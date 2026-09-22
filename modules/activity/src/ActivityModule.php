<?php

declare(strict_types=1);

namespace Atelier\Modules\Activity;

use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Support\Clock;
use Atelier\Support\Json;

/**
 * Journal d'activité : consultation paginée, filtrée et triée des entrées enregistrées par le noyau
 * et les modules, détail d'une entrée et export CSV. Les entrées ne sont jamais modifiables.
 */
final class ActivityModule extends AbstractModule
{
    private const EXPORT_LIMIT = 10000;
    private const EXPORT_RESOURCE = 'action/export';
    private const PURGE_RESOURCE = 'action/purge';

    /** Libellés et couleurs des résultats. */
    public const RESULT_LABELS = [
        'success' => ['Succès', 'success'],
        'failure' => ['Échec', 'danger'],
        'denied' => ['Refusé', 'warning'],
        'error' => ['Erreur', 'danger'],
    ];

    public function routes(RouteCollection $r): void
    {
        $r->view('list', [$this, 'list'], permission: 'open');
        $r->view('detail/{id}', [$this, 'detail'], permission: 'open');
        $r->action('filter', [$this, 'filter'], permission: 'open');
        $r->raw('export.csv', [$this, 'export'], permission: 'export', resource: self::EXPORT_RESOURCE);
        $r->action('purge-entries', [$this, 'purgeEntries'], permission: 'admin', resource: self::PURGE_RESOURCE);
        $r->action('purge-preview', [$this, 'purgePreview'], permission: 'admin', resource: self::PURGE_RESOURCE);
    }

    // ----- Vues -----

    public function list(Request $request, array $params): ModuleView
    {
        $filters = $this->filtersFrom($request->allQuery());
        $result = $this->ctx->activity->paginate(
            $filters->toRepository(),
            $filters->page(),
            $filters->perPage(),
            $filters->sort(),
            $filters->direction()
        );
        $total = (int) $result['total'];
        $canExport = $this->can('export', self::EXPORT_RESOURCE);

        $content = $this->render('list', [
            'rows' => $result['rows'],
            'total' => $total,
            'filters' => $filters,
            'users' => $this->ctx->users->all(),
            'modules' => $this->ctx->activity->distinctModules(),
            'actions' => $this->ctx->activity->distinctActions(),
            'results' => self::RESULT_LABELS,
            'perPageOptions' => ActivityFilters::PER_PAGE_OPTIONS,
            'canPurge' => $this->can('admin', self::PURGE_RESOURCE),
        ]);

        $actions = '';
        if ($canExport) {
            $actions .= '<a class="btn" href="' . $this->e($this->url($filters->route('export.csv'))) . '" download title="Exporter les entrées filtrées (10 000 lignes au plus)">'
                . '<svg class="icon" aria-hidden="true"><use href="#i-download"></use></svg> Exporter CSV</a> ';
        }
        $actions .= '<a class="btn" href="#" data-route="' . $this->e($filters->route()) . '" title="Recharger la liste">'
            . '<svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Actualiser</a>';

        $banner = $this->renderCore('banner', [
            'icon' => 'activity',
            'title' => 'Journal d’activité',
            'subtitle' => $this->countLabel($total, $filters->isActive()),
            'actions' => $actions,
        ]);

        return ModuleView::make('Journal d’activité')
            ->banner($banner)
            ->content($content)
            ->status($this->countLabel($total, $filters->isActive()));
    }

    public function detail(Request $request, array $params): ModuleView
    {
        $id = (int) ($params['id'] ?? 0);
        $entry = $id > 0 ? $this->ctx->activity->find($id) : null;
        if ($entry === null) {
            throw new NotFoundException('Entrée du journal introuvable.');
        }

        $detailsPretty = null;
        if (!empty($entry['details'])) {
            try {
                $detailsPretty = Json::encode(Json::decode((string) $entry['details']), true);
            } catch (\Throwable) {
                $detailsPretty = (string) $entry['details'];
            }
        }

        $content = $this->render('detail', [
            'entry' => $entry,
            'detailsPretty' => $detailsPretty,
            'results' => self::RESULT_LABELS,
            'occurredAt' => Clock::formatDateTimeSeconds($entry['occurred_at'] ?? null, '—'),
        ]);

        $actions = '<a class="btn" href="#" data-route="list">'
            . '<svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg> Retour au journal</a>';
        $banner = $this->renderCore('banner', [
            'icon' => 'activity',
            'title' => 'Entrée n° ' . $id,
            'subtitle' => (string) $entry['module_id'] . ' · ' . (string) $entry['action'],
            'actions' => $actions,
        ]);

        return ModuleView::make('Journal – entrée ' . $id)
            ->banner($banner)
            ->content($content)
            ->status('Entrée n° ' . $id . ' du ' . Clock::formatDateTime($entry['occurred_at'] ?? null));
    }

    // ----- Actions -----

