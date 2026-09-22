<?php

declare(strict_types=1);

namespace Atelier\Modules\Wiki;

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
 * Module « Pages » : pages de type wiki rédigées en BBCode (éditeur commun) et syntaxe wiki
 * ([[liens internes]], [file=…] images et fichiers joints, [point=…] lieux GPS), reliées entre
 * elles (rétroliens), aux tags partagés, aux points GPS et aux pièces jointes ; historique des versions.
 *
 * Les pages supprimées (corbeille du module) sont exposées à la corbeille globale (TrashProviderInterface).
 */
final class WikiModule extends AbstractModule implements TrashProviderInterface
{
    private const PER_PAGE_CHOICES = [25, 50, 100];
    private const TITLE_MAX = 200;
    private const CONTENT_MAX = 200000;
    private const TAGS_MAX = 20;
    private const POINTS_MAX = 20;

    private ?WikiRepository $repository = null;

    public function boot(ModuleContext $context): void
    {
        parent::boot($context);
        $this->repository = null;
    }

    public function routes(RouteCollection $r): void
    {
        $r->view('index', [$this, 'index'], permission: 'open');
        $r->view('list', [$this, 'list'], permission: 'open');
        $r->view('settings', [$this, 'settingsView'], permission: 'admin');
        $r->action('save-settings', [$this, 'saveSettings'], permission: 'admin');
        $r->action('set-home', [$this, 'setHome'], permission: 'admin');
        $r->view('new', [$this, 'new'], permission: 'create');
        $r->view('show/{slug}', [$this, 'show'], permission: 'open');
        $r->view('edit/{id}', [$this, 'edit'], permission: 'update');
        $r->view('history/{id}', [$this, 'history'], permission: 'open');
        $r->view('revision/{id}/{rev}', [$this, 'revision'], permission: 'open');
        $r->view('trash', [$this, 'trash'], permission: 'delete');

        $r->action('filter', [$this, 'filter'], permission: 'open');
        $r->action('save', [$this, 'save'], permission: 'open'); // create ou update vérifié dans le gestionnaire
        $r->action('delete', [$this, 'delete'], permission: 'delete');
        $r->action('restore', [$this, 'restore'], permission: 'delete');
        $r->action('purge', [$this, 'destroy'], permission: 'delete');
        $r->action('restore-revision', [$this, 'restoreRevision'], permission: 'update');
        $r->action('lookup', [$this, 'lookup'], permission: 'open', methods: ['GET', 'POST']);
    }

    public function service(): ?object
    {
        return new WikiService($this->ctx);
    }

    /** Données de démonstration : page d'accueil et page d'aide sur la syntaxe. */
    public function seed(): string
    {
        $repository = $this->repository();
        if ($repository->countAll() > 0) {
            return 'pages déjà présentes';
        }
        $userId = $this->ctx->auth->userId();
        $aide = "[h2]Syntaxe des pages[/h2]\nLes pages utilisent le BBCode commun ([b]gras[/b], [i]italique[/i], listes, titres, citations, code, liens) complété par :\n[list]\n[*][b][[Titre d’une page]][/b] : lien vers une autre page (en rouge si elle n’existe pas encore, un clic la crée) ; [b][[cible|libellé]][/b] pour changer le texte.\n[*][b][file=identifiant][/b] : image affichée ou fichier joint à télécharger ; l’éditeur propose les fichiers joints à la page avec un bouton d’insertion.\n[*][b][point=numéro][/b] : lieu du module Coordonnées GPS (fiche et carte).\n[/list]\nChaque enregistrement crée une version : l’historique permet de comparer et de restaurer.";
        $accueil = "[h2]Bienvenue[/h2]\nCet espace de pages fonctionne comme un wiki : chaque page peut pointer vers d’autres pages, porter des tags, être rattachée à des lieux et recevoir des fichiers.\n\nPour commencer : lisez la page [[Aide sur la syntaxe]] puis créez votre première page.";
        $id = $repository->create('aide-sur-la-syntaxe', 'Aide sur la syntaxe', $aide, WikiRenderer::linkTargets($aide), $userId);
        $this->registerInfo($id, 'Aide sur la syntaxe');
        $id = $repository->create('accueil', 'Accueil', $accueil, WikiRenderer::linkTargets($accueil), $userId);
        $this->registerInfo($id, 'Accueil');
        return '2 pages d’exemple créées';
    }

