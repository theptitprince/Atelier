<?php

declare(strict_types=1);

namespace Atelier\Modules\News;

use Atelier\Error\ConflictException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleContext;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Modules\TrashProviderInterface;
use Atelier\Shared\TagService;
use Atelier\Support\Clock;
use Atelier\View\BbCode;

/**
 * Module « Actualités » : veille sur des flux RSS / Atom / JSON Feed choisis, classés par catégorie
 * et par centre d'intérêt (mots-clés), lecture par utilisateur, archivage à la demande des faits
 * à conserver (note, tags, inscription au registre commun pour les relier aux autres modules).
 * Les flux retirés et les faits archivés supprimés passent par la corbeille (suppression logique,
 * restaurable pendant trash.retention_days) ; les entrées non archivées suivent la rétention du flux.
 */
final class NewsModule extends AbstractModule implements TrashProviderInterface
{
    public const DATASET_FEED = 'news.feed';

    /** Types d'éléments de la corbeille : préfixe d'identifiant => jeu de données. */
    private const TRASH_KINDS = ['feed' => self::DATASET_FEED, 'archive' => NewsService::DATASET];

    private const PER_PAGE_CHOICES = [20, 50, 100];
    private const AUTO_REFRESH_MAX_FEEDS = 3;
    private const AUTO_REFRESH_BUDGET = 8.0;
    private const CRON_MAX_FEEDS = 50;
    private const CRON_FEED_BUDGET = 120.0;
    private const CRON_MAX_ARTICLES = 40;
    private const CRON_ARTICLE_BUDGET = 120.0;
    private const CRON_STALE_HOURS = 24;
    private const NOTE_MAX = 20000;
    private const TAGS_MAX = 20;
    private const REFRESH_MIN = 5;
    private const REFRESH_MAX = 10080; // 7 jours

    /**
     * Flux publics suggérés pour démarrer ou tester : [titre, adresse, catégorie]. Les adresses sont celles
     * publiées par les éditeurs au moment de la livraison ; un flux disparu apparaît simplement en erreur.
     */
    public const SUGGESTED_FEEDS = [
        ['Le Monde — À la une', 'https://www.lemonde.fr/rss/une.xml', 'Actualité générale'],
        ['France Info', 'https://www.francetvinfo.fr/titres.rss', 'Actualité générale'],
        ['Le Figaro — Actualités', 'https://www.lefigaro.fr/rss/figaro_actualites.xml', 'Actualité générale'],
        ['20 Minutes', 'https://www.20minutes.fr/feeds/rss-une.xml', 'Actualité générale'],
        ['Ouest-France — En continu', 'https://www.ouest-france.fr/rss-en-continu.xml', 'Actualité générale'],
        ['Courrier international', 'https://www.courrierinternational.com/feed/all/rss.xml', 'International'],
        ['BBC News', 'https://feeds.bbci.co.uk/news/rss.xml', 'International'],
        ['Le Monde — Sciences', 'https://www.lemonde.fr/sciences/rss_full.xml', 'Sciences'],
        ['Futura Sciences', 'https://www.futura-sciences.com/rss/actualites.xml', 'Sciences'],
        ['CNRS — Le journal', 'https://lejournal.cnrs.fr/rss', 'Sciences'],
        ['ESA — Space News', 'https://www.esa.int/rssfeed/Our_Activities/Space_News', 'Sciences'],
        ['Le Monde — Planète', 'https://www.lemonde.fr/planete/rss_full.xml', 'Environnement'],
        ['Next', 'https://next.ink/feed/', 'Technologie'],
        ['Numerama', 'https://www.numerama.com/feed/', 'Technologie'],
        ['ZDNet France', 'https://www.zdnet.fr/feeds/rss/actualites/', 'Technologie'],
        ['Korben', 'https://korben.info/feed', 'Technologie'],
        ['Hacker News — Une', 'https://hnrss.org/frontpage', 'Technologie'],
        ['The Verge', 'https://www.theverge.com/rss/index.xml', 'Technologie'],
        ['CERT-FR — Alertes et avis', 'https://www.cert.ssi.gouv.fr/feed/', 'Sécurité'],
        ['Service-public.fr — Actualités', 'https://www.service-public.fr/particuliers/actualites/rss', 'Administration'],
    ];

    /** Fréquences proposées (minutes => libellé). */
    public const REFRESH_CHOICES = [15 => '15 minutes', 30 => '30 minutes', 60 => '1 heure', 120 => '2 heures', 240 => '4 heures', 360 => '6 heures', 720 => '12 heures', 1440 => '24 heures', 4320 => '3 jours', 10080 => '7 jours'];

    /** Cache par requête du dépôt et du synchroniseur. */
    private ?NewsRepository $repository = null;
    private ?NewsSync $sync = null;

    /** Transport HTTP de remplacement (tests). @var (callable(string, array<string, string>, int): array{status: int, headers: array<string, string>, body: string})|null */
    public static $transport = null;

    public function boot(ModuleContext $context): void
    {
        parent::boot($context);
        $this->repository = null;
        $this->sync = null;
    }

    public function routes(RouteCollection $r): void
    {
        $r->view('list', [$this, 'list'], permission: 'open');
        $r->view('archives', [$this, 'archives'], permission: 'open');
        $r->view('archive/{id}', [$this, 'archiveShow'], permission: 'open');
        $r->view('read/{id}', [$this, 'read'], permission: 'open');
        $r->view('feeds', [$this, 'feeds'], permission: 'update');
        $r->view('feeds/new', [$this, 'feedNew'], permission: 'create');
        $r->view('feeds/edit/{id}', [$this, 'feedEdit'], permission: 'update');
        $r->view('taxonomy', [$this, 'taxonomy'], permission: 'update');
        $r->view('trash', [$this, 'trash'], permission: 'delete');

        $r->action('filter', [$this, 'filter'], permission: 'open');
        $r->action('archive-delete', [$this, 'archiveDelete'], permission: 'archive');
        $r->action('trash-restore', [$this, 'trashRestore'], permission: 'open'); // droit précis revérifié selon le type
        $r->action('trash-purge', [$this, 'trashPurge'], permission: 'delete');
        $r->action('read', [$this, 'markRead'], permission: 'open');
        $r->action('content-fetch', [$this, 'contentFetch'], permission: 'open');
        $r->action('read-all', [$this, 'readAll'], permission: 'open');
        $r->action('refresh', [$this, 'refresh'], permission: 'open');
        $r->action('archive', [$this, 'archive'], permission: 'archive');
        $r->action('archive-save', [$this, 'archiveSave'], permission: 'archive');
        $r->action('unarchive', [$this, 'unarchive'], permission: 'archive');
        $r->action('feed-save', [$this, 'feedSave'], permission: 'open'); // create ou update vérifié dans le gestionnaire
        $r->action('feed-suggest', [$this, 'feedSuggest'], permission: 'create');
        $r->action('feed-delete', [$this, 'feedDelete'], permission: 'delete');
        $r->action('feed-toggle', [$this, 'feedToggle'], permission: 'update');
        $r->action('feed-refresh', [$this, 'feedRefresh'], permission: 'update');
        $r->action('category-save', [$this, 'categorySave'], permission: 'update');
        $r->action('category-delete', [$this, 'categoryDelete'], permission: 'delete');
        $r->action('interest-save', [$this, 'interestSave'], permission: 'update');
        $r->action('interest-delete', [$this, 'interestDelete'], permission: 'delete');
        $r->action('badge', [$this, 'badge'], permission: 'open', methods: ['GET', 'POST']);
    }

