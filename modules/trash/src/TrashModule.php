<?php

declare(strict_types=1);

namespace Atelier\Modules\Trash;

use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;

/**
 * Corbeille globale : agrège les éléments supprimés logiquement par les modules fournisseurs
 * (TrashProviderInterface) et les pièces jointes supprimées du noyau. Restauration et suppression
 * définitive, unitaires ou groupées ; « Vider la corbeille » réservé à la permission purge-all.
 *
 * Le module ne possède aucune donnée : chaque fournisseur reste seul juge de ses droits et
 * applique lui-même sa rétention (hook purge()).
 */
final class TrashModule extends AbstractModule
{
    public const PER_PAGE = 25;
    public const EMPTY_CONFIRM_WORD = 'VIDER';
    public const WARNING_DAYS = 3;

    private ?TrashAggregator $aggregator = null;

    /** Un agrégateur (et son cache) par requête : indispensable quand une même instance sert plusieurs requêtes (tests). */
    public function boot(\Atelier\Modules\ModuleContext $context): void
    {
        parent::boot($context);
        $this->aggregator = null;
    }

    public function routes(RouteCollection $r): void
    {
        $r->view('list', [$this, 'list'], permission: 'open');

        $r->action('filter', [$this, 'filter'], permission: 'open');
        $r->action('restore', [$this, 'restore'], permission: 'open');
        $r->action('purge', [$this, 'purgeItem'], permission: 'open');
        $r->action('restore-many', [$this, 'restoreMany'], permission: 'open');
        $r->action('purge-many', [$this, 'purgeMany'], permission: 'open');
        $r->action('empty', [$this, 'empty'], permission: 'purge-all');
        $r->action('badge', [$this, 'badge'], permission: 'open', methods: ['GET', 'POST']);
    }

    // =====================================================================
    // Vue
    // =====================================================================