    public function purge(): string
    {
        $days = $this->retentionDays();
        $count = 0;
        foreach ($this->repository()->expiredTrashIds($days) as $id) {
            $this->ctx->shared->registry->unregister(WikiService::DATASET, (string) $id);
            $this->repository()->deleteById($id);
            $count++;
        }
        return $count . ' page(s) purgée(s) de la corbeille (> ' . $days . ' jours)';
    }

    // =====================================================================
    // Vues
    // =====================================================================

    /**
     * Page d'arrivée du module (route par défaut) : la page d'accueil configurée ou la liste,
     * selon le paramètre « landing » du module (Paramètres des pages).
     */
    public function index(Request $request, array $params): ModuleView
    {
        $home = $this->homePage();
        if ($this->landing() === 'home' && $home !== null) {
            return $this->show($request, ['slug' => (string) $home['slug']])->route('show/' . $home['slug']);
        }
        return $this->list($request, $params);
    }

    public function list(Request $request, array $params): ModuleView
    {
        $query = $this->listQuery($request->allQuery());
        $result = $this->repository()->paginate($query['q'], $query['page'], $query['per_page'], $query['sort'], $query['dir'], $query['tag'] !== '' ? $query['tag'] : null);
        $rows = $result['rows'];
        foreach ($rows as &$row) {
            $row['excerpt'] = mb_substr(trim(preg_replace('/\s+/u', ' ', BbCode::toText((string) $row['excerpt'])) ?? ''), 0, 200, 'UTF-8');
            $row['tags'] = $this->tagNames((int) $row['id']);
        }
        unset($row);
        $rights = $this->rights(['create', 'update', 'delete', 'admin']);
        $content = $this->render('list', [
            'rows' => $rows,
            'total' => (int) $result['total'],
            'query' => $query,
            'rights' => $rights,
            'perPageChoices' => self::PER_PAGE_CHOICES,
            'home' => $this->homePage(),
            'categories' => $this->repository()->tagCounts(),
            'listRoute' => fn (array $overrides): string => $this->listRoute(array_merge($query, $overrides)),
        ]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="' . $this->e($this->listRoute($query)) . '">' . $this->icon('refresh') . '<span>Actualiser</span></a>';
        if ($rights['admin']) {
            $actions .= '<a class="btn btn--ghost" href="#" data-route="settings" title="Page d’arrivée et page d’accueil">' . $this->icon('settings') . '<span>Paramètres</span></a>';
        }
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="new">' . $this->icon('plus') . '<span>Nouvelle page</span></a>';
        }
        $filtered = $query['q'] !== '' || $query['tag'] !== '';
        $subtitle = $result['total'] . ' page(s)' . ($filtered ? ' (filtrées' . ($query['tag'] !== '' ? ', catégorie « ' . $query['tag'] . ' »' : '') . ')' : '');
        $banner = $this->renderCore('banner', ['icon' => 'book', 'title' => 'Pages', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Pages')->banner($banner)->content($content)->status($subtitle)->route($this->listRoute($query));
    }

    /** Paramètres du module : page d'arrivée (liste ou page d'accueil) et choix de la page d'accueil. */
    public function settingsView(Request $request, array $params): ModuleView
    {
        $home = $this->homePage();
        $content = $this->render('settings', [
            'landing' => $this->landing(),
            'homeSlug' => $home !== null ? (string) $home['slug'] : (string) ($this->ctx->settings->get('home_slug', '', 'wiki') ?? ''),
            'homeMissing' => $home === null && (string) ($this->ctx->settings->get('home_slug', '', 'wiki') ?? '') !== '',
            'pages' => $this->repository()->allActive(),
        ]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Toutes les pages</span></a>';
        $banner = $this->renderCore('banner', ['icon' => 'settings', 'title' => 'Paramètres des pages', 'subtitle' => 'Page d’arrivée et page d’accueil', 'actions' => $actions]);
        return ModuleView::make('Pages · paramètres')->banner($banner)->content($content)->status('Paramètres des pages');
    }

    public function saveSettings(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $landing = $request->string('landing');
        $homeSlug = trim($request->string('home_slug'));
        $errors = [];
        if (!in_array($landing, ['list', 'home'], true)) {
            $errors['landing'] = 'Choisissez « la liste des pages » ou « la page d’accueil ».';
        }
        if ($homeSlug !== '' && $this->repository()->findBySlug($homeSlug) === null) {
            $errors['home_slug'] = 'Cette page n’existe pas (ou est dans la corbeille).';
        }
        if ($landing === 'home' && $homeSlug === '') {
            $errors['home_slug'] = 'Choisissez la page d’accueil.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $userId = $this->ctx->userId();
        $this->ctx->settings->set('landing', $landing, 'wiki', $userId);
        $this->ctx->settings->set('home_slug', $homeSlug, 'wiki', $userId);
        $this->log('wiki.settings', 'success', 'wiki:settings', 'Paramètres des pages modifiés', ['landing' => $landing, 'home_slug' => $homeSlug]);
        return ActionResult::ok(['landing' => $landing, 'home_slug' => $homeSlug], 'Paramètres enregistrés.')->dirty(false)->refresh();
    }

    /** Depuis une page : la définir comme page d'accueil (et arriver dessus en ouvrant le module). */
    public function setHome(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $page = $this->requirePage($this->requireId($request));
        $userId = $this->ctx->userId();
        $this->ctx->settings->set('home_slug', (string) $page['slug'], 'wiki', $userId);
        $this->ctx->settings->set('landing', 'home', 'wiki', $userId);
        $this->log('wiki.settings', 'success', 'page:' . $page['id'], 'Page d’accueil définie : ' . $page['title']);
        return ActionResult::ok(['home_slug' => $page['slug']], '« ' . $page['title'] . ' » est désormais la page d’accueil des Pages.')->refresh();
    }

    private function landing(): string
    {
        $value = (string) ($this->ctx->settings->get('landing', 'home', 'wiki') ?? 'home');
        return $value === 'list' ? 'list' : 'home';
    }

    /** Page d'accueil configurée (paramètre home_slug, sinon la page « accueil » si elle existe). @return array<string, mixed>|null */
    private function homePage(): ?array
    {
        $slug = (string) ($this->ctx->settings->get('home_slug', '', 'wiki') ?? '');
        $page = $slug !== '' ? $this->repository()->findBySlug($slug) : null;
        return $page ?? $this->repository()->findBySlug('accueil');
    }

    public function new(Request $request, array $params): ModuleView
    {
        $title = trim((string) $request->query('title', ''));
        $page = ['id' => null, 'slug' => '', 'title' => mb_substr($title, 0, self::TITLE_MAX, 'UTF-8'), 'content' => '', 'revision' => 0];
        return $this->editorView($page, [], [], [], true);
    }

    public function show(Request $request, array $params): ModuleView
    {
        $page = $this->requirePageBySlug((string) ($params['slug'] ?? ''));
        $id = (int) $page['id'];
        $info = $this->ctx->shared->registry->find(WikiService::DATASET, (string) $id);
        $infoId = $info !== null ? (string) $info['id'] : null;
        $rights = $this->rights(['update', 'delete', 'create', 'admin']);
        $home = $this->homePage();
        $content = $this->render('show', [
            'page' => $page,
            'isHome' => $home !== null && (int) $home['id'] === $id,
            'html' => $this->renderer()->toHtml($page['content']),
            'tags' => $this->tagNames($id),
            'points' => $this->linkedPoints($infoId),
            'backlinks' => $this->repository()->backlinks((string) $page['slug']),
            'missing' => $this->repository()->missingLinks($id),
            'attachments' => $infoId !== null ? $this->ctx->shared->attachments->listFor($infoId) : [],
            'relations' => $infoId !== null ? array_values(array_filter($this->ctx->shared->relations->relationsOf($infoId), static fn (array $r): bool => $r['other_dataset'] !== 'geo.point')) : [],
            'rights' => $rights,
            'infoId' => $infoId,
            'attachmentsModule' => $this->ctx->modules()->has('attachments'),
            'mapModule' => $this->ctx->modules()->has('map'),
        ]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Pages</span></a>';
        $actions .= '<a class="btn btn--ghost" href="#" data-route="history/' . $id . '" title="Historique des versions">' . $this->icon('clock') . '<span>v' . (int) $page['revision'] . '</span></a>';
        if ($rights['admin'] && !($home !== null && (int) $home['id'] === $id)) {
            $actions .= '<button type="button" class="btn btn--ghost" data-action="set-home" data-params=\'{"id":' . $id . '}\' title="Arriver sur cette page en ouvrant le module">' . $this->icon('home') . '<span>Définir comme accueil</span></button>';
        }
        if ($rights['update']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="edit/' . $id . '">' . $this->icon('edit') . '<span>Modifier</span></a>';
        }
        if ($rights['delete']) {
            $actions .= '<button type="button" class="btn btn--outline-danger" data-action="delete" data-params=\'{"id":' . $id . '}\' data-confirm="Mettre cette page à la corbeille ?" data-danger>' . $this->icon('trash') . '<span>Supprimer</span></button>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'book', 'title' => (string) $page['title'], 'subtitle' => 'Modifiée le ' . Clock::formatDateTime($page['updated_at']) . ($page['updated_by_name'] !== null ? ' par ' . $page['updated_by_name'] : ''), 'actions' => $actions]);
        return ModuleView::make('Page · ' . $page['title'])->banner($banner)->content($content)->status('Page « ' . $page['title'] . ' » · version ' . (int) $page['revision']);
    }

    public function edit(Request $request, array $params): ModuleView
    {
        $page = $this->requirePage((int) ($params['id'] ?? 0));
        $info = $this->ctx->shared->registry->find(WikiService::DATASET, (string) $page['id']);
        $infoId = $info !== null ? (string) $info['id'] : null;
        return $this->editorView($page, $this->tagNames((int) $page['id']), $this->linkedPoints($infoId), $infoId !== null ? $this->ctx->shared->attachments->listFor($infoId) : [], false, $infoId);
    }

    public function history(Request $request, array $params): ModuleView
    {
        $page = $this->requirePage((int) ($params['id'] ?? 0));
        $content = $this->render('history', ['page' => $page, 'revisions' => $this->repository()->revisions((int) $page['id']), 'canUpdate' => $this->can('update'), 'kept' => WikiRepository::REVISIONS_KEPT]);
        $banner = $this->renderCore('banner', ['icon' => 'clock', 'title' => 'Historique · ' . $page['title'], 'subtitle' => 'Version courante : ' . (int) $page['revision'], 'actions' => '<a class="btn btn--ghost" href="#" data-route="show/' . $this->e($page['slug']) . '">' . $this->icon('chevron-left') . '<span>Page</span></a>']);
        return ModuleView::make('Historique · ' . $page['title'])->banner($banner)->content($content)->status('Historique de « ' . $page['title'] . ' »');
    }

    public function revision(Request $request, array $params): ModuleView
    {
        $page = $this->requirePage((int) ($params['id'] ?? 0));
        $revision = $this->repository()->revision((int) $page['id'], (int) ($params['rev'] ?? 0));
        if ($revision === null) {
            throw new NotFoundException('Version introuvable.');
        }
        $content = $this->render('revision', ['page' => $page, 'revision' => $revision, 'html' => $this->renderer()->toHtml($revision['content']), 'canUpdate' => $this->can('update')]);
        $banner = $this->renderCore('banner', ['icon' => 'clock', 'title' => $revision['title'], 'subtitle' => 'Version ' . (int) $revision['revision'] . ' du ' . Clock::formatDateTime($revision['saved_at']) . ($revision['saved_by_name'] !== null ? ' par ' . $revision['saved_by_name'] : ''), 'actions' => '<a class="btn btn--ghost" href="#" data-route="history/' . (int) $page['id'] . '">' . $this->icon('chevron-left') . '<span>Historique</span></a>']);
        return ModuleView::make('Version ' . (int) $revision['revision'] . ' · ' . $page['title'])->banner($banner)->content($content)->status('Version ' . (int) $revision['revision']);
    }

    public function trash(Request $request, array $params): ModuleView
    {
        $days = $this->retentionDays();
        $rows = $this->repository()->trashed($days);
        $content = $this->render('trash', ['rows' => $rows, 'retentionDays' => $days]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Pages</span></a>';
        if ($this->ctx->modules()->has('trash')) {
            $actions .= '<a class="btn btn--ghost" href="#" data-open-module="trash" title="Corbeille globale : tous les modules et les pièces jointes">' . $this->icon('trash') . '<span>Voir toute la corbeille</span></a>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'trash', 'title' => 'Corbeille des pages', 'subtitle' => count($rows) . ' page(s) · purge automatique après ' . $days . ' jours', 'actions' => $actions]);
        return ModuleView::make('Corbeille · pages')->banner($banner)->content($content)->status(count($rows) . ' page(s) en corbeille');
    }

    // =====================================================================
    // Actions
    // =====================================================================

    public function filter(Request $request, array $params): ActionResult
    {
        $query = $this->listQuery($request->all());
        $query['page'] = 1;
        return ActionResult::ok()->navigate($this->listRoute($query));
    }

    public function save(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update', null, 'Vous n’avez pas le droit de ' . ($isNew ? 'créer' : 'modifier') . ' des pages.');
        $existing = $isNew ? null : $this->requirePage($id);
        $data = $this->validate($request, $existing);
        $userId = $this->ctx->userId();
        $links = WikiRenderer::linkTargets($data['content']);

        if ($isNew) {
            $id = $this->repository()->create($data['slug'], $data['title'], $data['content'], $links, $userId);
            $revision = 1;
        } else {
            $revision = $this->repository()->update($id, $data['slug'], $data['title'], $data['content'], $links, $userId);
        }
        $infoId = $this->registerInfo($id, $data['title']);
        $this->ctx->shared->tags->replace($infoId, $data['tags'], TagService::SHARED, $userId);
        $this->syncPoints($infoId, $data['points']);
        $this->log($isNew ? 'wiki.create' : 'wiki.update', 'success', 'wiki_page:' . $id, ($isNew ? 'Page créée : ' : 'Page modifiée : ') . $data['title'], ['revision' => $revision, 'links' => count($links), 'tags' => count($data['tags']), 'points' => count($data['points'])]);
        return ActionResult::ok(['id' => $id, 'slug' => $data['slug'], 'revision' => $revision], 'Page « ' . $data['title'] . ' » enregistrée (version ' . $revision . ').')->navigate('show/' . $data['slug']);
    }

    public function delete(Request $request, array $params): ActionResult
    {
        $page = $this->requirePage($this->requireId($request));
        $this->repository()->softDelete((int) $page['id']);
        $this->log('wiki.delete', 'success', 'wiki_page:' . $page['id'], 'Page mise à la corbeille : ' . $page['title']);
        return ActionResult::ok(null, 'Page « ' . $page['title'] . ' » mise à la corbeille.')->navigate('list');
    }

    public function restore(Request $request, array $params): ActionResult
    {
        $page = $this->restoreTrashed($this->requireId($request), 'Page restaurée');
        return ActionResult::ok(null, 'Page « ' . $page['title'] . ' » restaurée.')->refresh();
    }

    /** Suppression définitive d'une page de la corbeille (action « purge »). */
    public function destroy(Request $request, array $params): ActionResult
    {
        $page = $this->purgeTrashed($this->requireId($request), 'Page supprimée définitivement');
        return ActionResult::ok(null, 'Page « ' . $page['title'] . ' » supprimée définitivement.')->refresh();
    }

    public function restoreRevision(Request $request, array $params): ActionResult
    {
        $page = $this->requirePage($this->requireId($request));
        $number = $this->requireId($request, 'revision');
        $revision = $this->repository()->revision((int) $page['id'], $number);
        if ($revision === null) {
            throw new NotFoundException('Version introuvable.');
        }
        $content = (string) ($revision['content'] ?? '');
        $new = $this->repository()->update((int) $page['id'], (string) $page['slug'], (string) $revision['title'], $content, WikiRenderer::linkTargets($content), $this->ctx->userId());
        $this->registerInfo((int) $page['id'], (string) $revision['title']);
        $this->log('wiki.restore_revision', 'success', 'wiki_page:' . $page['id'], 'Version ' . $number . ' restaurée comme version ' . $new . ' : ' . $revision['title']);
        return ActionResult::ok(['revision' => $new], 'Version ' . $number . ' restaurée (nouvelle version ' . $new . ').')->navigate('show/' . $page['slug']);
    }

    /** Recherche de pages pour les liens (GET lookup?q=…) : { items: [{ id, slug, title }] }. */
    public function lookup(Request $request, array $params): array
    {
        $term = $request->string('q');
        $items = [];
        foreach ($this->repository()->search($term, max(1, min(30, $request->int('limit', 10) ?? 10))) as $row) {
            $items[] = ['id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'title' => (string) $row['title']];
        }
        return ['items' => $items];
    }

    // =====================================================================
    // Corbeille globale (TrashProviderInterface) : les pages sont communes, la règle du module
    // s'applique (restaurer comme purger exigent « delete », comme la vue « trash »)
    // =====================================================================

    public function trashItems(): array
    {
        $retention = $this->retentionDays();
        $canDelete = $this->can('delete');
        $items = [];
        foreach ($this->repository()->trashed($retention) as $page) {
            $deletedAt = (string) $page['deleted_at'];
            $purgeAt = Clock::parseUtc($deletedAt)?->modify('+' . $retention . ' days');
            $items[] = [
                'id' => (string) $page['id'],
                'label' => (string) $page['title'],
                'dataset' => WikiService::DATASET,
                'deleted_at' => $deletedAt,
                'deleted_by' => null, // la table ne conserve pas l'auteur de la suppression
                'purge_at' => $purgeAt === null ? null : Clock::utc($purgeAt),
                'can_restore' => $canDelete,
                'can_purge' => $canDelete,
            ];
        }
        return $items;
    }

    public function restoreTrashItem(string $id): void
    {
        $this->require('delete');
        $this->restoreTrashed((int) $id, 'Page restaurée depuis la corbeille globale');
    }

    public function purgeTrashItem(string $id): void
    {
        $this->require('delete');
        $this->purgeTrashed((int) $id, 'Page supprimée définitivement depuis la corbeille globale');
    }

    // =====================================================================
    // Helpers publics (gabarits)
    // =====================================================================

    public function icon(string $name, string $extra = ''): string
    {
        return '<svg class="icon' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    // =====================================================================
    // Interne
    // =====================================================================

    /** Restaure une page en corbeille (action « restore » et corbeille globale). @return array<string, mixed> la page */
    private function restoreTrashed(int $id, string $message): array
    {
        $page = $this->requireTrashed($id);
        if (!$this->repository()->restore($id)) {
            throw new NotFoundException('Cette page n’est pas dans la corbeille.');
        }
        $this->log('wiki.restore', 'success', 'wiki_page:' . $id, $message . ' : ' . $page['title']);
        return $page;
    }

    /** Supprime définitivement une page en corbeille et la retire du registre commun. @return array<string, mixed> la page */
    private function purgeTrashed(int $id, string $message): array
    {
        $page = $this->requireTrashed($id);
        $this->ctx->db->transaction(function () use ($id): void {
            $this->ctx->shared->registry->unregister(WikiService::DATASET, (string) $id);
            $this->repository()->purge($id);
        });
        $this->log('wiki.purge', 'success', 'wiki_page:' . $id, $message . ' : ' . $page['title']);
        return $page;
    }

    /** @return array<string, mixed> page en corbeille, NotFoundException sinon */
    private function requireTrashed(int $id): array
    {
        $page = $id > 0 ? $this->repository()->find($id, true) : null;
        if ($page === null || $page['deleted_at'] === null) {
            throw new NotFoundException('Cette page n’est pas dans la corbeille.');
        }
        return $page;
    }

    private function retentionDays(): int
    {
        return max(1, $this->ctx->config->int('trash.retention_days', 30));
    }

    /**
     * @param array<string, mixed> $page @param list<string> $tags @param list<array<string, mixed>> $points @param list<array<string, mixed>> $attachments
     */
    private function editorView(array $page, array $tags, array $points, array $attachments, bool $isNew, ?string $infoId = null): ModuleView
    {
        $content = $this->render('edit', [
            'page' => $page,
            'isNew' => $isNew,
            'tags' => $tags,
            'points' => $points,
            'attachments' => $attachments,
            'infoId' => $infoId,
            'titleMax' => self::TITLE_MAX,
            'contentMax' => self::CONTENT_MAX,
            'geoModule' => $this->ctx->modules()->has('geo'),
            'attachmentsModule' => $this->ctx->modules()->has('attachments'),
            'canDelete' => !$isNew && $this->can('delete'),
        ]);
        $banner = $this->renderCore('banner', [
            'icon' => 'book',
            'title' => $isNew ? 'Nouvelle page' : (string) $page['title'],
            'subtitle' => $isNew ? 'Rédaction' : 'Modification · version ' . (int) $page['revision'],
            'actions' => '<a class="btn btn--ghost" href="#" data-route="' . ($isNew ? 'list' : 'show/' . $this->e($page['slug'])) . '">' . $this->icon('chevron-left') . '<span>' . ($isNew ? 'Pages' : 'Page') . '</span></a>',
        ]);
        return ModuleView::make($isNew ? 'Nouvelle page' : 'Modifier · ' . $page['title'])->banner($banner)->content($content)->status($isNew ? 'Création d’une page' : 'Modification de « ' . $page['title'] . ' »');
    }

    /** @param array<string, mixed>|null $existing @return array{title: string, slug: string, content: string, tags: list<string>, points: list<int>} */
    private function validate(Request $request, ?array $existing): array
    {
        $errors = [];
        $title = trim(preg_replace('/\s+/u', ' ', $request->string('title')) ?? '');
        if ($title === '') {
            $errors['title'] = 'Le titre est obligatoire.';
        } elseif (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            $errors['title'] = sprintf('Le titre ne peut dépasser %d caractères.', self::TITLE_MAX);
        } elseif (preg_match('/[\[\]|<>]/u', $title) === 1) {
            $errors['title'] = 'Le titre ne peut contenir ni crochets, ni barre verticale, ni chevrons.';
        }
        $content = str_replace(["\r\n", "\r"], "\n", (string) ($request->input('content') ?? ''));
        if (mb_strlen($content, 'UTF-8') > self::CONTENT_MAX) {
            $errors['content'] = sprintf('Le contenu ne peut dépasser %s caractères.', number_format(self::CONTENT_MAX, 0, ',', ' '));
        }
        $tags = [];
        try {
            $tags = $this->parseTags($request->input('tags', ''));
        } catch (ValidationException $e) {
            $errors['tags'] = implode(' ', $e->fieldErrors());
        }
        $points = [];
        foreach ($request->arrayInput('points') as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $points[] = (int) $value;
            }
        }
        $points = array_values(array_unique($points));
        if (count($points) > self::POINTS_MAX) {
            $errors['points'] = 'Au maximum ' . self::POINTS_MAX . ' lieux par page.';
        }
        if (!isset($errors['title'])) {
            $slug = $existing !== null ? (string) $existing['slug'] : '';
            if ($existing === null || mb_strtolower((string) $existing['title'], 'UTF-8') !== mb_strtolower($title, 'UTF-8')) {
                $sameTitle = $this->repository()->findByTitle($title);
                if ($sameTitle !== null && ($existing === null || (int) $sameTitle['id'] !== (int) $existing['id'])) {
                    $errors['title'] = 'Une page porte déjà ce titre.';
                } elseif ($existing === null) {
                    $slug = $this->repository()->uniqueSlug($title);
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['title' => $title, 'slug' => $slug, 'content' => $content, 'tags' => $tags, 'points' => $points];
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
            throw ValidationException::single('tags', 'Au maximum ' . self::TAGS_MAX . ' tags par page.');
        }
        return $tags;
    }

    /** Aligne les relations « located_at » de la page sur la liste de points. @param list<int> $pointIds */
    private function syncPoints(string $infoId, array $pointIds): void
    {
        try {
            $geo = $this->ctx->moduleService('geo');
            $current = [];
            foreach ($geo->pointsOf($infoId) as $point) {
                $current[(int) $point['id']] = true;
            }
            foreach ($pointIds as $id) {
                if (!isset($current[$id])) {
                    try {
                        $geo->attach($infoId, $id);
                    } catch (NotFoundException) {
                        // point disparu : ignoré
                    }
                }
                unset($current[$id]);
            }
            foreach (array_keys($current) as $id) {
                $geo->detach($infoId, $id);
            }
        } catch (\Atelier\Error\ModuleUnavailableException | \Atelier\Error\ForbiddenException) {
            // module geo absent ou jeu non lisible : les lieux ne sont pas gérés
        }
    }

    /** @return list<array<string, mixed>> */
    private function linkedPoints(?string $infoId): array
    {
        if ($infoId === null) {
            return [];
        }
        try {
            return $this->ctx->moduleService('geo')->pointsOf($infoId);
        } catch (\Atelier\Error\ModuleUnavailableException | \Atelier\Error\ForbiddenException) {
            return [];
        }
    }

    /** @return list<string> */
    private function tagNames(int $pageId): array
    {
        $info = $this->ctx->shared->registry->find(WikiService::DATASET, (string) $pageId);
        if ($info === null) {
            return [];
        }
        return array_map(static fn (array $t): string => (string) $t['name'], $this->ctx->shared->tags->tagsOf((string) $info['id'], TagService::SHARED));
    }

    private function registerInfo(int $id, string $title): string
    {
        return $this->ctx->shared->registry->register(WikiService::DATASET, (string) $id, $title, $this->ctx->auth->userId());
    }

    /** @return array<string, mixed> */
    private function requirePage(int $id): array
    {
        $page = $id > 0 ? $this->repository()->find($id) : null;
        if ($page === null) {
            throw new NotFoundException('Page introuvable.');
        }
        return $page;
    }

    /** @return array<string, mixed> */
    private function requirePageBySlug(string $slug): array
    {
        $page = $slug !== '' ? $this->repository()->findBySlug($slug) : null;
        if ($page === null) {
            throw new NotFoundException('Page introuvable : ' . $slug);
        }
        return $page;
    }

    private function requireId(Request $request, string $key = 'id'): int
    {
        $id = $request->int($key);
        if ($id === null || $id <= 0) {
            throw ValidationException::single($key, 'Identifiant manquant.');
        }
        return $id;
    }

    /** @param array<string, mixed> $input @return array{q: string, tag: string, sort: string, dir: string, page: int, per_page: int} */
    private function listQuery(array $input): array
    {
        $default = (int) $this->ctx->settings->preference($this->ctx->userId(), 'pageSize', 25);
        $perPage = (int) ($input['per_page'] ?? $default);
        $sort = (string) ($input['sort'] ?? 'title');
        return [
            'q' => is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '',
            'tag' => is_scalar($input['tag'] ?? null) ? \Atelier\Support\Str::normalizeTag((string) $input['tag']) : '',
            'sort' => WikiRepository::isSortable($sort) ? $sort : 'title',
            'dir' => strtolower((string) ($input['dir'] ?? ($sort === 'title' ? 'asc' : 'desc'))) === 'desc' ? 'desc' : 'asc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PER_PAGE_CHOICES, true) ? $perPage : 25,
        ];
    }

    /** @param array{q: string, tag?: string, sort: string, dir: string, page: int, per_page: int} $query */
    private function listRoute(array $query): string
    {
        $params = array_filter([
            'q' => $query['q'],
            'tag' => $query['tag'] ?? '',
            'sort' => $query['sort'] !== 'title' ? $query['sort'] : null,
            'dir' => $query['dir'] !== 'asc' ? $query['dir'] : null,
            'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null,
            'page' => $query['page'] > 1 ? $query['page'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return $params === [] ? 'list' : 'list?' . http_build_query($params);
    }

    private function repository(): WikiRepository
    {
        return $this->repository ??= new WikiRepository($this->ctx->db);
    }

    private function renderer(): WikiRenderer
    {
        return new WikiRenderer($this->ctx, $this->repository());
    }
}
