<?php

declare(strict_types=1);

namespace Atelier\Modules\Demo;

use Atelier\Error\ConflictException;
use Atelier\Error\ForbiddenException;
use Atelier\Error\ModuleUnavailableException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Modules\TrashProviderInterface;
use Atelier\Shared\RelationService;
use Atelier\Shared\TagService;
use Atelier\Support\Clock;
use Atelier\Support\Str;

/**
 * Module de démonstration : référence technique et visuelle du noyau.
 *
 * Chaque vue exerce une famille de composants ou de comportements (composants CSS, formulaires,
 * tableaux, notifications, cycle de vie, données partagées, cas d'erreur). Le code est volontairement
 * explicite et commenté : il sert de modèle pour écrire un nouveau module.
 *
 * Suppression : comme toute donnée métier, un article supprimé part en corbeille (suppression
 * logique) ; il reste restaurable depuis l'écran « Corbeille » du module ou depuis le module
 * Corbeille globale (TrashProviderInterface), puis la rétention le purge. Voir la section
 * « Corbeille » plus bas : c'est le motif à reproduire dans un nouveau module.
 */
final class DemoModule extends AbstractModule implements TrashProviderInterface
{
    public const DATASET = 'demo.item';
    public const NAME_MAX = 80;

    private ?ItemRepository $items = null;

    // =====================================================================
    // Déclaration des routes
    // =====================================================================

    public function routes(RouteCollection $r): void
    {
        // Vues (GET) : une par entrée de navigation du manifeste.
        $r->view('index', [$this, 'index'], permission: 'open');
        $r->view('components', [$this, 'components'], permission: 'open');
        $r->view('forms', [$this, 'forms'], permission: 'open');
        $r->view('tables', [$this, 'tables'], permission: 'open');
        $r->view('feedback', [$this, 'feedback'], permission: 'open');
        $r->view('lifecycle', [$this, 'lifecycle'], permission: 'open');
        $r->view('shared', [$this, 'shared'], permission: 'open');
        $r->view('errors', [$this, 'errors'], permission: 'open');
        $r->view('errors/server', [$this, 'errorsServer'], permission: 'admin');
        $r->view('trash', [$this, 'trashView'], permission: 'delete');
        // Ouverture d'un article par identifiant : openRoute du jeu demo.item (tags, explorateur, corbeille).
        $r->view('item/{id}', [$this, 'openItem'], permission: 'open');

        // Actions (POST, JSON ou multipart) — formulaires.
        $r->action('submit-form', [$this, 'submitForm'], permission: 'open');
        $r->action('quick-filter', [$this, 'quickFilter'], permission: 'open');
        $r->action('reset-items', [$this, 'resetItems'], permission: 'update');
        $r->action('rename', [$this, 'rename'], permission: 'update');

        // Actions — tableaux.
        $r->action('filter-tables', [$this, 'filterTables'], permission: 'open');
        $r->action('toggle', [$this, 'toggle'], permission: 'update');
        $r->action('bulk', [$this, 'bulk'], permission: 'update');
        $r->raw('export.csv', [$this, 'exportCsv'], permission: 'export');

        // Actions — suppression logique et corbeille. Le gestionnaire de suppression définitive
        // s'appelle purgeItem() et non purge() : ce nom est réservé au hook de rétention.
        $r->action('delete', [$this, 'deleteItem'], permission: 'delete');
        $r->action('restore', [$this, 'restoreItem'], permission: 'delete');
        $r->action('purge', [$this, 'purgeItem'], permission: 'delete');

        // Actions — notifications et cycle de vie.
        $r->action('notify', [$this, 'notify'], permission: 'open');
        $r->action('slow', [$this, 'slow'], permission: 'open');
        $r->action('status-update', [$this, 'statusUpdate'], permission: 'open');
        $r->action('banner-update', [$this, 'bannerUpdate'], permission: 'open');
        $r->action('close-tab', [$this, 'closeTab'], permission: 'open');
        $r->action('go-components', [$this, 'goComponents'], permission: 'open');

        // Actions — données partagées.
        $r->action('register', [$this, 'register'], permission: 'update');
        $r->action('tag-attach', [$this, 'tagAttach'], permission: 'update');
        $r->action('tag-detach', [$this, 'tagDetach'], permission: 'update');
        $r->action('relate', [$this, 'relate'], permission: 'update');
        $r->action('unrelate', [$this, 'unrelate'], permission: 'update');
        $r->action('upload', [$this, 'upload'], permission: 'update');
        $r->action('attachment-delete', [$this, 'attachmentDelete'], permission: 'delete');

        // Actions — cas d'erreur volontaires.
        $r->action('error/validation', [$this, 'errorValidation'], permission: 'open');
        $r->action('error/forbidden', [$this, 'errorForbidden'], permission: 'open');
        $r->action('error/not-found', [$this, 'errorNotFound'], permission: 'open');
        $r->action('error/conflict', [$this, 'errorConflict'], permission: 'open');
        $r->action('error/unavailable', [$this, 'errorUnavailable'], permission: 'open');
        $r->action('error/service', [$this, 'errorService'], permission: 'open');
        $r->action('error/server', [$this, 'errorServer'], permission: 'admin');
        // Ressource protégée déclarée dans le manifeste : atelier/demo/action/secret (permission execute).
        $r->action('secret', [$this, 'secret'], permission: 'execute', resource: 'action/secret');
        $r->raw('sample.txt', [$this, 'sampleFile'], permission: 'open');
    }

    /** Service intermodule : lecture du jeu partagé « demo.item » par les autres modules. */
    public function service(): ?object
    {
        return new DemoService($this->ctx, $this->repo());
    }

    // =====================================================================
    // Vues
    // =====================================================================