    /** list?source=&module=&q=&sort=&dir=&page= : agrégation en mémoire, puis filtre, tri et tranche. */
    public function list(Request $request, array $params): ModuleView
    {
        $source = (string) $request->query('source', '');
        if (!in_array($source, ['', TrashAggregator::SOURCE_MODULE, TrashAggregator::SOURCE_ATTACHMENT], true)) {
            $source = '';
        }
        $moduleFilter = trim((string) $request->query('module', ''));
        $search = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'deleted_at');
        if (!in_array($sort, TrashAggregator::SORTS, true)) {
            $sort = 'deleted_at';
        }
        $defaultDir = $sort === 'deleted_at' ? 'desc' : 'asc';
        $direction = strtolower((string) $request->query('dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) $request->query('page', 1));

        $collection = $this->aggregator()->collect();
        $all = $collection['items'];
        $filtered = TrashAggregator::sort(TrashAggregator::filter($all, $source, $moduleFilter, $search), $sort, $direction);
        $total = count($filtered);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $counts = [
            '' => count($all),
            TrashAggregator::SOURCE_MODULE => count(array_filter($all, static fn (array $i): bool => $i['source'] === TrashAggregator::SOURCE_MODULE)),
            TrashAggregator::SOURCE_ATTACHMENT => count(array_filter($all, static fn (array $i): bool => $i['source'] === TrashAggregator::SOURCE_ATTACHMENT)),
        ];
        $purgeable = count(array_filter($all, static fn (array $i): bool => $i['can_purge']));
        $canEmpty = $this->can('purge-all');
        $retention = $this->retentionDays();

        $query = array_filter(
            ['source' => $source, 'module' => $moduleFilter, 'q' => $search, 'sort' => $sort, 'dir' => $direction],
            static fn (string $v): bool => $v !== ''
        );

        $content = $this->render('list', [
            'items' => $rows,
            'total' => $total,
            'countAll' => count($all),
            'counts' => $counts,
            'warnings' => $collection['warnings'],
            'modules' => TrashAggregator::moduleOptions($all),
            'source' => $source,
            'moduleFilter' => $moduleFilter,
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'query' => $query,
            'retention' => $retention,
            'warningDays' => self::WARNING_DAYS,
            'canRestoreAny' => array_filter($rows, static fn (array $i): bool => $i['can_restore']) !== [],
            'canPurgeAny' => array_filter($rows, static fn (array $i): bool => $i['can_purge']) !== [],
        ]);

        $actions = '';
        if ($canEmpty && $purgeable > 0) {
            $actions = '<button type="button" class="btn btn--danger" data-action="empty"'
                . ' data-prompt="Tapez ' . self::EMPTY_CONFIRM_WORD . ' pour confirmer la suppression définitive de ' . $this->plural($purgeable, 'élément') . '."'
                . ' data-prompt-field="confirm" data-confirm-title="Vider la corbeille" title="Supprimer définitivement tous les éléments que vous pouvez purger">'
                . '<svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Vider la corbeille</button>';
        }
        $subtitle = $this->plural(count($all), 'élément') . ', purge automatique après ' . $this->plural($retention, 'jour');

        return ModuleView::make('Corbeille')
            ->banner($this->banner('Corbeille', $subtitle, $actions))
            ->content($content)
            ->status($total === 0 ? 'Corbeille vide' : $this->plural(count($rows), 'élément affiché', 'éléments affichés') . ' sur ' . $total);
    }

    // =====================================================================
    // Actions
    // =====================================================================

    /** Formulaire de filtres : redirige vers la liste filtrée (page remise à 1). */
    public function filter(Request $request, array $params): ActionResult
    {
        $query = array_filter([
            'source' => $request->string('source'),
            'module' => $request->string('module'),
            'q' => $request->string('q'),
            'sort' => $request->string('sort'),
            'dir' => $request->string('dir'),
        ], static fn (string $v): bool => $v !== '');
        return ActionResult::ok()->navigate('list' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    /** Restaure un élément : { source, module, id } ou { key: "source:module:id" }. */
    public function restore(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($request);
        $this->aggregator()->restore($item);
        $this->log('trash.restore', 'success', $item['key'], 'Élément restauré : ' . $item['label'], $this->details($item), 'data');
        return ActionResult::ok(['key' => $item['key']], '« ' . $item['label'] . ' » restauré.')->refresh();
    }

    /** Supprime définitivement un élément (mêmes paramètres que restore). */
    public function purgeItem(Request $request, array $params): ActionResult
    {
        $item = $this->requireItem($request);
        $this->aggregator()->purge($item);
        $this->log('trash.purge', 'success', $item['key'], 'Élément supprimé définitivement : ' . $item['label'], $this->details($item), 'data');
        return ActionResult::ok(['key' => $item['key']], '« ' . $item['label'] . ' » supprimé définitivement.')->refresh();
    }

    /** Restauration groupée : ids[] = clés « source:module:id ». */
    public function restoreMany(Request $request, array $params): ActionResult
    {
        return $this->batch($this->requestedKeys($request), 'restore');
    }

    /** Suppression définitive groupée : ids[] = clés « source:module:id ». */
    public function purgeMany(Request $request, array $params): ActionResult
    {
        return $this->batch($this->requestedKeys($request), 'purge');
    }

    /**
     * Vide la corbeille : purge tout ce que l'utilisateur voit et peut purger.
     * Confirmation forte : le champ « confirm » doit contenir le mot VIDER.
     */
    public function empty(Request $request, array $params): ActionResult
    {
        $this->require('purge-all');
        if (mb_strtoupper($request->string('confirm'), 'UTF-8') !== self::EMPTY_CONFIRM_WORD) {
            throw new ValidationException(['confirm' => 'Tapez ' . self::EMPTY_CONFIRM_WORD . ' pour confirmer la suppression définitive.']);
        }
        $items = array_values(array_filter($this->aggregator()->collect()['items'], static fn (array $i): bool => $i['can_purge']));
        if ($items === []) {
            return ActionResult::info(null, 'La corbeille ne contient aucun élément à supprimer.');
        }
        $report = $this->apply($items, 'purge');
        $this->log(
            'trash.empty',
            $report['failed'] === 0 ? 'success' : 'failure',
            null,
            'Corbeille vidée : ' . $this->plural($report['done'], 'élément supprimé', 'éléments supprimés') . ($report['failed'] > 0 ? ', ' . $this->plural($report['failed'], 'échec') : ''),
            ['done' => $report['done'], 'failed' => $report['failed'], 'errors' => array_slice($report['errors'], 0, 20)],
            'data'
        );
        $message = $this->plural($report['done'], 'élément supprimé définitivement', 'éléments supprimés définitivement');
        if ($report['failed'] > 0) {
            return ActionResult::warning($report, $message . ', ' . $this->plural($report['failed'], 'échec') . ' : ' . implode(' ', array_slice($report['errors'], 0, 3)))->refresh();
        }
        return ActionResult::ok($report, 'Corbeille vidée : ' . $message . '.')->refresh();
    }

    /** Indicateur de la colonne de gauche (GET) : nombre d'éléments visibles. */
    public function badge(Request $request, array $params): array
    {
        $count = count($this->aggregator()->collect()['items']);
        return ['count' => $count, 'label' => $this->plural($count, 'élément') . ' dans la corbeille'];
    }

    // =====================================================================
    // Hooks
    // =====================================================================

    /**
     * Rétention (console maintenance:purge) : rien à purger directement, chaque fournisseur et le
     * noyau appliquent leur propre rétention. On rend compte de ce qui reste malgré tout.
     */
    public function purge(): string
    {
        $expired = $this->aggregator()->countExpired();
        return 'rien à purger directement (les modules et le noyau appliquent leur rétention) ; '
            . $this->plural($expired, 'élément expiré encore présent', 'éléments expirés encore présents');
    }

    // =====================================================================
    // Helpers privés
    // =====================================================================

    private function aggregator(): TrashAggregator
    {
        return $this->aggregator ??= new TrashAggregator($this->ctx, new TrashQueries($this->ctx->db), $this->id(), $this->retentionDays());
    }

    private function retentionDays(): int
    {
        return max(1, $this->ctx->config->int('trash.retention_days', 30));
    }

    /** Élément visé par une action unitaire, ou NotFoundException s'il n'est pas visible. */
    private function requireItem(Request $request): array
    {
        $key = $request->string('key');
        if ($key === '') {
            $key = TrashAggregator::key($request->string('source'), $request->string('module'), $request->string('id'));
        }
        if (TrashAggregator::parseKey($key) === null) {
            throw new ValidationException(['id' => 'Identifiant d’élément invalide.']);
        }
        $item = $this->aggregator()->find($key);
        if ($item === null) {
            throw new NotFoundException('Cet élément n’est pas (ou plus) dans la corbeille.');
        }
        return $item;
    }

    /**
     * Clés demandées par une action groupée : tableau ids[] ou chaîne séparée par des virgules.
     *
     * @return list<string>
     */
    private function requestedKeys(Request $request): array
    {
        $raw = $request->input('ids', []);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $keys = [];
        foreach ($raw as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $keys[] = trim((string) $value);
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * Action groupée : continue malgré les échecs individuels et rend un compte rendu.
     *
     * @param list<string> $keys
     */
    private function batch(array $keys, string $operation): ActionResult
    {
        if ($keys === []) {
            return ActionResult::warning(null, 'Aucun élément sélectionné.');
        }
        $this->aggregator()->collect(true);
        $items = [];
        $missing = 0;
        foreach ($keys as $key) {
            $item = $this->aggregator()->find($key);
            if ($item === null) {
                $missing++;
                continue;
            }
            $items[] = $item;
        }
        $report = $this->apply($items, $operation);
        $report['failed'] += $missing;
        if ($missing > 0) {
            $report['errors'][] = $this->plural($missing, 'élément introuvable', 'éléments introuvables') . ' dans la corbeille.';
        }

        $verb = $operation === 'restore' ? ['élément restauré', 'éléments restaurés'] : ['élément supprimé définitivement', 'éléments supprimés définitivement'];
        $message = $this->plural($report['done'], $verb[0], $verb[1]);
        if ($report['failed'] > 0) {
            $message .= ', ' . $this->plural($report['failed'], 'échec') . ' : ' . implode(' ', array_slice($report['errors'], 0, 3));
            return ActionResult::warning($report, $message)->refresh();
        }
        return ActionResult::ok($report, $message . '.')->refresh();
    }

    /**
     * Applique restore|purge à chaque élément, journalise, et compte succès et échecs.
     *
     * @param list<array<string, mixed>> $items
     * @return array{done: int, failed: int, errors: list<string>}
     */
    private function apply(array $items, string $operation): array
    {
        $report = ['done' => 0, 'failed' => 0, 'errors' => []];
        $action = $operation === 'restore' ? 'trash.restore' : 'trash.purge';
        foreach ($items as $item) {
            try {
                if ($operation === 'restore') {
                    $this->aggregator()->restore($item);
                } else {
                    $this->aggregator()->purge($item);
                }
                $report['done']++;
                $this->log($action, 'success', $item['key'], ($operation === 'restore' ? 'Élément restauré : ' : 'Élément supprimé définitivement : ') . $item['label'], $this->details($item), 'data');
            } catch (\Throwable $e) {
                $report['failed']++;
                $report['errors'][] = '« ' . $item['label'] . ' » : ' . ($e->getMessage() !== '' ? $e->getMessage() : 'erreur inattendue');
                $this->ctx->logger->warning('Corbeille : échec ' . $operation, ['key' => $item['key'], 'error' => $e->getMessage()]);
                $this->log($action, 'failure', $item['key'], 'Échec (' . $operation . ') : ' . $item['label'], $this->details($item) + ['error' => $e->getMessage()], 'data');
            }
        }
        return $report;
    }

    /** @return array<string, mixed> détails journalisés (sans contenu privé) */
    private function details(array $item): array
    {
        return ['source' => $item['source'], 'module' => $item['module_id'], 'dataset' => $item['dataset'], 'deleted_at' => $item['deleted_at']];
    }

    private function banner(string $title, string $subtitle, string $actions = ''): string
    {
        return $this->renderCore('banner', ['icon' => 'trash', 'title' => $title, 'subtitle' => $subtitle, 'actions' => $actions]);
    }

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        $plural ??= $singular . 's';
        return $count . ' ' . ($count === 1 ? $singular : $plural);
    }
}