    public function service(): ?object
    {
        return new NewsService($this->ctx);
    }

    /** Données de démonstration : catégories, centres d'intérêt et quelques flux publics (non récupérés ici). */
    public function seed(): string
    {
        $repository = $this->repository();
        if ($repository->categories() !== [] || $repository->feeds() !== []) {
            return 'flux déjà présents';
        }
        $general = $repository->saveCategory(null, 'Actualité générale', '#2c3e50', 10);
        $tech = $repository->saveCategory(null, 'Technologie', '#2f6fdb', 20);
        $sciences = $repository->saveCategory(null, 'Sciences', '#1f8a4c', 30);
        $repository->saveInterest(null, 'Intelligence artificielle', 'intelligence artificielle, IA, AI, LLM, ChatGPT, OpenAI, Mistral', '#b7791f', 10);
        $repository->saveInterest(null, 'Espace', 'spatial, espace, NASA, ESA, fusée, satellite, Ariane, SpaceX', '#2b6cb0', 20);
        $repository->saveInterest(null, 'Climat', 'climat, climatique, réchauffement, canicule, GIEC, émissions', '#1f8a4c', 30);
        $userId = $this->ctx->auth->userId();
        $feeds = [
            ['Le Monde — À la une', 'https://www.lemonde.fr/rss/une.xml', $general],
            ['France Info', 'https://www.francetvinfo.fr/titres.rss', $general],
            ['Next', 'https://next.ink/feed/', $tech],
            ['Le Monde — Sciences', 'https://www.lemonde.fr/sciences/rss_full.xml', $sciences],
        ];
        foreach ($feeds as [$title, $url, $category]) {
            $repository->createFeed(['title' => $title, 'url' => $url, 'category_id' => $category, 'refresh_minutes' => 60, 'retention_days' => 30, 'is_active' => 1], $userId);
        }
        return count($feeds) . ' flux, 3 catégories et 3 centres d’intérêt d’exemple créés (récupération à la première ouverture)';
    }

    /**
     * Rétention : supprime les entrées non archivées plus anciennes que la rétention de leur flux, puis
     * purge physiquement les flux et faits archivés en corbeille depuis plus de trash.retention_days.
     * Un flux en corbeille qui porte encore des faits archivés n'est jamais purgé automatiquement.
     */
    public function purge(): string
    {
        $expired = $this->repository()->purgeExpired();
        $days = $this->retentionDays();
        $feeds = 0;
        $kept = 0;
        foreach ($this->repository()->expiredTrashFeedIds($days) as $id) {
            if ($this->repository()->archivedCountForFeed($id) > 0) {
                $kept++;
                continue;
            }
            $this->repository()->purgeFeed($id);
            $this->log('news.purge', 'success', 'news_feed:' . $id, 'Flux purgé par la rétention de la corbeille (' . $days . ' jours)');
            $feeds++;
        }
        $archives = 0;
        foreach ($this->repository()->expiredTrashItemIds($days) as $id) {
            $this->destroyArchive($id);
            $this->log('news.purge', 'success', 'news_item:' . $id, 'Fait archivé purgé par la rétention de la corbeille (' . $days . ' jours)');
            $archives++;
        }
        return $expired . ' entrée(s) d’actualité purgée(s) (archives conservées) ; corbeille : ' . $feeds . ' flux et ' . $archives . ' fait(s) archivé(s) purgés (> ' . $days . ' jours)' . ($kept > 0 ? ', ' . $kept . ' flux conservé(s) car porteur(s) de faits archivés' : '');
    }