    public function index(Request $request, array $params): ModuleView
    {
        $byCategory = $this->repo()->quantityByCategory();
        $content = $this->render('index', [
            'total' => $this->repo()->count(),
            'active' => $this->repo()->countActive(),
            'byCategory' => $byCategory,
            'sparkline' => implode(',', array_values($byCategory)),
            'checklist' => self::checklist(),
            'excerpts' => [
                'manifest' => $this->manifestExcerpt(),
                'routes' => $this->sourceExcerpt(__FILE__, '    public function routes(RouteCollection $r): void', "\n    }\n"),
                'view' => $this->sourceExcerpt(__FILE__, '    public function feedback(Request $request, array $params): ModuleView', "\n    }\n"),
                'action' => $this->sourceExcerpt(__FILE__, '    public function toggle(Request $request, array $params): ActionResult', "\n    }\n"),
                'template' => $this->sourceExcerpt(dirname(__DIR__) . '/templates/feedback.php', '<?php', '?>'),
                'js' => $this->sourceExcerpt(dirname(__DIR__) . '/assets/demo.js', '  Atelier.modules.register(', "\n  });\n"),
            ],
        ]);
        return ModuleView::make('Démonstration')
            ->banner($this->bannerHtml('Démonstration', 'Référence des composants et comportements du noyau', 'flask', $this->navLinks('index')))
            ->content($content)
            ->status(count(self::checklist()) . ' vérifications couvertes')
            ->state(['screen' => 'index', 'items' => $this->repo()->count()]);
    }

    public function components(Request $request, array $params): ModuleView
    {
        return ModuleView::make('Composants')
            ->banner($this->bannerHtml('Composants', 'Tout ce que fournit atelier.css', 'grid', $this->navLinks('components')))
            ->content($this->render('components', ['gauge' => 62]))
            ->status('Composants du CSS commun : aucun style redéfini par le module')
            ->state(['screen' => 'components']);
    }

    public function forms(Request $request, array $params): ModuleView
    {
        $first = $this->repo()->find(1);
        $content = $this->render('forms', [
            'categories' => ItemRepository::CATEGORIES,
            'today' => Clock::now()->format('Y-m-d'),
            'firstItem' => $first,
            'rights' => $this->rights(['update']),
        ]);
        return ModuleView::make('Formulaires')
            ->banner($this->bannerHtml('Formulaires', 'Validation serveur, soumission automatique, confirmation', 'edit', $this->navLinks('forms')))
            ->content($content)
            ->status('Ctrl+S soumet le formulaire principal')
            ->state(['screen' => 'forms']);
    }

    public function tables(Request $request, array $params): ModuleView
    {
        $filters = ItemFilters::fromArray($request->allQuery());
        $result = $this->repo()->paginate($filters->criteria(), $filters->page(), $filters->perPage(), $filters->sort(), $filters->direction());
        $rights = $this->rights(['update', 'delete', 'export']);
        $selected = array_map('intval', array_filter(explode(',', (string) $request->query('selected', '')), 'is_numeric'));

        $content = $this->render('tables', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'categories' => ItemRepository::CATEGORIES,
            'rights' => $rights,
            'selected' => $selected,
            'countAll' => $this->repo()->count(),
        ]);
        $shown = count($result['rows']);
        $status = $result['total'] === 0
            ? 'Aucun élément'
            : sprintf('%d élément%s affiché%s sur %d', $shown, $shown > 1 ? 's' : '', $shown > 1 ? 's' : '', $result['total']);