    /** Soumission du formulaire de filtres : redirige vers la liste avec la chaîne de requête correspondante. */
    /**
     * Purge ciblée (administration) : les critères du formulaire de purge (catégorie, résultat, module,
     * ancienneté minimale en jours) ; les filtres courants de la liste ne sont pas utilisés pour éviter
     * une purge accidentelle. Le mot « PURGER » doit être saisi. La purge est elle-même journalisée.
     */
    public function purgeEntries(Request $request, array $params): ActionResult
    {
        $this->require('admin', self::PURGE_RESOURCE, 'Vous n’êtes pas autorisé à purger le journal.');
        [$filters, $days] = $this->purgeCriteria($request);
        if (strtoupper($request->string('confirm')) !== 'PURGER') {
            throw new ValidationException(['confirm' => 'Tapez PURGER pour confirmer la suppression définitive.']);
        }
        if ($filters === [] && $days === null) {
            throw new ValidationException(['older_than' => 'Indiquez au moins un critère (catégorie, résultat, module ou ancienneté).']);
        }
        $count = $this->ctx->activity->purgeBy($filters, $days);
        $this->log('activity.purge', 'success', 'activity_log', sprintf('%d entrée(s) du journal purgée(s)', $count), ['criteria' => $filters, 'older_than_days' => $days, 'deleted' => $count], 'admin');
        return ActionResult::ok(['deleted' => $count], sprintf('%d entrée(s) supprimée(s) du journal.', $count))->refresh();
    }

    /** Aperçu du nombre d'entrées concernées par les critères de purge. */
    public function purgePreview(Request $request, array $params): ActionResult
    {
        $this->require('admin', self::PURGE_RESOURCE);
        [$filters, $days] = $this->purgeCriteria($request);
        $count = $filters === [] && $days === null ? 0 : $this->ctx->activity->countBy($filters, $days);
        return ActionResult::info(['count' => $count], sprintf('%d entrée(s) correspondent à ces critères.', $count));
    }

    /** @return array{0: array<string, mixed>, 1: ?int} */
    private function purgeCriteria(Request $request): array
    {
        $filters = [];
        $category = $request->string('purge_category');
        if (in_array($category, \Atelier\Activity\ActivityLog::CATEGORIES, true)) {
            $filters['category'] = $category;
        }
        $result = $request->string('purge_result');
        if (in_array($result, ActivityFilters::RESULTS, true)) {
            $filters['result'] = $result;
        }
        $module = $request->string('purge_module');
        if ($module !== '' && \Atelier\Support\Str::isSlug($module)) {
            $filters['module_id'] = $module;
        }
        $days = $request->int('older_than');
        $days = $days !== null && $days > 0 ? min(3650, $days) : null;
        return [$filters, $days];
    }

    public function filter(Request $request, array $params): ActionResult
    {
        $input = $request->all();
        unset($input['page']); // tout changement de filtre revient à la première page
        $filters = $this->filtersFrom($input);
        return ActionResult::ok()->navigate($filters->route());
    }

    // ----- Export -----

    public function export(Request $request, array $params): Response
    {
        $this->require('export', self::EXPORT_RESOURCE, 'Vous n’êtes pas autorisé à exporter le journal.');

        $filters = $this->filtersFrom($request->allQuery());
        // L'export suit les filtres et le tri affichés ; ActivityLog::export() n'accepte pas le tri,
        // on utilise donc paginate() sur une seule page plafonnée à EXPORT_LIMIT lignes.
        $rows = $this->ctx->activity->paginate($filters->toRepository(), 1, self::EXPORT_LIMIT, $filters->sort(), $filters->direction())['rows'];

        $csv = $this->buildCsv($rows);
        $fileName = 'journal-activite-' . Clock::now()->setTimezone(new \DateTimeZone($this->timezone()))->format('Ymd-His') . '.csv';

        $this->log('activity.export', 'success', 'activity_log', sprintf('Export CSV de %d entrée(s)', count($rows)), [
            'count' => count($rows),
            'truncated' => count($rows) >= self::EXPORT_LIMIT,
            'filters' => $filters->toQuery(),
        ]);

        return Response::raw($csv, 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->withHeader('Content-Length', (string) strlen($csv))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    // ----- Helpers -----

    /** @param array<string, mixed> $input */
    private function filtersFrom(array $input): ActivityFilters
    {
        $default = (int) $this->ctx->settings->preference($this->ctx->userId(), 'pageSize', 25);
        return ActivityFilters::fromArray($input, $this->timezone(), $default);
    }

    private function timezone(): string
    {
        return $this->ctx->config->string('app.timezone', 'Europe/Paris');
    }

    private function countLabel(int $total, bool $filtered): string
    {
        $label = $total === 1 ? '1 entrée' : number_format($total, 0, ',', ' ') . ' entrées';
        return $filtered ? $label . ' (filtrées)' : $label;
    }

    /**
     * CSV « Excel-compatible » : BOM UTF-8, séparateur point-virgule, fins de ligne CRLF.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function buildCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de préparer l’export.');
        }
        $write = static function (array $fields) use ($handle): void {
            fputcsv($handle, $fields, ';', '"', '', "\r\n");
        };
        $write(['ID', 'Date', 'Utilisateur', 'ID utilisateur', 'Module', 'Action', 'Résultat', 'Ressource', 'Message', 'Adresse IP', 'Référence erreur', 'Détails']);
        foreach ($rows as $row) {
            $write([
                (string) $row['id'],
                Clock::formatDateTimeSeconds($row['occurred_at'] ?? null),
                (string) ($row['username'] ?? ''),
                $row['user_id'] === null ? '' : (string) $row['user_id'],
                (string) $row['module_id'],
                (string) $row['action'],
                self::RESULT_LABELS[$row['result']][0] ?? (string) $row['result'],
                (string) ($row['resource_ref'] ?? ''),
                (string) ($row['message'] ?? ''),
                (string) ($row['ip'] ?? ''),
                (string) ($row['error_id'] ?? ''),
                (string) ($row['details'] ?? ''),
            ]);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return "\xEF\xBB\xBF" . $csv;
    }
}