    /**
     * Tâche de fond (console cron:run) : récupère les flux dont la fréquence est échue puis télécharge
     * la copie locale des articles en attente, dans des limites de nombre et de durée.
     */
    public function cron(): string
    {
        $sync = $this->sync();
        $results = $sync->refreshStale(self::CRON_MAX_FEEDS, self::CRON_FEED_BUDGET, 10);
        $new = array_sum(array_map(static fn (array $r): int => $r['new'], $results));
        $errors = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'error'));
        $content = $sync->fetchPendingContent(self::CRON_MAX_ARTICLES, self::CRON_ARTICLE_BUDGET, 10);
        $summary = sprintf('%d flux vérifié(s), %d nouvelle(s) entrée(s), %d erreur(s) ; %d article(s) copié(s) en local, %d échec(s)', count($results), $new, $errors, $content['done'], $content['errors']);
        $this->log('news.cron', $errors === 0 && $content['errors'] === 0 ? 'success' : 'failure', 'news_feed', $summary, ['feeds' => count($results), 'new' => $new, 'errors' => $errors] + $content);
        return $summary;
    }

    // =====================================================================
    // Vues
    // =====================================================================

    public function list(Request $request, array $params): ModuleView
    {
        $query = $this->listQuery($request->allQuery());
        $userId = $this->ctx->userId();
        $repository = $this->repository();
        $refreshed = [];
        if ($request->query('norefresh') === null) {
            $refreshed = $this->sync()->refreshStale(self::AUTO_REFRESH_MAX_FEEDS, self::AUTO_REFRESH_BUDGET);
        }
        $filters = $this->filtersFromQuery($query);
        $result = $repository->paginate($filters, $userId, $query['page'], $query['per_page']);
        $rights = $this->rights(['archive', 'update']);
        $categories = $repository->categories();
        $feeds = $repository->feeds(true);
        $content = $this->render('list', [
            'rows' => $result['rows'],
            'total' => (int) $result['total'],
            'query' => $query,
            'categories' => $categories,
            'interests' => $repository->interests(),
            'feeds' => $feeds,
            'counts' => $repository->countsByCategory(),
            'unread' => $repository->unreadCount($userId),
            'rights' => $rights,
            'perPageChoices' => self::PER_PAGE_CHOICES,
            'route' => $this->listRoute($query),
            'errors' => array_values(array_filter($refreshed, static fn (array $r): bool => $r['status'] === 'error')),
            'hasFeeds' => $feeds !== [],
        ]);
        $unread = $repository->unreadCount($userId);
        $subtitle = sprintf('%d entrée(s)%s · %d non lue(s)', $result['total'], $this->isFiltered($query) ? ' (filtrées)' : '', $unread);
        $actions = '<button type="button" class="btn btn--ghost" data-action="refresh" title="Récupérer les flux périmés maintenant">' . $this->icon('refresh') . '<span>Actualiser</span></button>';
        $actions .= '<button type="button" class="btn" data-action="read-all" data-params=\'' . $this->e(json_encode($this->filtersFromQuery($query), JSON_UNESCAPED_UNICODE)) . '\' title="Marquer comme lues les entrées affichées">' . $this->icon('check') . '<span>Tout marquer lu</span></button>';
        if ($rights['update']) {
            $actions .= '<a class="btn" href="#" data-route="feeds">' . $this->icon('globe') . '<span>Flux</span></a>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'rss', 'title' => 'Fil d’actualité', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Actualités')->banner($banner)->content($content)->status($subtitle)->route($this->listRoute($query));
    }

    public function archives(Request $request, array $params): ModuleView
    {
        $query = $this->listQuery($request->allQuery());
        $filters = $this->filtersFromQuery($query) + ['archived' => true];
        $result = $this->repository()->paginate($filters, $this->ctx->userId(), $query['page'], $query['per_page']);
        $rows = $result['rows'];
        foreach ($rows as &$row) {
            $info = $this->ctx->shared->registry->find(NewsService::DATASET, (string) $row['id']);
            $row['tags'] = $info === null ? [] : $this->ctx->shared->tags->tagsOf((string) $info['id']);
            $row['note_excerpt'] = $row['archive_note'] !== null ? mb_substr(BbCode::toText((string) $row['archive_note']), 0, 240, 'UTF-8') : '';
            $row['archiver'] = $row['archived_by'] !== null ? $this->ctx->users->find((int) $row['archived_by']) : null;
        }
        unset($row);
        $content = $this->render('archives', [
            'rows' => $rows,
            'total' => (int) $result['total'],
            'query' => $query,
            'categories' => $this->repository()->categories(),
            'interests' => $this->repository()->interests(),
            'rights' => $this->rights(['archive']),
            'perPageChoices' => self::PER_PAGE_CHOICES,
        ]);
        $subtitle = $result['total'] . ' fait(s) archivé(s)' . ($this->isFiltered($query) ? ' (filtrés)' : '');
        $banner = $this->renderCore('banner', ['icon' => 'archive', 'title' => 'Archives', 'subtitle' => $subtitle, 'actions' => '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('rss') . '<span>Fil d’actualité</span></a>']);
        return ModuleView::make('Actualités · archives')->banner($banner)->content($content)->status($subtitle)->route($this->listRoute($query, 'archives'));
    }

    public function archiveShow(Request $request, array $params): ModuleView
    {
        $item = $this->requireArchived((int) ($params['id'] ?? 0));
        $info = $this->ctx->shared->registry->find(NewsService::DATASET, (string) $item['id']);
        $infoId = $info !== null ? (string) $info['id'] : null;
        $tags = $infoId !== null ? array_map(static fn (array $t): string => (string) $t['name'], $this->ctx->shared->tags->tagsOf($infoId)) : [];
        $content = $this->render('archive', [
            'item' => $item,
            'noteHtml' => BbCode::toHtml($item['archive_note']),
            'articleHtml' => $this->articleHtml($item['content'] ?? null),
            'tags' => $tags,
            'relations' => $infoId !== null ? $this->ctx->shared->relations->relationsOf($infoId) : [],
            'attachments' => $infoId !== null ? $this->ctx->shared->attachments->listFor($infoId) : [],
            'archiver' => $item['archived_by'] !== null ? $this->ctx->users->find((int) $item['archived_by']) : null,
            'canArchive' => $this->can('archive'),
            'attachmentsModule' => $this->ctx->modules()->has('attachments'),
            'noteMax' => self::NOTE_MAX,
            'infoId' => $infoId,
        ]);
        $banner = $this->renderCore('banner', [
            'icon' => 'archive',
            'title' => (string) $item['title'],
            'subtitle' => (string) $item['feed_title'] . ' · archivé le ' . \Atelier\Support\Clock::formatDateTime($item['archived_at']),
            'actions' => '<a class="btn btn--ghost" href="#" data-route="archives">' . $this->icon('chevron-left') . '<span>Archives</span></a>'
                . ($item['url'] !== null ? '<a class="btn" href="' . $this->e($item['url']) . '" target="_blank" rel="noopener noreferrer">' . $this->icon('external') . '<span>Source</span></a>' : ''),
        ]);
        return ModuleView::make('Archive · ' . $item['title'])->banner($banner)->content($content)->status('Fait archivé n° ' . $item['id']);
    }

    /** Lecture de la copie locale d'une entrée (marquée lue). */
    public function read(Request $request, array $params): ModuleView
    {
        $item = $this->requireItem((int) ($params['id'] ?? 0));
        $this->repository()->markRead($this->ctx->userId(), (int) $item['id'], true);
        $content = $this->render('read', [
            'item' => $item,
            'articleHtml' => $this->articleHtml($item['content'] ?? null),
            'canArchive' => $this->can('archive') && (int) $item['is_archived'] !== 1,
        ]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="' . ((int) $item['is_archived'] === 1 ? 'archive/' . $item['id'] : 'list') . '">' . $this->icon('chevron-left') . '<span>' . ((int) $item['is_archived'] === 1 ? 'Archive' : 'Fil d’actualité') . '</span></a>';
        if ($item['url'] !== null) {
            $actions .= '<a class="btn" href="' . $this->e($item['url']) . '" target="_blank" rel="noopener noreferrer">' . $this->icon('external') . '<span>Source</span></a>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'rss', 'title' => (string) $item['title'], 'subtitle' => (string) $item['feed_title'] . ($item['published_at'] !== null ? ' · ' . Clock::formatDateTime($item['published_at']) : ''), 'actions' => $actions]);
        return ModuleView::make('Lecture · ' . $item['title'])->banner($banner)->content($content)->status('Copie locale' . ($item['content_status'] === 'ok' ? ' du ' . Clock::formatDateTime($item['content_fetched_at']) : ' indisponible'));
    }

    public function feeds(Request $request, array $params): ModuleView
    {
        $rows = $this->repository()->feeds();
        $content = $this->render('feeds', [
            'rows' => $rows,
            'rights' => $this->rights(['create', 'update', 'delete']),
            'cron' => $this->cronStatus(),
            'localCopies' => $this->repository()->localCopyCounts(),
            'refreshChoices' => self::REFRESH_CHOICES,
        ]);
        $errors = count(array_filter($rows, static fn (array $f): bool => $f['last_status'] === 'error'));
        $subtitle = count($rows) . ' flux' . ($errors > 0 ? ' · ' . $errors . ' en erreur' : '');
        $actions = '<button type="button" class="btn" data-action="feed-refresh" data-params=\'{"all":1}\' title="Récupérer tous les flux actifs">' . $this->icon('refresh') . '<span>Tout actualiser</span></button>';
        if ($this->can('create')) {
            $actions .= '<button type="button" class="btn" data-action="feed-suggest" data-confirm="Ajouter les flux suggérés (' . count(self::SUGGESTED_FEEDS) . ' sources publiques : presse générale, international, sciences, technologie, sécurité, administration) ? Ceux déjà suivis sont ignorés." data-confirm-title="Flux suggérés" data-confirm-label="Ajouter" title="Sources publiques pour démarrer ou tester">' . $this->icon('rss') . '<span>Flux suggérés</span></button>';
            $actions .= '<a class="btn btn--primary" href="#" data-route="feeds/new">' . $this->icon('plus') . '<span>Ajouter un flux</span></a>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'globe', 'title' => 'Flux suivis', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Actualités · flux')->banner($banner)->content($content)->status($subtitle);
    }

    public function feedNew(Request $request, array $params): ModuleView
    {
        $feed = ['id' => null, 'title' => '', 'url' => '', 'category_id' => null, 'refresh_minutes' => 120, 'retention_days' => 30, 'is_active' => 1, 'fetch_content' => 1, 'description' => '', 'site_url' => null];
        $content = $this->render('feed_form', ['feed' => $feed, 'isNew' => true, 'categories' => $this->repository()->categories(), 'refreshChoices' => self::REFRESH_CHOICES]);
        $banner = $this->renderCore('banner', ['icon' => 'globe', 'title' => 'Nouveau flux', 'subtitle' => 'RSS, Atom ou JSON Feed', 'actions' => '<a class="btn btn--ghost" href="#" data-route="feeds">' . $this->icon('chevron-left') . '<span>Flux suivis</span></a>']);
        return ModuleView::make('Nouveau flux')->banner($banner)->content($content)->status('Ajout d’un flux');
    }

    public function feedEdit(Request $request, array $params): ModuleView
    {
        $feed = $this->requireFeed((int) ($params['id'] ?? 0));
        $content = $this->render('feed_form', ['feed' => $feed, 'isNew' => false, 'categories' => $this->repository()->categories(), 'refreshChoices' => self::REFRESH_CHOICES]);
        $banner = $this->renderCore('banner', ['icon' => 'globe', 'title' => (string) $feed['title'], 'subtitle' => (string) $feed['url'], 'actions' => '<a class="btn btn--ghost" href="#" data-route="feeds">' . $this->icon('chevron-left') . '<span>Flux suivis</span></a>']);
        return ModuleView::make('Flux · ' . $feed['title'])->banner($banner)->content($content)->status('Modification du flux');
    }

    public function taxonomy(Request $request, array $params): ModuleView
    {
        $content = $this->render('taxonomy', [
            'categories' => $this->repository()->categories(),
            'interests' => $this->repository()->interests(),
            'rights' => $this->rights(['update', 'delete']),
        ]);
        $banner = $this->renderCore('banner', ['icon' => 'tag', 'title' => 'Catégories et centres d’intérêt', 'subtitle' => 'Classement des flux et repérage par mots-clés', 'actions' => '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('rss') . '<span>Fil d’actualité</span></a>']);
        return ModuleView::make('Actualités · classement')->banner($banner)->content($content)->status('Catégories et centres d’intérêt');
    }

    /** Corbeille du module : flux et faits archivés supprimés depuis moins de N jours. */
    public function trash(Request $request, array $params): ModuleView
    {
        $retention = $this->retentionDays();
        $rows = $this->trashRows($retention);
        $content = $this->render('trash', ['rows' => $rows, 'retention' => $retention, 'rights' => $this->rights(['update', 'archive', 'delete'])]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="feeds">' . $this->icon('globe') . '<span>Flux suivis</span></a><a class="btn btn--ghost" href="#" data-route="archives">' . $this->icon('archive') . '<span>Archives</span></a>';
        if ($this->ctx->modules()->has('trash')) {
            $actions .= '<a class="btn btn--ghost" href="#" data-open-module="trash" title="Corbeille globale : tous les modules et les pièces jointes">' . $this->icon('trash') . '<span>Voir toute la corbeille</span></a>';
        }
        $subtitle = count($rows) . ' élément' . (count($rows) > 1 ? 's' : '') . ' · purge automatique après ' . $retention . ' jours';
        $banner = $this->renderCore('banner', ['icon' => 'trash', 'title' => 'Corbeille des actualités', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Actualités · corbeille')->banner($banner)->content($content)->status($subtitle);
    }

    // =====================================================================
    // Actions : lecture, archivage
    // =====================================================================

    public function filter(Request $request, array $params): ActionResult
    {
        $query = $this->listQuery($request->all());
        $query['page'] = 1;
        $base = $request->string('view') === 'archives' ? 'archives' : 'list';
        return ActionResult::ok()->navigate($this->listRoute($query, $base));
    }

    public function markRead(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($this->requireId($request));
        $read = $request->bool('read', true);
        $this->repository()->markRead($this->ctx->userId(), (int) $item['id'], $read);
        return ActionResult::ok(['id' => (int) $item['id'], 'read' => $read, 'unread' => $this->repository()->unreadCount($this->ctx->userId())]);
    }

    /** Téléchargement immédiat de la copie locale d'une entrée. */
    public function contentFetch(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($this->requireId($request));
        $result = $this->sync()->fetchContent($item, 10);
        $this->log('news.content_fetch', $result['status'] === 'ok' ? 'success' : 'failure', 'news_item:' . $item['id'], 'Copie locale : ' . $result['message']);
        return $result['status'] === 'ok'
            ? ActionResult::ok($result, 'Copie locale enregistrée : ' . $result['message'])->refresh()
            : ActionResult::warning($result, 'Copie locale impossible : ' . $result['message'])->refresh();
    }

    public function readAll(Request $request, array $params): ActionResult
    {
        $filters = ['q' => $request->string('q'), 'category_id' => $request->int('category_id'), 'interest_id' => $request->int('interest_id'), 'feed_id' => $request->int('feed_id')];
        $count = $this->repository()->markAllRead($this->ctx->userId(), $filters);
        return ActionResult::ok(['count' => $count], $count . ' entrée(s) marquée(s) comme lue(s).')->refresh();
    }

    /** Récupération immédiate des flux périmés (bouton Actualiser du fil). */
    public function refresh(Request $request, array $params): ActionResult
    {
        $results = $this->sync()->refreshStale(10, 20.0, 8);
        return $this->refreshResult($results);
    }

    public function archive(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($this->requireId($request));
        $note = $this->validateNote($request->string('note'));
        $userId = $this->ctx->userId();
        $this->repository()->archive((int) $item['id'], $userId, $note);
        $this->repository()->markRead($userId, (int) $item['id'], true);
        if ($item['content_status'] !== 'ok' && $item['url'] !== null) {
            // Un fait archivé doit rester lisible même si la source disparaît : copie locale immédiate.
            $this->sync()->fetchContent($item, 10);
        }
        $this->ctx->shared->registry->register(NewsService::DATASET, (string) $item['id'], (string) $item['title'], $userId);
        $this->log('news.archive', 'success', 'news_item:' . $item['id'], 'Actualité archivée : ' . $item['title'], ['feed_id' => (int) $item['feed_id']]);
        return ActionResult::ok(['id' => (int) $item['id']], '« ' . mb_substr((string) $item['title'], 0, 80, 'UTF-8') . ' » archivée.')->navigate('archive/' . $item['id']);
    }

    public function archiveSave(Request $request, array $params): ActionResult
    {
        $item = $this->requireArchived($this->requireId($request));
        $note = $this->validateNote($request->string('note'));
        $tags = $this->parseTags($request->input('tags', ''));
        $userId = $this->ctx->userId();
        $this->repository()->updateArchiveNote((int) $item['id'], $note);
        $infoId = $this->ctx->shared->registry->register(NewsService::DATASET, (string) $item['id'], (string) $item['title'], $userId);
        $this->ctx->shared->tags->replace($infoId, $tags, TagService::SHARED, $userId);
        $this->log('news.archive_update', 'success', 'news_item:' . $item['id'], 'Archive annotée : ' . $item['title'], ['tags' => count($tags)]);
        return ActionResult::ok(['id' => (int) $item['id']], 'Archive enregistrée.')->refresh();
    }

    public function unarchive(Request $request, array $params): ActionResult
    {
        $item = $this->requireArchived($this->requireId($request));
        $this->ctx->shared->registry->unregister(NewsService::DATASET, (string) $item['id']);
        $this->repository()->unarchive((int) $item['id']);
        $this->log('news.unarchive', 'success', 'news_item:' . $item['id'], 'Archive retirée : ' . $item['title']);
        return ActionResult::ok(null, 'Fait retiré des archives ; il suivra la rétention de son flux.')->navigate('archives');
    }

    /** Mise en corbeille d'un fait archivé : note, tags, relations et pièces jointes conservés jusqu'à la purge. */
    public function archiveDelete(Request $request, array $params): ActionResult
    {
        $item = $this->requireArchived($this->requireId($request));
        $this->repository()->softDeleteItem((int) $item['id'], $this->ctx->userId());
        $this->log('news.archive_delete', 'success', 'news_item:' . $item['id'], 'Fait archivé placé dans la corbeille : ' . $item['title']);
        return ActionResult::ok(['id' => (int) $item['id']], '« ' . mb_substr((string) $item['title'], 0, 80, 'UTF-8') . ' » placé dans la corbeille.')->navigate('archives');
    }

    /** Restaure un élément depuis la vue corbeille du module : { id: "feed:3" | "archive:9" }. */
    public function trashRestore(Request $request, array $params): ActionResult
    {
        $id = $request->string('id');
        $this->restoreTrashItem($id);
        return ActionResult::ok(['id' => $id], '« ' . $this->trashLabel($id) . ' » restauré.')->refresh();
    }

    /** Supprime définitivement un élément depuis la vue corbeille du module. */
    public function trashPurge(Request $request, array $params): ActionResult
    {
        $id = $request->string('id');
        $label = $this->trashLabel($id);
        $this->purgeTrashItem($id);
        return ActionResult::ok(['id' => $id], '« ' . $label . ' » supprimé définitivement.')->refresh();
    }

    /** Indicateur de la colonne : entrées non lues. */
    public function badge(Request $request, array $params): array
    {
        $count = $this->repository()->unreadCount($this->ctx->userId());
        return ['count' => $count, 'label' => $count . ' actualité(s) non lue(s)'];
    }

    // =====================================================================
    // Actions : flux, catégories, centres d'intérêt
    // =====================================================================

    public function feedSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update', null, 'Vous n’avez pas le droit de ' . ($isNew ? 'créer' : 'modifier') . ' des flux.');
        $data = $this->validateFeed($request, $isNew ? null : $id);
        $probe = null;
        $probeError = null;
        try {
            $probe = $this->sync()->probe($data['url'], 10);
        } catch (\Throwable $e) {
            $probeError = mb_substr($e->getMessage(), 0, 300, 'UTF-8');
        }
        if ($data['title'] === '' && $probe !== null && $probe['title'] !== '') {
            $data['title'] = mb_substr($probe['title'], 0, 250, 'UTF-8');
        }
        if ($data['title'] === '') {
            if ($probeError !== null && $isNew) {
                throw ValidationException::single('url', 'Flux injoignable ou illisible : ' . $probeError . ' Indiquez un titre pour l’enregistrer malgré tout.');
            }
            $data['title'] = (string) parse_url($data['url'], PHP_URL_HOST);
        }
        if ($probe !== null) {
            $data['site_url'] = $data['site_url'] ?? $probe['site_url'];
            $data['description'] = $data['description'] ?? $probe['description'];
        }
        if ($isNew) {
            $id = $this->repository()->createFeed($data, $this->ctx->userId());
            $this->log('news.feed_create', 'success', 'news_feed:' . $id, 'Flux ajouté : ' . $data['title'], ['url' => $data['url']]);
        } else {
            $this->repository()->updateFeed($id, $data);
            $this->log('news.feed_update', 'success', 'news_feed:' . $id, 'Flux modifié : ' . $data['title'], ['url' => $data['url']]);
        }
        $feed = $this->repository()->findFeed($id);
        $message = 'Flux « ' . $data['title'] . ' » enregistré.';
        if ($probe !== null && $feed !== null) {
            $result = $this->sync()->refresh($feed, 10, false);
            $message .= ' ' . $result['message'];
        } elseif ($probeError !== null) {
            $this->repository()->updateFeedStatus($id, ['last_status' => 'error', 'last_error' => $probeError, 'last_fetched_at' => \Atelier\Support\Clock::utc()]);
            return ActionResult::warning(['id' => $id], $message . ' Récupération impossible pour l’instant : ' . $probeError)->navigate('feeds');
        }
        return ActionResult::ok(['id' => $id], $message)->navigate('feeds');
    }

    /** Ajoute les flux suggérés absents (et leurs catégories) ; la récupération suit au prochain cron ou à l'ouverture du fil. */
    public function feedSuggest(Request $request, array $params): ActionResult
    {
        $repository = $this->repository();
        $categories = [];
        foreach ($repository->categories() as $category) {
            $categories[NewsRepository::slugify((string) $category['name'])] = (int) $category['id'];
        }
        $userId = $this->ctx->userId();
        $added = [];
        $position = 100;
        foreach (self::SUGGESTED_FEEDS as [$title, $url, $categoryName]) {
            if ($repository->feedUrlExists($url)) {
                continue;
            }
            $slug = NewsRepository::slugify($categoryName);
            if (!isset($categories[$slug])) {
                $categories[$slug] = $repository->saveCategory(null, $categoryName, null, $position += 10);
            }
            $repository->createFeed(['title' => $title, 'url' => $url, 'category_id' => $categories[$slug], 'refresh_minutes' => 120, 'retention_days' => 30, 'is_active' => 1, 'fetch_content' => 1], $userId);
            $added[] = $title;
        }
        $this->log('news.feed_suggest', 'success', 'news_feed', count($added) . ' flux suggéré(s) ajouté(s)', ['added' => $added]);
        if ($added === []) {
            return ActionResult::info(['added' => 0], 'Tous les flux suggérés sont déjà suivis.');
        }
        return ActionResult::ok(['added' => count($added)], count($added) . ' flux ajouté(s) : ' . implode(', ', array_slice($added, 0, 5)) . (count($added) > 5 ? '…' : '') . '. Récupération au prochain passage de la tâche de fond ou via « Tout actualiser ».')->refresh();
    }

    /** Mise en corbeille d'un flux : ses entrées non archivées sont masquées ; ses faits archivés restent consultables. */
    public function feedDelete(Request $request, array $params): ActionResult
    {
        $feed = $this->requireFeed($this->requireId($request));
        $this->repository()->softDeleteFeed((int) $feed['id'], $this->ctx->userId());
        $this->log('news.feed_delete', 'success', 'news_feed:' . $feed['id'], 'Flux placé dans la corbeille : ' . $feed['title'], ['url' => $feed['url']]);
        return ActionResult::ok(null, 'Flux « ' . $feed['title'] . ' » placé dans la corbeille ; ses faits archivés restent consultables.')->refresh();
    }

    public function feedToggle(Request $request, array $params): ActionResult
    {
        $feed = $this->requireFeed($this->requireId($request));
        $active = (int) $feed['is_active'] !== 1;
        $this->repository()->setFeedActive((int) $feed['id'], $active);
        $this->log('news.feed_toggle', 'success', 'news_feed:' . $feed['id'], ($active ? 'Flux activé : ' : 'Flux suspendu : ') . $feed['title']);
        return ActionResult::ok(['active' => $active], 'Flux « ' . $feed['title'] . ' » ' . ($active ? 'activé' : 'suspendu') . '.')->refresh();
    }

    public function feedRefresh(Request $request, array $params): ActionResult
    {
        if ($request->bool('all')) {
            return $this->refreshResult($this->sync()->refreshAll(8));
        }
        $feed = $this->requireFeed($this->requireId($request));
        return $this->refreshResult([$this->sync()->refresh($feed, 10)]);
    }

    public function categorySave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $name = $request->string('name');
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw ValidationException::single('name', 'Le nom est obligatoire (100 caractères au plus).');
        }
        $color = $this->validateColor($request->string('color'));
        try {
            $saved = $this->repository()->saveCategory($id !== null && $id > 0 ? $id : null, $name, $color, $request->int('position', 100) ?? 100);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::single('name', $e->getMessage());
        }
        $this->log('news.category_save', 'success', 'news_category:' . $saved, 'Catégorie enregistrée : ' . $name);
        return ActionResult::ok(['id' => $saved], 'Catégorie « ' . $name . ' » enregistrée.')->refresh();
    }

    public function categoryDelete(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $category = $this->repository()->findCategory($id);
        if ($category === null || !$this->repository()->deleteCategory($id)) {
            throw new NotFoundException('Catégorie introuvable.');
        }
        $this->log('news.category_delete', 'success', 'news_category:' . $id, 'Catégorie supprimée : ' . $category['name']);
        return ActionResult::ok(null, 'Catégorie supprimée ; ses flux sont désormais sans catégorie.')->refresh();
    }

    public function interestSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $name = $request->string('name');
        $keywords = implode(', ', InterestMatcher::keywords($request->string('keywords')));
        $errors = [];
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            $errors['name'] = 'Le nom est obligatoire (100 caractères au plus).';
        }
        if ($keywords === '' || mb_strlen($keywords, 'UTF-8') > 2000) {
            $errors['keywords'] = 'Indiquez au moins un mot-clé (2 000 caractères au plus, séparés par des virgules).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $color = $this->validateColor($request->string('color'));
        $saved = $this->repository()->saveInterest($id !== null && $id > 0 ? $id : null, $name, $keywords, $color, $request->int('position', 100) ?? 100);
        $matched = $this->repository()->rematchInterest(['id' => $saved, 'keywords' => $keywords]);
        $this->log('news.interest_save', 'success', 'news_interest:' . $saved, 'Centre d’intérêt enregistré : ' . $name, ['matched' => $matched]);
        return ActionResult::ok(['id' => $saved, 'matched' => $matched], 'Centre d’intérêt « ' . $name . ' » enregistré : ' . $matched . ' entrée(s) correspondante(s).')->refresh();
    }

    public function interestDelete(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $interest = $this->repository()->findInterest($id);
        if ($interest === null || !$this->repository()->deleteInterest($id)) {
            throw new NotFoundException('Centre d’intérêt introuvable.');
        }
        $this->log('news.interest_delete', 'success', 'news_interest:' . $id, 'Centre d’intérêt supprimé : ' . $interest['name']);
        return ActionResult::ok(null, 'Centre d’intérêt supprimé.')->refresh();
    }

    // =====================================================================
    // Corbeille globale (TrashProviderInterface)
    // =====================================================================

    /**
     * Éléments en corbeille visibles par l'utilisateur courant. Flux : restauration = update ;
     * faits archivés : restauration = archive ; suppression définitive = delete dans les deux cas.
     */
    public function trashItems(): array
    {
        $retention = $this->retentionDays();
        $canUpdate = $this->can('update');
        $canArchive = $this->can('archive');
        $canPurge = $this->can('delete');
        $items = [];
        foreach ($this->trashRows($retention) as $row) {
            $purgeAt = $row['trash_kind'] === 'feed' && (int) $row['archived_count'] > 0 ? null : Clock::parseUtc($row['deleted_at'])?->modify('+' . $retention . ' days');
            $items[] = [
                'id' => $row['trash_id'],
                'label' => $row['trash_label'],
                'dataset' => self::TRASH_KINDS[$row['trash_kind']],
                'deleted_at' => (string) $row['deleted_at'],
                'deleted_by' => $row['deleted_by'] === null ? null : (int) $row['deleted_by'],
                'purge_at' => $purgeAt === null ? null : Clock::utc($purgeAt),
                'can_restore' => $row['trash_kind'] === 'feed' ? $canUpdate : $canArchive,
                'can_purge' => $canPurge,
            ];
        }
        return $items;
    }

    public function restoreTrashItem(string $id): void
    {
        [$kind, $localId] = $this->parseTrashId($id);
        $this->require($kind === 'feed' ? 'update' : 'archive', null, 'Vous n’avez pas le droit de restaurer cet élément.');
        $row = $this->findTrashedRow($kind, $localId);
        if ($row === null) {
            throw new NotFoundException('Cet élément n’est pas dans la corbeille des actualités.');
        }
        if ($kind === 'feed') {
            $this->repository()->restoreFeed($localId);
        } else {
            $this->repository()->restoreItem($localId);
        }
        $this->log('news.restore', 'success', 'news_' . ($kind === 'feed' ? 'feed' : 'item') . ':' . $localId, 'Restauré depuis la corbeille : ' . $row['trash_label']);
    }

    public function purgeTrashItem(string $id): void
    {
        $this->require('delete', null, 'Vous n’avez pas le droit de supprimer définitivement cet élément.');
        [$kind, $localId] = $this->parseTrashId($id);
        $row = $this->findTrashedRow($kind, $localId);
        if ($row === null) {
            throw new NotFoundException('Cet élément n’est pas dans la corbeille des actualités.');
        }
        if ($kind === 'feed') {
            $archived = $this->repository()->archivedCountForFeed($localId);
            if ($archived > 0) {
                throw new ConflictException('Le flux « ' . $row['title'] . ' » porte ' . $archived . ' fait(s) archivé(s) : supprimez-les définitivement (ou restaurez le flux) avant de le purger.');
            }
            $this->repository()->purgeFeed($localId);
        } else {
            $this->destroyArchive($localId);
        }
        $this->log('news.purge', 'success', 'news_' . ($kind === 'feed' ? 'feed' : 'item') . ':' . $localId, 'Supprimé définitivement depuis la corbeille : ' . $row['trash_label']);
    }

    // =====================================================================
    // Helpers publics (gabarits)
    // =====================================================================

    public function icon(string $name, string $extra = ''): string
    {
        return '<svg class="icon' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    /**
     * Rendu HTML sûr d'une copie locale : paragraphes, titres (« ## »), puces (« • »), tout le reste échappé.
     */
    public function articleHtml(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $html = '';
        foreach (preg_split("/\n{2,}/", $text) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (str_starts_with($block, '## ')) {
                $html .= '<h3>' . $this->e(substr($block, 3)) . '</h3>';
            } elseif (str_starts_with($block, '### ')) {
                $html .= '<h4>' . $this->e(substr($block, 4)) . '</h4>';
            } elseif (str_starts_with($block, '• ')) {
                $html .= '<ul>';
                foreach (explode("\n", $block) as $line) {
                    $html .= '<li>' . $this->e(ltrim($line, "• \t")) . '</li>';
                }
                $html .= '</ul>';
            } else {
                $html .= '<p>' . nl2br($this->e($block)) . '</p>';
            }
        }
        return $html;
    }

    /**
     * État de la planification : date de la dernière exécution de cron:run et alerte si absente ou ancienne.
     *
     * @return array{last: ?string, stale: bool}
     */
    private function cronStatus(): array
    {
        $last = $this->ctx->settings->get('cron.last_run', null, 'core');
        $last = is_string($last) && $last !== '' ? $last : null;
        $parsed = $last !== null ? Clock::parseUtc($last) : null;
        $stale = $parsed === null || $parsed->modify('+' . self::CRON_STALE_HOURS . ' hours') < Clock::now();
        return ['last' => $last, 'stale' => $stale];
    }

    /** Style inline d'une pastille colorée (couleur validée en amont, #rrggbb). */
    public function colorStyle(?string $color): string
    {
        return $color !== null && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? ' style="--news-color: ' . $color . '"' : '';
    }

    // =====================================================================
    // Interne
    // =====================================================================

    private function retentionDays(): int
    {
        return max(1, $this->ctx->config->int('trash.retention_days', 30));
    }

    /**
     * Lignes de la corbeille (flux puis faits archivés), décorées de trash_kind / trash_id / trash_label /
     * expires_in_days, les plus récemment supprimées d'abord.
     *
     * @return list<array<string, mixed>>
     */
    private function trashRows(int $retention): array
    {
        $rows = [];
        foreach ($this->repository()->trashedFeeds($retention) as $row) {
            $rows[] = $this->decorateTrashRow('feed', $row);
        }
        foreach ($this->repository()->trashedItems($retention) as $row) {
            $rows[] = $this->decorateTrashRow('archive', $row);
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['deleted_at'], (string) $a['deleted_at']) ?: strcmp($a['trash_id'], $b['trash_id']));
        $now = Clock::now();
        foreach ($rows as &$row) {
            $deletedAt = Clock::parseUtc($row['deleted_at']);
            $row['expires_in_days'] = $deletedAt === null ? 0 : max(0, $retention - (int) $deletedAt->diff($now)->days);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decorateTrashRow(string $kind, array $row): array
    {
        $row['trash_kind'] = $kind;
        $row['trash_id'] = $kind . ':' . $row['id'];
        $row['archived_count'] = (int) ($row['archived_count'] ?? 0);
        $row['trash_label'] = $kind === 'feed'
            ? 'Flux « ' . $row['title'] . ' »'
            : 'Fait archivé « ' . mb_substr((string) $row['title'], 0, 120, 'UTF-8') . ' » — ' . $row['feed_title'];
        $row['trash_type'] = $kind === 'feed' ? 'Flux' : 'Fait archivé';
        return $row;
    }

    /** @return array{0: string, 1: int} type et identifiant local d'un identifiant « type:id » */
    private function parseTrashId(string $id): array
    {
        $parts = explode(':', trim($id), 2);
        $kind = $parts[0] ?? '';
        $localId = (int) ($parts[1] ?? 0);
        if (!isset(self::TRASH_KINDS[$kind]) || $localId <= 0) {
            throw new NotFoundException('Identifiant de corbeille invalide.');
        }
        return [$kind, $localId];
    }

    /** @return array<string, mixed>|null ligne en corbeille décorée, ou null */
    private function findTrashedRow(string $kind, int $localId): ?array
    {
        $row = $kind === 'feed' ? $this->repository()->findTrashedFeed($localId) : $this->repository()->findTrashedItem($localId);
        if ($row === null) {
            return null;
        }
        if ($kind === 'feed') {
            $row['archived_count'] = $this->repository()->archivedCountForFeed($localId);
        }
        return $this->decorateTrashRow($kind, $row);
    }

    /** Libellé d'un élément en corbeille (messages), ou l'identifiant brut s'il est introuvable. */
    private function trashLabel(string $id): string
    {
        try {
            [$kind, $localId] = $this->parseTrashId($id);
        } catch (NotFoundException) {
            return $id;
        }
        return $this->findTrashedRow($kind, $localId)['trash_label'] ?? $id;
    }

    /** Suppression physique d'un fait archivé en corbeille et retrait du registre commun (tags, relations). */
    private function destroyArchive(int $id): void
    {
        $this->ctx->db->transaction(function () use ($id): void {
            $this->repository()->purgeItem($id);
            $this->ctx->shared->registry->unregister(NewsService::DATASET, (string) $id);
        });
    }

    /** @param list<array{status: string, new: int, message: string, feed: array<string, mixed>}> $results */
    private function refreshResult(array $results): ActionResult
    {
        $new = array_sum(array_map(static fn (array $r): int => $r['new'], $results));
        $errors = array_filter($results, static fn (array $r): bool => $r['status'] === 'error');
        $this->log('news.refresh', $errors === [] ? 'success' : 'failure', 'news_feed', sprintf('%d flux récupéré(s), %d nouvelle(s) entrée(s), %d erreur(s)', count($results), $new, count($errors)));
        if ($results === []) {
            return ActionResult::info(['new' => 0], 'Tous les flux sont à jour.')->refresh();
        }
        $unchanged = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'unchanged'));
        $message = sprintf('%d flux vérifié(s), %d nouvelle(s) entrée(s)%s.', count($results), $new, $unchanged > 0 ? ', ' . $unchanged . ' inchangé(s)' : '');
        if ($errors !== []) {
            $first = reset($errors);
            return ActionResult::warning(['new' => $new, 'errors' => count($errors)], $message . ' ' . count($errors) . ' flux en erreur (' . $first['feed']['title'] . ' : ' . $first['message'] . ').')->refresh();
        }
        return ActionResult::ok(['new' => $new], $message)->refresh();
    }

    /** @return array<string, mixed> */
    private function validateFeed(Request $request, ?int $exceptId): array
    {
        $errors = [];
        $url = $request->string('url');
        try {
            FeedFetcher::assertSafeUrl($url);
        } catch (\InvalidArgumentException $e) {
            $errors['url'] = $e->getMessage();
        }
        if (!isset($errors['url']) && mb_strlen($url, 'UTF-8') > 500) {
            $errors['url'] = 'L’adresse ne peut dépasser 500 caractères.';
        }
        if (!isset($errors['url']) && $this->repository()->feedUrlExists($url, $exceptId)) {
            $errors['url'] = $this->repository()->findTrashedFeedByUrl($url) !== null ? 'Ce flux est dans la corbeille : restaurez-le plutôt que de le recréer.' : 'Ce flux est déjà suivi.';
        }
        $title = $request->string('title');
        if (mb_strlen($title, 'UTF-8') > 250) {
            $errors['title'] = 'Le titre ne peut dépasser 250 caractères.';
        }
        $refresh = $request->int('refresh_minutes', 120) ?? 120;
        if ($refresh < self::REFRESH_MIN || $refresh > self::REFRESH_MAX) {
            $errors['refresh_minutes'] = 'La fréquence est comprise entre 5 minutes et 7 jours.';
        }
        $retention = $request->int('retention_days', 30) ?? 30;
        if ($retention < 1 || $retention > 3650) {
            $errors['retention_days'] = 'La rétention est comprise entre 1 et 3 650 jours.';
        }
        $categoryId = $request->int('category_id');
        if ($categoryId !== null && $categoryId > 0 && $this->repository()->findCategory($categoryId) === null) {
            $errors['category_id'] = 'Catégorie inconnue.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $description = $request->string('description');
        return [
            'title' => $title,
            'url' => $url,
            'category_id' => $categoryId,
            'refresh_minutes' => $refresh,
            'retention_days' => $retention,
            'is_active' => $request->bool('is_active', true),
            'fetch_content' => $request->bool('fetch_content', true),
            'description' => $description !== '' ? mb_substr($description, 0, 1000, 'UTF-8') : null,
            'site_url' => null,
        ];
    }

    private function validateNote(string $note): ?string
    {
        $note = str_replace(["\r\n", "\r"], "\n", $note);
        if (mb_strlen($note, 'UTF-8') > self::NOTE_MAX) {
            throw ValidationException::single('note', sprintf('La note ne peut dépasser %d caractères.', self::NOTE_MAX));
        }
        return trim($note) !== '' ? $note : null;
    }

    private function validateColor(string $color): ?string
    {
        if ($color === '') {
            return null;
        }
        if (preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            throw ValidationException::single('color', 'La couleur doit être au format #rrggbb.');
        }
        return strtolower($color);
    }

    /** @return list<string> */
    private function parseTags(mixed $raw): array
    {
        $names = is_array($raw) ? $raw : explode(',', (string) $raw);
        $tags = [];
        $seen = [];
        foreach ($names as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
            $key = mb_strtolower($name, 'UTF-8');
            if ($name === '' || isset($seen[$key])) {
                continue;
            }
            if (mb_strlen($name, 'UTF-8') > 60) {
                throw ValidationException::single('tags', 'Chaque tag comporte au plus 60 caractères.');
            }
            $seen[$key] = true;
            $tags[] = $name;
        }
        if (count($tags) > self::TAGS_MAX) {
            throw ValidationException::single('tags', 'Au maximum ' . self::TAGS_MAX . ' tags.');
        }
        return $tags;
    }

    /** @return array<string, mixed> */
    private function requireItem(int $id): array
    {
        $item = $id > 0 ? $this->repository()->findItem($id, $this->ctx->userId()) : null;
        if ($item === null) {
            throw new NotFoundException('Actualité introuvable.');
        }
        return $item;
    }

    /** @return array<string, mixed> */
    private function requireArchived(int $id): array
    {
        $item = $this->requireItem($id);
        if ((int) $item['is_archived'] !== 1) {
            throw new NotFoundException('Cette actualité n’est pas archivée.');
        }
        return $item;
    }

    /** @return array<string, mixed> */
    private function requireFeed(int $id): array
    {
        $feed = $id > 0 ? $this->repository()->findFeed($id) : null;
        if ($feed === null) {
            throw new NotFoundException('Flux introuvable.');
        }
        return $feed;
    }

    private function requireId(Request $request, string $key = 'id'): int
    {
        $id = $request->int($key);
        if ($id === null || $id <= 0) {
            throw ValidationException::single($key, 'Identifiant manquant.');
        }
        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{q: string, category: int, interest: int, feed: int, unread: bool, page: int, per_page: int}
     */
    private function listQuery(array $input): array
    {
        $default = (int) $this->ctx->settings->preference($this->ctx->userId(), 'pageSize', 25);
        $perPage = (int) ($input['per_page'] ?? ($default === 25 ? 20 : $default));
        return [
            'q' => is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '',
            'category' => max(0, (int) ($input['category'] ?? 0)),
            'interest' => max(0, (int) ($input['interest'] ?? 0)),
            'feed' => max(0, (int) ($input['feed'] ?? 0)),
            'unread' => in_array((string) ($input['unread'] ?? ''), ['1', 'true', 'on'], true),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PER_PAGE_CHOICES, true) ? $perPage : 20,
        ];
    }

    /** @param array{q: string, category: int, interest: int, feed: int, unread: bool, page: int, per_page: int} $query @return array<string, mixed> */
    private function filtersFromQuery(array $query): array
    {
        return array_filter([
            'q' => $query['q'],
            'category_id' => $query['category'] ?: null,
            'interest_id' => $query['interest'] ?: null,
            'feed_id' => $query['feed'] ?: null,
            'unread' => $query['unread'] ?: null,
        ], static fn ($v): bool => $v !== null && $v !== '');
    }

    /** @param array{q: string, category: int, interest: int, feed: int, unread: bool, page: int, per_page: int} $query */
    private function isFiltered(array $query): bool
    {
        return $query['q'] !== '' || $query['category'] > 0 || $query['interest'] > 0 || $query['feed'] > 0 || $query['unread'];
    }

    /** @param array{q: string, category: int, interest: int, feed: int, unread: bool, page: int, per_page: int} $query */
    private function listRoute(array $query, string $base = 'list', array $overrides = []): string
    {
        $params = array_filter($overrides + [
            'q' => $query['q'],
            'category' => $query['category'] ?: null,
            'interest' => $query['interest'] ?: null,
            'feed' => $query['feed'] ?: null,
            'unread' => $query['unread'] ? 1 : null,
            'per_page' => $query['per_page'] !== 20 ? $query['per_page'] : null,
            'page' => $query['page'] > 1 ? $query['page'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return $params === [] ? $base : $base . '?' . http_build_query($params);
    }

    private function repository(): NewsRepository
    {
        return $this->repository ??= new NewsRepository($this->ctx->db);
    }

    private function sync(): NewsSync
    {
        // Certificats racines de secours pour un PHP sans curl.cainfo (PHP portable) : var/config/cacert.pem (bundle Mozilla via curl.se).
        return $this->sync ??= new NewsSync($this->repository(), new FeedFetcher(self::$transport, $this->ctx->config->path('var') . '/config/cacert.pem'), $this->ctx->logger);
    }
}