        return ModuleView::make('Tableaux')
            ->banner($this->bannerHtml('Tableaux', $result['total'] . ' article' . ($result['total'] > 1 ? 's' : '') . ($filters->isActive() ? ' (filtrés)' : ''), 'list', $this->navLinks('tables')))
            // Route canonique avec sa chaîne de requête : conservée dans l'URL et rejouée par ->refresh().
            ->route($filters->route('tables'))
            ->content($content)
            ->status($status)
            ->state(['screen' => 'tables', 'page' => $filters->page(), 'total' => $result['total'], 'sort' => $filters->sort(), 'direction' => $filters->direction(), 'filters' => $filters->criteria()]);
    }

    public function feedback(Request $request, array $params): ModuleView
    {
        // Un ModuleView complet : bandeau, contenu, texte de barre d'état et état initial pour le JS.
        return ModuleView::make('Notifications et états')
            ->banner($this->bannerHtml('Notifications et états', 'Toasts, barre d’état, dialogues, blocs d’état', 'bell', $this->navLinks('feedback')))
            ->content($this->render('feedback', ['stateTypes' => ['empty', 'denied', 'error', 'unavailable', 'loading']]))
            ->status('Prêt : cliquez sur un bouton')
            ->state(['screen' => 'feedback']);
    }

    public function lifecycle(Request $request, array $params): ModuleView
    {
        $step = max(1, min(3, (int) $request->query('step', 1)));
        return ModuleView::make('Cycle de vie')
            ->banner($this->bannerHtml('Cycle de vie et onglets', 'Hooks, minuteries, onglet modifié, navigation', 'clock', $this->navLinks('lifecycle')))
            ->route($step > 1 ? 'lifecycle?step=' . $step : 'lifecycle')
            ->content($this->render('lifecycle', ['step' => $step, 'now' => Clock::formatDateTimeSeconds(Clock::utc())]))
            ->status('Étape ' . $step . ' sur 3 — observez la console du navigateur')
            ->state(['screen' => 'lifecycle', 'step' => $step, 'renderedAt' => Clock::utc(), 'user' => $this->ctx->user()['username']]);
    }

    public function shared(Request $request, array $params): ModuleView
    {
        return $this->sharedView((int) $request->query('item', 0));
    }

    /**
     * Ouverture d'un article par son identifiant (route item/{id}, déclarée comme openRoute du
     * jeu demo.item) : les modules transversaux ne savent construire qu'une route contenant la
     * clé locale, sans chaîne de requête. La fiche affichée est celle de l'écran « shared ».
     */
    public function openItem(Request $request, array $params): ModuleView
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw new NotFoundException('Article introuvable.');
        }
        return $this->sharedView($id);
    }

    /** Écran « Corbeille » du module : articles supprimés, restauration et suppression définitive. */
    public function trashView(Request $request, array $params): ModuleView
    {
        $retention = $this->retentionDays();
        $rows = [];
        $authors = [];
        foreach ($this->repo()->trashed($retention) as $item) {
            $by = $item['deleted_by'];
            if ($by !== null && !array_key_exists($by, $authors)) {
                $authors[$by] = $this->ctx->users->find($by)['display_name'] ?? null;
            }
            $item['deleted_by_name'] = $by === null ? null : $authors[$by];
            $item['purge_at'] = $this->purgeAt((string) $item['deleted_at']);
            $item['days_left'] = $this->daysLeft($item['purge_at']);
            $rows[] = $item;
        }
        $count = count($rows);

        $actions = $this->navLinks('trash');
        if ($this->ctx->modules()->has('trash')) {
            $actions .= '<a class="btn btn--ghost" href="#" data-open-module="trash" data-open-route="list?module=demo" title="Corbeille globale : tous les modules et les pièces jointes">'
                . '<svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Toute la corbeille</a>';
        }
        $subtitle = $count . ' article' . ($count > 1 ? 's' : '') . ' · purge automatique après ' . $retention . ' jours';
        return ModuleView::make('Corbeille')
            ->banner($this->bannerHtml('Corbeille', $subtitle, 'trash', $actions))
            ->content($this->render('trash', ['rows' => $rows, 'retention' => $retention]))
            ->status($count === 0 ? 'Corbeille vide' : $count . ' article' . ($count > 1 ? 's' : '') . ' en corbeille')
            ->state(['screen' => 'trash', 'count' => $count]);
    }

    /** Fiche d'un article (écran « shared » et ouverture par identifiant). */
    private function sharedView(int $itemId): ModuleView
    {
        $userId = $this->ctx->userId();
        $item = $itemId > 0 ? $this->requireItem($itemId) : null;

        $shared = $this->ctx->shared;
        $info = $item === null ? null : $shared->registry->find(self::DATASET, (string) $item['id']);
        $infoId = $info === null ? null : (string) $info['id'];

        $vars = [
            'items' => $this->repo()->all(200),
            'item' => $item,
            'info' => $info,
            'tags' => $infoId === null ? [] : $shared->tags->tagsOf($infoId, TagService::SHARED),
            'relations' => $infoId === null ? [] : $shared->relations->relationsOf($infoId),
            'attachments' => $infoId === null ? [] : $shared->attachments->listFor($infoId),
            'relationTypes' => RelationService::DEFAULT_TYPES,
            'catalog' => $shared->catalog->shared(),
            'secret' => [
                'declared' => $shared->catalog->find('demo.secret') !== null,
                'shared' => $shared->catalog->isShared('demo.secret'),
                'readable' => $shared->catalog->canAccess($userId, 'demo.secret', 'read'),
            ],
            'itemReadable' => $shared->catalog->canAccess($userId, self::DATASET, 'read'),
            'serviceCount' => $this->serviceItemCount($userId),
            'rights' => $this->rights(['update', 'delete']),
        ];

        $subtitle = $item === null ? 'Choisissez un article pour commencer' : 'Article n° ' . $item['id'] . ' — ' . $item['name'];
        return ModuleView::make('Données partagées')
            ->banner($this->bannerHtml('Tags, relations, pièces jointes', $subtitle, 'tag', $this->navLinks('shared')))
            ->route($item === null ? 'shared' : 'shared?item=' . $item['id'])
            ->content($this->render('shared', $vars))
            ->status($item === null ? 'Aucun article sélectionné' : ($infoId === null ? 'Article non enregistré dans le registre commun' : 'Identifiant global : ' . $infoId))
            ->state(['screen' => 'shared', 'item' => $item['id'] ?? null, 'infoId' => $infoId]);
    }

    public function errors(Request $request, array $params): ModuleView
    {
        $content = $this->render('errors', [
            'isAdmin' => $this->can('admin'),
            'canExecuteSecret' => $this->can('execute', 'action/secret'),
            'secretResource' => $this->resource('action/secret'),
        ]);
        return ModuleView::make('Cas d’erreur')
            ->banner($this->bannerHtml('Cas d’erreur', 'Toutes les exceptions du noyau, déclenchées volontairement', 'error', $this->navLinks('errors')))
            ->content($content)
            ->status('Chaque bouton provoque une erreur attendue')
            ->state(['screen' => 'errors']);
    }

    /** Vue réservée aux administrateurs : exception PHP brute → réponse 500 avec référence d'incident. */
    public function errorsServer(Request $request, array $params): ModuleView
    {
        $this->require('admin');
        throw new \RuntimeException('Exception de démonstration levée volontairement par la vue errors/server.');
    }

    // =====================================================================
    // Actions — formulaires
    // =====================================================================

    /** Validation serveur complète du formulaire principal (JSON ou multipart si un fichier est joint). */
    public function submitForm(Request $request, array $params): ActionResult
    {
        $name = $request->string('name');
        $email = $request->string('email');
        $quantityRaw = $request->input('quantity');
        $date = $request->string('date');
        $category = $request->string('category');
        $shipping = $request->string('shipping', 'standard');
        $tags = array_values(array_filter(array_map(static fn ($t): string => is_scalar($t) ? trim((string) $t) : '', $request->arrayInput('tags')), static fn (string $t): bool => $t !== ''));
        $comment = $request->string('comment');
        $accept = $request->bool('accept');
        $file = $request->file('attachment');

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Le nom est obligatoire.';
        } elseif (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            $errors['name'] = 'Le nom ne doit pas dépasser ' . self::NAME_MAX . ' caractères.';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        if ($quantityRaw === null || $quantityRaw === '' || !is_numeric($quantityRaw)) {
            $errors['quantity'] = 'La quantité doit être un nombre.';
        } elseif ((int) $quantityRaw < 0) {
            $errors['quantity'] = 'La quantité ne peut pas être négative.';
        }
        $parsedDate = $date === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false) {
            $errors['date'] = 'Date invalide (format attendu : AAAA-MM-JJ).';
        } elseif ($parsedDate < Clock::now()->setTime(0, 0)) {
            $errors['date'] = 'La date ne peut pas être dans le passé.';
        }
        if (!in_array($category, ItemRepository::CATEGORIES, true)) {
            $errors['category'] = 'Choisissez une catégorie dans la liste.';
        }
        if (!in_array($shipping, ['standard', 'express'], true)) {
            $errors['shipping'] = 'Mode de livraison inconnu.';
        }
        if (!$accept) {
            $errors['accept'] = 'Vous devez accepter les conditions.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $data = [
            'name' => $name,
            'email' => $email,
            'quantity' => (int) $quantityRaw,
            'date' => $date,
            'category' => $category,
            'shipping' => $shipping,
            'tags' => $tags,
            'comment' => $comment,
            'attachment' => $file === null ? null : ['name' => (string) $file['name'], 'size' => (int) $file['size']],
            'transport' => $file === null ? 'json' : 'multipart',
        ];
        $this->log('demo.form_submit', 'success', null, 'Formulaire de démonstration validé', ['transport' => $data['transport']]);
        return ActionResult::ok($data, 'Formulaire valide.')->dirty(false)->status('Formulaire validé à ' . Clock::formatDateTimeSeconds(Clock::utc()));
    }

    /** Filtre instantané (formulaire data-auto-submit) : renvoie des données, le JS du module les affiche. */
    public function quickFilter(Request $request, array $params): ActionResult
    {
        $filters = ItemFilters::fromArray(['q' => $request->string('q'), 'category' => $request->string('category')]);
        $result = $this->repo()->quickSearch($filters->criteria(), 8);
        $rows = array_map(static fn (array $r): array => ['id' => $r['id'], 'name' => $r['name'], 'category' => $r['category'], 'quantity' => $r['quantity']], $result['rows']);
        return ActionResult::ok(['rows' => $rows, 'total' => $result['total'], 'q' => $filters->q()])
            ->status($result['total'] . ' résultat' . ($result['total'] > 1 ? 's' : '') . ($filters->q() !== '' ? ' pour « ' . $filters->q() . ' »' : ''));
    }

    /**
     * Formulaire avec confirmation : régénère les 120 articles déterministes (corbeille comprise).
     *
     * Seule suppression PHYSIQUE déclenchée depuis l'interface, et exception assumée à la règle
     * de la corbeille : c'est un outil de démonstration qui remet la table dans un état connu,
     * pas la suppression d'une donnée métier. Un module réel ne doit pas l'imiter. Le registre
     * commun est tenu à jour dans la même transaction : les articles effacés en sont retirés,
     * ceux qui sortent de la corbeille y sont signalés restaurés.
     */
    public function resetItems(Request $request, array $params): ActionResult
    {
        $result = $this->ctx->db->transaction(function (): array {
            $result = $this->repo()->regenerate();
            $registry = $this->ctx->shared->registry;
            foreach ($result['removed'] as $id) {
                $registry->unregister(self::DATASET, (string) $id);
            }
            $registry->restore(self::DATASET, array_map('strval', range(1, ItemRepository::SEED_COUNT)));
            return $result;
        });
        $count = $result['count'];
        $this->log('demo.reset', 'success', null, 'Articles de démonstration régénérés', ['count' => $count, 'removed' => count($result['removed'])]);
        return ActionResult::ok(['count' => $count], $count . ' articles régénérés à l’identique ; ceux qui étaient en corbeille sont restaurés.');
    }

    /** Bouton data-action + data-prompt : renomme un article. */
    public function rename(Request $request, array $params): ActionResult
    {
        $id = (int) ($request->int('id') ?? 0);
        $name = $request->string('name');
        $this->requireItem($id);
        if ($name === '') {
            throw ValidationException::single('name', 'Le nom ne peut pas être vide.');
        }
        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw ValidationException::single('name', 'Le nom ne doit pas dépasser ' . self::NAME_MAX . ' caractères.');
        }
        $this->repo()->rename($id, $name);
        $info = $this->ctx->shared->registry->find(self::DATASET, (string) $id);
        if ($info !== null) {
            $this->ctx->shared->registry->updateLabel((string) $info['id'], $name);
        }
        $this->log('demo.rename', 'success', 'item:' . $id, 'Article renommé');
        return ActionResult::ok(['id' => $id, 'name' => $name], 'Article n° ' . $id . ' renommé.')->refresh();
    }

    // =====================================================================
    // Actions — tableaux
    // =====================================================================

    /** Formulaire de filtres (auto-submit) : redirige vers la liste filtrée, page remise à 1. */
    public function filterTables(Request $request, array $params): ActionResult
    {
        $filters = ItemFilters::fromArray($request->all() + ['page' => 1]);
        return ActionResult::ok()->navigate($filters->route('tables', ['page' => null]));
    }

    /** Action par ligne : inverse l'état actif puis recharge la vue (->refresh()). */
    public function toggle(Request $request, array $params): ActionResult
    {
        $id = (int) ($request->int('id') ?? 0);
        $this->requireItem($id);
        $active = $this->repo()->toggleActive($id);
        $this->log('demo.toggle', 'success', 'item:' . $id, $active ? 'Article activé' : 'Article désactivé');
        return ActionResult::ok(['id' => $id, 'active' => $active], 'Article n° ' . $id . ($active ? ' activé.' : ' désactivé.'))->refresh();
    }

    /** Action groupée : ids[] + op (activate|deactivate|delete). */
    public function bulk(Request $request, array $params): ActionResult
    {
        $ids = array_values(array_filter(array_map('intval', $request->arrayInput('ids')), static fn (int $id): bool => $id > 0));
        $op = $request->string('op', 'activate');
        if ($ids === []) {
            return ActionResult::warning(null, 'Aucun article sélectionné.');
        }
        switch ($op) {
            case 'activate':
                $count = $this->repo()->setActive($ids, true);
                $message = $count . ' article' . ($count > 1 ? 's activés.' : ' activé.');
                break;
            case 'deactivate':
                $count = $this->repo()->setActive($ids, false);
                $message = $count . ' article' . ($count > 1 ? 's désactivés.' : ' désactivé.');
                break;
            case 'delete':
                // Contrôle fin côté serveur : la route exige « update », la suppression exige « delete ».
                $this->require('delete');
                $count = count($this->moveToTrash($ids));
                if ($count === 0) {
                    return ActionResult::warning(null, 'Aucun article à supprimer : la sélection est déjà dans la corbeille.')->refresh();
                }
                $message = $count . ' article' . ($count > 1 ? 's placés' : ' placé') . ' dans la corbeille (restaurable' . ($count > 1 ? 's' : '') . ' ' . $this->retentionDays() . ' jours).';
                break;
            default:
                throw ValidationException::single('op', 'Opération inconnue : ' . $op);
        }
        $this->log('demo.bulk', 'success', null, 'Action groupée ' . $op, ['ids' => $ids]);
        return ActionResult::ok(['count' => $count, 'op' => $op], $message)->refresh();
    }

    /** Export CSV (route brute) des lignes filtrées et triées. */
    public function exportCsv(Request $request, array $params): Response
    {
        $filters = ItemFilters::fromArray($request->allQuery());
        $rows = $this->repo()->export($filters->criteria(), $filters->sort(), $filters->direction());
        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            throw new \RuntimeException('Impossible de préparer l’export.');
        }
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($out, ['id', 'name', 'category', 'quantity', 'price_eur', 'active', 'created_at'], ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, [$row['id'], $row['name'], $row['category'], $row['quantity'], number_format($row['price'] / 100, 2, ',', ''), $row['active'] ? '1' : '0', $row['created_at']], ';', '"', '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->log('demo.export', 'success', null, 'Export CSV', ['rows' => count($rows)]);
        return Response::raw($csv, 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="demo-items-' . Clock::now()->format('Ymd-His') . '.csv"');
    }

    // =====================================================================
    // Actions — corbeille du module
    //
    // Motif à reproduire dans tout module qui supprime des données :
    //  1. supprimer = mettre en corbeille (deleted_at), jamais DELETE ;
    //  2. signaler chaque mise en corbeille ET chaque restauration au registre commun
    //     (registry->trash / registry->restore) pour que tags, relations et Explorateur
    //     masquent puis ré-affichent l'élément ;
    //  3. seule la purge (manuelle ou par rétention) efface la ligne, et retire alors
    //     l'élément du registre (registry->unregister) ;
    //  4. l'écran du module, la corbeille globale et le hook de rétention passent par les
    //     mêmes méthodes privées (moveToTrash, restoreFromTrash, purgeFromTrash) : un seul
    //     endroit où la table et le registre sont tenus cohérents.
    // =====================================================================

    /** Suppression unitaire (bouton de ligne de l'écran Tableaux) : l'article part en corbeille. */
    public function deleteItem(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($request->int('id'));
        $this->moveToTrash([$item['id']]);
        $this->log('demo.delete', 'success', 'item:' . $item['id'], 'Article placé dans la corbeille');
        return ActionResult::ok(['id' => $item['id']], 'Article « ' . $item['name'] . ' » placé dans la corbeille (restaurable ' . $this->retentionDays() . ' jours).')->refresh();
    }

    public function restoreItem(Request $request, array $params): ActionResult
    {
        $item = $this->restoreFromTrash((int) ($request->int('id') ?? 0), 'Article restauré depuis la corbeille du module');
        return ActionResult::ok(['id' => $item['id']], 'Article « ' . $item['name'] . ' » restauré.')->refresh();
    }

    public function purgeItem(Request $request, array $params): ActionResult
    {
        $item = $this->purgeFromTrash((int) ($request->int('id') ?? 0), 'Article supprimé définitivement depuis la corbeille du module');
        return ActionResult::ok(['id' => $item['id']], 'Article « ' . $item['name'] . ' » supprimé définitivement.')->refresh();
    }

    // =====================================================================
    // Corbeille globale (TrashProviderInterface)
    //
    // Les articles sont communs à tous les utilisateurs du module : la règle est celle de
    // l'écran « Corbeille » (permission delete pour voir, restaurer et purger). Le module
    // Corbeille ne fait confiance à personne : il relaie, le module revérifie ses droits.
    // =====================================================================

    public function trashItems(): array
    {
        // Même périmètre que l'écran « Corbeille » : sans « delete », rien à montrer.
        if (!$this->can('delete')) {
            return [];
        }
        $items = [];
        foreach ($this->repo()->trashed($this->retentionDays()) as $item) {
            $items[] = [
                'id' => (string) $item['id'],
                'label' => (string) $item['name'],
                'dataset' => self::DATASET,
                'deleted_at' => (string) $item['deleted_at'],
                'deleted_by' => $item['deleted_by'],
                'purge_at' => $this->purgeAt((string) $item['deleted_at']),
                'can_restore' => true,
                'can_purge' => true,
            ];
        }
        return $items;
    }

    public function restoreTrashItem(string $id): void
    {
        $this->restoreFromTrash($this->trashKey($id), 'Article restauré depuis la corbeille globale');
    }

    public function purgeTrashItem(string $id): void
    {
        $this->purgeFromTrash($this->trashKey($id), 'Article supprimé définitivement depuis la corbeille globale');
    }

    // =====================================================================
    // Actions — notifications, barre d'état, cycle de vie
    // =====================================================================

    /** Trois niveaux de message côté serveur : ok (succès), info, warning. */
    public function notify(Request $request, array $params): ActionResult
    {
        return match ($request->string('level', 'ok')) {
            'info' => ActionResult::info(null, 'Message d’information émis par le serveur.'),
            'warning' => ActionResult::warning(null, 'Avertissement émis par le serveur : rien de grave.'),
            default => ActionResult::ok(null, 'Action réussie côté serveur.'),
        };
    }

    /** Action volontairement lente (2 s) pour observer l'indicateur d'occupation. */
    public function slow(Request $request, array $params): ActionResult
    {
        $seconds = max(1, min(5, $request->int('seconds') ?? 2));
        sleep($seconds);
        return ActionResult::ok(['seconds' => $seconds], 'Traitement long terminé (' . $seconds . ' s).');
    }

    public function statusUpdate(Request $request, array $params): ActionResult
    {
        return ActionResult::ok()->status('Barre d’état mise à jour par le serveur à ' . Clock::formatDateTimeSeconds(Clock::utc()));
    }

    /** Remplace le bandeau de l'onglet sans recharger la vue. */
    public function bannerUpdate(Request $request, array $params): ActionResult
    {
        $n = max(1, $request->int('n') ?? 1);
        $banner = $this->bannerHtml('Cycle de vie et onglets', 'Bandeau mis à jour (' . $n . ' fois) à ' . Clock::formatDateTimeSeconds(Clock::utc()), 'refresh', $this->navLinks('lifecycle'));
        return ActionResult::info(['n' => $n], 'Bandeau remplacé par le serveur.')->banner($banner);
    }

    public function closeTab(Request $request, array $params): ActionResult
    {
        return ActionResult::ok(null, 'Onglet fermé par une directive du serveur.')->close();
    }

    public function goComponents(Request $request, array $params): ActionResult
    {
        return ActionResult::ok()->navigate('components');
    }

    // =====================================================================
    // Actions — registre, tags, relations, pièces jointes
    // =====================================================================

    public function register(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($request->int('item'));
        $infoId = $this->ctx->shared->registry->register(self::DATASET, (string) $item['id'], (string) $item['name'], $this->ctx->userId());
        $this->log('demo.register', 'success', 'item:' . $item['id'], 'Article enregistré dans le registre commun');
        return ActionResult::ok(['infoId' => $infoId], 'Identifiant global attribué : ' . $infoId)->refresh();
    }

    public function tagAttach(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($request->int('item'));
        $tag = trim(ltrim($request->string('tag'), '#'));
        if ($tag === '') {
            throw ValidationException::single('tag', 'Saisissez un nom de tag.');
        }
        if (mb_strlen($tag, 'UTF-8') > 60) {
            throw ValidationException::single('tag', 'Un tag comporte au plus 60 caractères.');
        }
        $infoId = $this->ctx->shared->registry->register(self::DATASET, (string) $item['id'], (string) $item['name'], $this->ctx->userId());
        $created = $this->ctx->shared->tags->attach($infoId, $tag, TagService::SHARED, $this->ctx->userId());
        return ActionResult::ok(['tag' => $created], 'Tag « ' . $created['name'] . ' » attaché.')->refresh();
    }

    public function tagDetach(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($request->int('item'));
        $tagId = (int) ($request->int('tag_id') ?? 0);
        $info = $this->ctx->shared->registry->find(self::DATASET, (string) $item['id']);
        if ($info === null || $tagId <= 0) {
            throw new NotFoundException('Tag ou article introuvable.');
        }
        $this->ctx->shared->tags->detach((string) $info['id'], $tagId);
        return ActionResult::ok(null, 'Tag détaché.')->refresh();
    }

    public function relate(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($request->int('item'));
        $targetId = (int) ($request->int('to') ?? 0);
        $type = $request->string('type', 'related');
        $target = $this->repo()->find($targetId);
        if ($target === null) {
            throw ValidationException::single('to', 'Choisissez un article cible.');
        }
        if (!isset(RelationService::DEFAULT_TYPES[$type])) {
            throw ValidationException::single('type', 'Type de relation inconnu.');
        }
        $userId = $this->ctx->userId();
        $registry = $this->ctx->shared->registry;
        $fromInfo = $registry->register(self::DATASET, (string) $item['id'], (string) $item['name'], $userId);
        $toInfo = $registry->register(self::DATASET, (string) $target['id'], (string) $target['name'], $userId);
        // RelationService lève lui-même ValidationException si from === to.
        $relationId = $this->ctx->shared->relations->relate($type, $fromInfo, $toInfo, $userId, $request->string('comment') ?: null);
        return ActionResult::ok(['id' => $relationId], 'Relation « ' . RelationService::DEFAULT_TYPES[$type] . ' » créée vers l’article n° ' . $target['id'] . '.')->refresh();
    }

    public function unrelate(Request $request, array $params): ActionResult
    {
        $id = (int) ($request->int('id') ?? 0);
        if ($id <= 0 || $this->ctx->shared->relations->find($id) === null) {
            throw new NotFoundException('Relation introuvable (déjà supprimée ?).');
        }
        $this->ctx->shared->relations->remove($id);
        return ActionResult::ok(null, 'Relation supprimée.')->refresh();
    }

    /** Téléversement multipart : <form data-action="upload"> avec <input type="file" name="file">. */
    public function upload(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($request->int('item'));
        $file = $this->ctx->request()->file('file');
        if ($file === null) {
            throw ValidationException::single('file', 'Choisissez un fichier à joindre.');
        }
        $userId = $this->ctx->userId();
        $infoId = $this->ctx->shared->registry->register(self::DATASET, (string) $item['id'], (string) $item['name'], $userId);
        // AttachmentService contrôle type MIME, extension, taille et quotas (ValidationException sinon).
        $record = $this->ctx->shared->attachments->store($file, $infoId, $userId);
        $this->log('demo.upload', 'success', 'attachment:' . $record['id'], 'Pièce jointe ajoutée à l’article n° ' . $item['id'], ['size' => $record['size']]);
        return ActionResult::ok(['id' => $record['id']], 'Fichier « ' . $record['original_name'] . ' » joint (' . Str::humanSize((int) $record['size']) . ').')->refresh();
    }

    public function attachmentDelete(Request $request, array $params): ActionResult
    {
        $id = $request->string('id');
        $attachment = $id === '' ? null : $this->ctx->shared->attachments->find($id);
        if ($attachment === null) {
            throw new NotFoundException('Pièce jointe introuvable.');
        }
        // La pièce doit appartenir à un article de ce module : jamais de suppression « à l'aveugle ».
        $info = $attachment['info_id'] === null ? null : $this->ctx->shared->registry->get((string) $attachment['info_id']);
        if ($info === null || $info['dataset_code'] !== self::DATASET) {
            throw new ForbiddenException('Cette pièce jointe n’appartient pas au module de démonstration.');
        }
        $this->ctx->shared->attachments->softDelete($id);
        $this->log('demo.attachment_delete', 'success', 'attachment:' . $id, 'Pièce jointe supprimée (logiquement)');
        return ActionResult::ok(null, 'Pièce jointe supprimée. La purge physique est faite par la maintenance.')->refresh();
    }

    // =====================================================================
    // Actions — cas d'erreur volontaires
    // =====================================================================

    public function errorValidation(Request $request, array $params): ActionResult
    {
        throw new ValidationException([
            'name' => 'Le nom est obligatoire (erreur volontaire).',
            'email' => 'Adresse e-mail invalide (erreur volontaire).',
        ]);
    }

    public function errorForbidden(Request $request, array $params): ActionResult
    {
        throw new ForbiddenException('Accès refusé volontairement : vous n’avez pas la permission fictive « demo:forbidden ».', $this->resource('action/forbidden'), 'execute');
    }

    public function errorNotFound(Request $request, array $params): ActionResult
    {
        throw new NotFoundException('L’article n° 999999 n’existe pas (erreur volontaire).');
    }

    public function errorConflict(Request $request, array $params): ActionResult
    {
        throw new ConflictException('Cet article a été modifié par un autre utilisateur entre-temps (erreur volontaire). Rechargez avant de réessayer.');
    }

    public function errorUnavailable(Request $request, array $params): ActionResult
    {
        throw new ModuleUnavailableException('Le module « exemple » est en maintenance (erreur volontaire).', 'exemple', 'maintenance');
    }

    /** Appel réel de l'API intermodule vers un module inexistant : ModuleUnavailableException du noyau. */
    public function errorService(Request $request, array $params): ActionResult
    {
        $this->ctx->moduleService('inexistant');
        return ActionResult::ok(); // jamais atteint
    }

    public function errorServer(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        throw new \LogicException('Exception PHP brute levée volontairement par l’action error/server.');
    }

    /** Action protégée par la ressource atelier/demo/action/secret (permission execute). */
    public function secret(Request $request, array $params): ActionResult
    {
        $this->log('demo.secret', 'success', null, 'Action réservée exécutée');
        return ActionResult::ok(['at' => Clock::utc()], 'Action réservée exécutée : vous détenez la permission « execute » sur ' . $this->resource('action/secret') . '.');
    }

    /** Route brute : petit fichier texte à télécharger. */
    public function sampleFile(Request $request, array $params): Response
    {
        $text = "Atelier — module de démonstration\n"
            . "Fichier produit par la route brute « sample.txt » le " . Clock::formatDateTimeSeconds(Clock::utc()) . ".\n"
            . "Utilisateur : " . $this->ctx->user()['username'] . "\n";
        return Response::raw($text, 'text/plain; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="atelier-demo.txt"');
    }

    // =====================================================================
    // Hooks
    // =====================================================================

    /** Données de démonstration : 120 articles déterministes si la table est vide (idempotent). */
    public function seed(): string
    {
        // countAll() et non count() : des articles tous en corbeille ne rendent pas la table vide.
        if ($this->repo()->countAll() > 0) {
            return 'articles de démonstration déjà présents (' . $this->repo()->countAll() . ')';
        }
        $count = $this->repo()->regenerate()['count'];
        return $count . ' articles de démonstration créés';
    }

    /**
     * Rétention (console maintenance:purge) : purge physique des articles en corbeille depuis
     * plus de trash.retention_days jours, avec retrait du registre commun.
     */
    public function purge(): string
    {
        $days = $this->retentionDays();
        $ids = $this->repo()->expiredTrashIds($days);
        foreach ($ids as $id) {
            $this->purgeRow($id);
            $this->log('demo.purge', 'success', 'item:' . $id, 'Article purgé par la rétention (' . $days . ' jours)');
        }
        return count($ids) . ' article(s) purgé(s) de la corbeille (> ' . $days . ' jours)';
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Liste des vérifications couvertes (§6.7.3), avec l'écran qui les exerce. */
    public static function checklist(): array
    {
        return [
            ['Styles des titres, textes, boutons, champs, listes et panneaux', 'components'],
            ['CSS commun vs CSS propre au module (préfixe .module-demo)', 'components'],
            ['États normal, survolé, sélectionné, désactivé, erreur', 'components'],
            ['Formulaires et validation serveur près des champs', 'forms'],
            ['Modales et confirmations (confirm, alert, prompt, dialogue libre)', 'feedback'],
            ['Tableaux : tri, filtres, pagination, sélection, état vide, export', 'tables'],
            ['Toasters des quatre niveaux, regroupement des doublons', 'feedback'],
            ['Messages temporaires et persistants, référence d’incident', 'feedback'],
            ['Bandeau dynamique remplacé par une directive serveur', 'lifecycle'],
            ['Barre d’état dynamique (client et serveur)', 'feedback'],
            ['Indicateurs de chargement et de progression', 'feedback'],
            ['Accès refusé, session expirée, module indisponible, erreur serveur', 'errors'],
            ['Ouverture, suspension, réactivation et fermeture d’onglet', 'lifecycle'],
            ['Largeurs bureau : grilles, split, cartes, tableaux larges', 'components'],
            ['Libération des écouteurs et minuteries à la fermeture', 'lifecycle'],
            ['Registre commun, tags, relations, pièces jointes, catalogue', 'shared'],
        ];
    }

    private function repo(): ItemRepository
    {
        return $this->items ??= new ItemRepository($this->ctx->db);
    }

    /**
     * Article vivant, ou NotFoundException. Un article en corbeille est « introuvable » pour
     * toutes les actions courantes, mais le message oriente vers la restauration.
     *
     * @return array<string, mixed>
     */
    private function requireItem(?int $id): array
    {
        $item = $id === null || $id <= 0 ? null : $this->repo()->find($id);
        if ($item !== null) {
            return $item;
        }
        if ($id !== null && $id > 0 && $this->repo()->findTrashed($id) !== null) {
            throw new NotFoundException('L’article n° ' . $id . ' est dans la corbeille : restaurez-le pour l’utiliser.');
        }
        throw new NotFoundException($id !== null && $id > 0 ? 'Article n° ' . $id . ' introuvable.' : 'Article introuvable : choisissez un article dans la liste.');
    }

    // ----- Corbeille : les seuls chemins qui modifient deleted_at ou effacent une ligne -----

    /**
     * Met des articles à la corbeille. Table du module et registre commun changent dans la même
     * transaction : un article ne peut pas être en corbeille ici et visible sous ses tags.
     *
     * @param list<int> $ids
     * @return list<int> identifiants réellement mis en corbeille (vivants parmi $ids)
     */
    private function moveToTrash(array $ids): array
    {
        return $this->ctx->db->transaction(function () use ($ids): array {
            $trashed = $this->repo()->trash($ids, $this->ctx->userId());
            if ($trashed !== []) {
                $this->ctx->shared->registry->trash(self::DATASET, array_map('strval', $trashed));
            }
            return $trashed;
        });
    }

    /**
     * Restaure un article en corbeille (écran du module et corbeille globale).
     *
     * @return array<string, mixed> l'article tel qu'il était en corbeille
     */
    private function restoreFromTrash(int $id, string $logMessage): array
    {
        $this->require('delete', null, 'Vous n’avez pas le droit de restaurer des articles.');
        $item = $this->requireTrashed($id);
        $this->ctx->db->transaction(function () use ($id): void {
            $this->repo()->restore($id);
            $this->ctx->shared->registry->restore(self::DATASET, (string) $id);
        });
        $this->log('demo.restore', 'success', 'item:' . $id, $logMessage);
        return $item;
    }

    /**
     * Supprime définitivement un article en corbeille (écran du module et corbeille globale).
     *
     * @return array<string, mixed> l'article supprimé
     */
    private function purgeFromTrash(int $id, string $logMessage): array
    {
        $this->require('delete', null, 'Vous n’avez pas le droit de supprimer définitivement des articles.');
        $item = $this->requireTrashed($id);
        $this->purgeRow($id);
        $this->log('demo.purge', 'success', 'item:' . $id, $logMessage);
        return $item;
    }

    /** Effacement physique d'un article en corbeille et retrait du registre commun (tags, relations, pièces jointes). */
    private function purgeRow(int $id): void
    {
        $this->ctx->db->transaction(function () use ($id): void {
            $this->repo()->purge($id);
            $this->ctx->shared->registry->unregister(self::DATASET, (string) $id);
        });
    }

    /** @return array<string, mixed> article en corbeille, NotFoundException sinon */
    private function requireTrashed(int $id): array
    {
        $item = $id > 0 ? $this->repo()->findTrashed($id) : null;
        if ($item === null) {
            throw new NotFoundException('Cet article n’est pas dans la corbeille (déjà restauré ou supprimé ?).');
        }
        return $item;
    }

    /** Identifiant reçu de la corbeille globale (chaîne) : entier strictement positif, sinon introuvable. */
    private function trashKey(string $id): int
    {
        if (preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
            throw new NotFoundException('Cet article n’est pas dans la corbeille.');
        }
        return (int) $id;
    }

    private function retentionDays(): int
    {
        return max(1, $this->ctx->config->int('trash.retention_days', 30));
    }

    /** Date UTC de purge automatique d'un article mis en corbeille à $deletedAt. */
    private function purgeAt(string $deletedAt): ?string
    {
        $at = Clock::parseUtc($deletedAt)?->modify('+' . $this->retentionDays() . ' days');
        return $at === null ? null : Clock::utc($at);
    }

    /** Jours restants avant la purge automatique (0 si dépassée). */
    private function daysLeft(?string $purgeAt): ?int
    {
        $at = Clock::parseUtc($purgeAt);
        return $at === null ? null : max(0, (int) ceil(($at->getTimestamp() - Clock::now()->getTimestamp()) / 86400));
    }

    private function serviceItemCount(int $userId): ?int
    {
        try {
            /** @var DemoService $service */
            $service = $this->ctx->moduleService('demo');
            return count($service->items($userId));
        } catch (ForbiddenException) {
            return null;
        }
    }

    private function bannerHtml(string $title, string $subtitle, string $icon, string $actions = ''): string
    {
        return $this->renderCore('banner', ['icon' => $icon, 'title' => $title, 'subtitle' => $subtitle, 'actions' => $actions]);
    }

    /** Liens du bandeau vers les autres écrans (celui en cours est marqué actif). */
    private function navLinks(string $current): string
    {
        $links = [
            'index' => ['Vue d’ensemble', 'home'],
            'components' => ['Composants', 'grid'],
            'forms' => ['Formulaires', 'edit'],
            'tables' => ['Tableaux', 'list'],
            'feedback' => ['Notifications', 'bell'],
            'lifecycle' => ['Cycle de vie', 'clock'],
            'shared' => ['Partagé', 'tag'],
            'errors' => ['Erreurs', 'error'],
        ];
        // L'écran Corbeille exige « delete » : inutile de proposer un lien qui mènerait à un refus.
        if ($this->can('delete')) {
            $links['trash'] = ['Corbeille', 'trash'];
        }
        $html = '<div class="btn-group" role="group" aria-label="Écrans de la démonstration">';
        foreach ($links as $route => [$label, $icon]) {
            $active = $route === $current ? ' is-active' : '';
            $html .= '<a class="btn btn--sm' . $active . '" href="#" data-route="' . $this->e($route) . '" title="' . $this->e($label) . '"' . ($route === $current ? ' aria-current="page"' : '') . '>'
                . '<svg class="icon icon--sm" aria-hidden="true"><use href="#i-' . $this->e($icon) . '"></use></svg><span class="demo-banner-label">' . $this->e($label) . '</span></a>';
        }
        return $html . '</div>';
    }

    /** Extrait du manifeste réel du module (quelques clés), pour la section « Structure d'un module ». */
    private function manifestExcerpt(): string
    {
        $raw = json_decode((string) file_get_contents(dirname(__DIR__) . '/manifest.json'), true);
        if (!is_array($raw)) {
            return '{}';
        }
        $subset = array_intersect_key($raw, array_flip(['id', 'name', 'version', 'namespace', 'entry', 'defaultRoute']));
        $subset['navigation'] = array_slice($raw['navigation'] ?? [], 0, 2);
        $subset['assets'] = ['css' => $raw['assets']['css'] ?? [], 'js' => $raw['assets']['js'] ?? []];
        $subset['datasets'] = array_map(static fn (array $d): array => array_intersect_key($d, array_flip(['code', 'name', 'visibility', 'openRoute', 'tables'])), $raw['datasets'] ?? []);
        return (string) json_encode($subset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Extrait d'un fichier source du module entre deux marqueurs (le code montré est toujours le code réel). */
    private function sourceExcerpt(string $file, string $start, string $end, int $maxLines = 40): string
    {
        $source = is_file($file) ? (string) file_get_contents($file) : '';
        $from = strpos($source, $start);
        if ($from === false) {
            return '// extrait indisponible';
        }
        $to = strpos($source, $end, $from + strlen($start));
        $excerpt = $to === false ? substr($source, $from) : substr($source, $from, $to - $from + strlen($end));
        $lines = explode("\n", rtrim($excerpt));
        if (count($lines) > $maxLines) {
            $lines = array_merge(array_slice($lines, 0, $maxLines), ['    // …']);
        }
        return implode("\n", $lines);
    }
}
