<?php

declare(strict_types=1);

namespace Atelier\Modules\Maintenance;

use Atelier\Error\ForbiddenException;
use Atelier\Error\ModuleUnavailableException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleContext;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Modules\TrashProviderInterface;
use Atelier\Shared\TagService;
use Atelier\Support\Clock;
use Atelier\Support\Str;
use Atelier\View\BbCode;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Module « Entretien » : GMAO domestique.
 *
 *  - équipements (voiture, chaudière, électroménager…) avec compteur éventuel (km, heures) ;
 *  - tâches d'entretien planifiées : périodicité par jours et/ou par compteur, prochaine échéance,
 *    rappel anticipé propre à chaque tâche (badge de la colonne, tableau de bord, calendrier iCalendar) ;
 *  - pannes et défauts (tâches correctives, non périodiques) ;
 *  - fiche d'intervention par tâche (descriptif, pièces, contacts, outillage, durée et coût estimés),
 *    imprimable ;
 *  - historique des interventions (date, compteur, coût, intervenant) ;
 *  - pièces jointes (factures, notices, photos) sur les équipements, les tâches et les interventions,
 *    via le mécanisme commun et le module Fichiers joints ; tags partagés ;
 *  - corbeille : équipements, tâches et interventions sont supprimés logiquement (deleted_at), restaurables
 *    depuis la corbeille du module ou la corbeille globale (TrashProviderInterface), purgés après
 *    trash.retention_days.
 */
final class MaintenanceModule extends AbstractModule implements TrashProviderInterface
{
    public const DATASET_ASSET = 'maintenance.asset';
    public const DATASET_JOB = 'maintenance.job';
    public const DATASET_LOG = 'maintenance.log';
    public const DATASETS = [self::DATASET_ASSET, self::DATASET_JOB, self::DATASET_LOG];

    private const EXPORT_RESOURCE = 'action/export';
    /** Préfixes des identifiants de corbeille (« asset:12 », « job:5 », « log:9 »). */
    private const TRASH_TYPES = ['asset' => self::DATASET_ASSET, 'job' => self::DATASET_JOB, 'log' => self::DATASET_LOG];
    private const PER_PAGE_CHOICES = [25, 50, 100];
    private const NAME_MAX = 150;
    private const SHORT_MAX = 100;
    private const TEXT_MAX = 20000;
    private const METER_MAX = 99999999;
    private const COST_MAX = 100000000; // 1 000 000 € en centimes
    private const DEFAULT_LEAD_DAYS = 14;
    private const DEFAULT_LEAD_METER = ['km' => 500, 'h' => 20, 'cycles' => 20];

    private ?AssetRepository $assets = null;
    private ?JobRepository $jobs = null;
    private ?LogRepository $logs = null;
    private ?MaintenanceService $serviceInstance = null;

    public function boot(ModuleContext $context): void
    {
        parent::boot($context);
        $this->assets = null;
        $this->jobs = null;
        $this->logs = null;
        $this->serviceInstance = null;
    }

    public function routes(RouteCollection $r): void
    {
        // Vues : les motifs fixes (asset/new) précèdent les motifs paramétrés (asset/{id}).
        $r->view('dashboard', [$this, 'dashboard'], permission: 'open');
        $r->view('assets', [$this, 'assets'], permission: 'open');
        $r->view('asset/new', [$this, 'assetNew'], permission: 'create');
        $r->view('asset/{id}', [$this, 'assetShow'], permission: 'open');
        $r->view('asset/{id}/edit', [$this, 'assetEdit'], permission: 'update');
        $r->view('jobs', [$this, 'jobs'], permission: 'open');
        $r->view('defects', [$this, 'defects'], permission: 'open');
        $r->view('job/new', [$this, 'jobNew'], permission: 'create');
        $r->view('job/{id}', [$this, 'jobShow'], permission: 'open');
        $r->view('job/{id}/edit', [$this, 'jobEdit'], permission: 'update');
        $r->view('history', [$this, 'history'], permission: 'open');
        $r->view('log/new', [$this, 'logNew'], permission: 'create');
        $r->view('log/{id}/edit', [$this, 'logEdit'], permission: 'update');
        $r->view('trash', [$this, 'trash'], permission: 'delete');

        // Actions.
        $r->action('badge', [$this, 'badge'], permission: 'open', methods: ['GET']);
        $r->action('filter-assets', [$this, 'filterAssets'], permission: 'open');
        $r->action('filter-jobs', [$this, 'filterJobs'], permission: 'open');
        $r->action('filter-history', [$this, 'filterHistory'], permission: 'open');
        $r->action('asset-save', [$this, 'assetSave'], permission: 'open');   // create ou update vérifié dans le gestionnaire
        $r->action('asset-meter', [$this, 'assetMeter'], permission: 'update');
        $r->action('asset-delete', [$this, 'assetDelete'], permission: 'delete');
        $r->action('asset-restore', [$this, 'assetRestore'], permission: 'delete');
        $r->action('asset-purge', [$this, 'assetPurge'], permission: 'delete');
        $r->action('trash-restore', [$this, 'trashRestore'], permission: 'update');
        $r->action('trash-purge', [$this, 'trashPurge'], permission: 'delete');
        $r->action('job-save', [$this, 'jobSave'], permission: 'open');
        $r->action('job-close', [$this, 'jobClose'], permission: 'update');
        $r->action('job-reopen', [$this, 'jobReopen'], permission: 'update');
        $r->action('job-delete', [$this, 'jobDelete'], permission: 'delete');
        $r->action('log-save', [$this, 'logSave'], permission: 'open');
        $r->action('log-delete', [$this, 'logDelete'], permission: 'delete');
        $r->action('attach', [$this, 'attach'], permission: 'update');
        $r->action('attachment-delete', [$this, 'attachmentDelete'], permission: 'update');

        // Routes brutes.
        $r->raw('job/{id}/print', [$this, 'jobPrint'], permission: 'open');
        $r->raw('export.csv', [$this, 'exportCsv'], permission: 'export', resource: self::EXPORT_RESOURCE);
        $r->raw('reminders.ics', [$this, 'exportIcs'], permission: 'export', resource: self::EXPORT_RESOURCE);
    }

    public function service(): ?object
    {
        return $this->serviceInstance ??= new MaintenanceService($this->ctx, $this->assetRepo(), $this->jobRepo(), $this->today());
    }

    /** Données de démonstration : une voiture et une chaudière avec leurs entretiens, si la table est vide. */
    public function seed(): string
    {
        if ($this->assetRepo()->countAll() > 0) {
            return 'équipements déjà présents';
        }
        $userId = $this->ctx->auth->userId();
        $today = $this->today();
        $day = static fn (int $offsetDays): string => $today->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days')->format('Y-m-d');

        $car = $this->assetRepo()->create([
            'name' => 'Voiture familiale', 'category' => 'vehicle', 'brand' => 'Peugeot', 'model' => '308 SW', 'identifier' => 'AB-123-CD',
            'acquired_at' => $day(-900), 'meter_unit' => 'km', 'meter_value' => 61200, 'location' => 'Garage',
            'notes' => "Carnet d’entretien dans la boîte à gants.\n[b]Pneus[/b] : 205/55 R16, pression 2,3 bar.",
        ], $userId);
        $this->registerAsset($car, 'Voiture familiale');
        $boiler = $this->assetRepo()->create([
            'name' => 'Chaudière gaz', 'category' => 'heating', 'brand' => 'Saunier Duval', 'model' => 'ThemaPlus Condens', 'identifier' => 'SN 2019-4471',
            'acquired_at' => $day(-2200), 'meter_unit' => null, 'meter_value' => null, 'location' => 'Buanderie', 'notes' => null,
        ], $userId);
        $this->registerAsset($boiler, 'Chaudière gaz');

        $jobs = [
            [$car, 'Vidange et filtre à huile', 'preventive', 'normal', "Vidange moteur, remplacement du filtre à huile, contrôle des niveaux.", "Huile 5W30 (4,3 l)\nFiltre à huile\nJoint de bouchon de vidange", "Garage Martin — 04 00 00 00 00", "Clé à filtre\nBac de récupération", 60, 12000, 365, 15000, $day(-320), 60000 + 15000 - 800, 14, 500, $day(-320), 46200],
            [$car, 'Contrôle technique', 'preventive', 'high', "Contrôle technique réglementaire (tous les 2 ans).", null, "Centre de contrôle — prendre rendez-vous en ligne", null, 45, 8000, 730, null, $day(20), null, 30, null, $day(-710), null],
            [$car, 'Remplacement des plaquettes de frein avant', 'preventive', 'normal', "Contrôler l’épaisseur des plaquettes ; remplacer si < 3 mm.", "Jeu de plaquettes avant\nGraisse cuivre", "Garage Martin", "Cric, chandelles\nClé de 13", 90, 9000, null, 40000, null, 80000, 14, 1000, null, null],
            [$boiler, 'Entretien annuel de la chaudière', 'preventive', 'high', "Entretien obligatoire annuel : nettoyage du corps de chauffe, contrôle de combustion, attestation.", null, "Chauffagiste Durand — 04 00 00 00 01\nContrat d’entretien n° 2024-118", null, 90, 15000, 365, null, $day(-5), null, 30, null, $day(-370), null],
            [$boiler, 'Bruit de claquement au démarrage', 'corrective', 'urgent', "Claquement métallique à chaque démarrage du brûleur depuis trois jours. Pression du circuit à 0,8 bar.", null, "Chauffagiste Durand", null, null, null, null, null, $day(3), null, 7, null, null, null],
        ];
        foreach ($jobs as [$assetId, $title, $kind, $priority, $description, $parts, $contacts, $tools, $minutes, $cost, $intervalDays, $intervalMeter, $nextDueAt, $nextDueMeter, $leadDays, $leadMeter, $lastDoneAt, $lastDoneMeter]) {
            $jobId = $this->jobRepo()->create([
                'asset_id' => $assetId, 'title' => $title, 'kind' => $kind, 'priority' => $priority, 'description' => $description,
                'parts' => $parts, 'contacts' => $contacts, 'tools' => $tools, 'estimated_minutes' => $minutes, 'estimated_cost' => $cost,
                'interval_days' => $intervalDays, 'interval_meter' => $intervalMeter, 'next_due_at' => $nextDueAt, 'next_due_meter' => $nextDueMeter,
                'lead_days' => $leadDays, 'lead_meter' => $leadMeter,
            ], $userId);
            if ($lastDoneAt !== null) {
                $this->ctx->db->update(JobRepository::TABLE, ['last_done_at' => $lastDoneAt, 'last_done_meter' => $lastDoneMeter], 'id = :id', ['id' => $jobId]);
                $logId = $this->logRepo()->create(['asset_id' => $assetId, 'job_id' => $jobId, 'done_at' => $lastDoneAt, 'meter_value' => $lastDoneMeter, 'title' => $title, 'notes' => 'Réalisé selon la fiche.', 'cost' => $cost, 'performed_by' => $assetId === $car ? 'Garage Martin' : 'Chauffagiste Durand'], $userId);
                $this->registerLog($logId, $title, $lastDoneAt, $assetId === $car ? 'Voiture familiale' : 'Chaudière gaz');
            }
            $this->registerJob($jobId, $title, $assetId === $car ? 'Voiture familiale' : 'Chaudière gaz');
        }
        return '2 équipements, ' . count($jobs) . ' tâches et 3 interventions d’exemple créés';
    }

    /** Rétention de la corbeille (trash.retention_days) : équipements, tâches et interventions expirés. */
    public function purge(): string
    {
        $days = $this->retentionDays();
        $counts = ['log' => 0, 'job' => 0, 'asset' => 0];
        foreach ($this->logRepo()->expiredTrashIds($days) as $id) {
            $this->purgeLog($id);
            $this->log('maintenance.log_purge', 'success', 'maintenance_log:' . $id, 'Intervention purgée par la rétention (' . $days . ' jours)');
            $counts['log']++;
        }
        foreach ($this->jobRepo()->expiredTrashIds($days) as $id) {
            $this->purgeJob($id);
            $this->log('maintenance.job_purge', 'success', 'maintenance_job:' . $id, 'Tâche purgée par la rétention (' . $days . ' jours)');
            $counts['job']++;
        }
        foreach ($this->assetRepo()->expiredTrashIds($days) as $id) {
            $this->destroyAsset($id);
            $this->log('maintenance.asset_purge', 'success', 'maintenance_asset:' . $id, 'Équipement purgé par la rétention (' . $days . ' jours)');
            $counts['asset']++;
        }
        return sprintf('%d équipement(s), %d tâche(s) et %d intervention(s) purgé(s) de la corbeille (> %d jours)', $counts['asset'], $counts['job'], $counts['log'], $days);
    }

    // =====================================================================
    // Vues
    // =====================================================================

    public function dashboard(Request $request, array $params): ModuleView
    {
        $jobs = $this->decorateJobs($this->jobRepo()->open());
        $overdue = array_values(array_filter($jobs, static fn (array $j): bool => in_array($j['state']['code'], [Scheduler::DUE, Scheduler::OVERDUE], true)));
        $soon = array_values(array_filter($jobs, static fn (array $j): bool => $j['state']['code'] === Scheduler::SOON));
        $defects = array_values(array_filter($jobs, static fn (array $j): bool => $j['kind'] === 'corrective'));
        $alerts = count($overdue) + count($soon);
        $yearAgo = $this->today()->modify('-12 months')->format('Y-m-d');

        $content = $this->render('dashboard', [
            'overdue' => $overdue,
            'soon' => $soon,
            'defects' => $defects,
            'recent' => $this->logRepo()->recent(8),
            'stats' => [
                'assets' => $this->assetRepo()->countActive(),
                'openJobs' => count($jobs),
                'alerts' => $alerts,
                'defects' => count($defects),
                'cost12' => $this->logRepo()->costSince($yearAgo),
            ],
            'rights' => $this->rights(['create', 'update', 'export']),
            'canExport' => $this->can('export', self::EXPORT_RESOURCE),
            'today' => $this->today()->format('Y-m-d'),
        ]);
        $subtitle = $alerts === 0 ? 'Aucun rappel en cours' : $alerts . ' rappel' . ($alerts > 1 ? 's' : '') . ' d’entretien (' . count($overdue) . ' à faire, ' . count($soon) . ' bientôt)';
        $banner = $this->renderCore('banner', ['icon' => 'tool', 'title' => 'Entretien', 'subtitle' => $subtitle, 'actions' => $this->navLinks('dashboard')]);
        return ModuleView::make('Entretien')->banner($banner)->content($content)->status($subtitle)->state(['alerts' => $alerts]);
    }

    public function assets(Request $request, array $params): ModuleView
    {
        $query = $this->assetsQuery($request->allQuery());
        $result = $this->assetRepo()->paginate(['q' => $query['q'], 'category' => $query['category']], $query['page'], $query['per_page'], $query['sort'], $query['dir']);
        $alertsByAsset = [];
        $openByAsset = [];
        foreach ($this->decorateJobs($this->jobRepo()->open()) as $job) {
            $openByAsset[$job['asset_id']] = ($openByAsset[$job['asset_id']] ?? 0) + 1;
            if (in_array($job['state']['code'], Scheduler::ALERTING, true)) {
                $alertsByAsset[$job['asset_id']] = max($alertsByAsset[$job['asset_id']] ?? 0, Scheduler::SEVERITY[$job['state']['code']]);
            }
        }
        $rights = $this->rights(['create', 'update', 'delete']);
        $content = $this->render('assets', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'query' => $query,
            'categories' => AssetRepository::CATEGORIES,
            'openByAsset' => $openByAsset,
            'alertsByAsset' => $alertsByAsset,
            'rights' => $rights,
            'perPageChoices' => self::PER_PAGE_CHOICES,
        ]);
        $actions = $this->navLinks('assets');
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="asset/new">' . $this->icon('plus') . '<span>Nouvel équipement</span></a>';
        }
        $subtitle = $this->countLabel($result['total'], 'équipement', $query['q'] !== '' || $query['category'] !== '');
        $banner = $this->renderCore('banner', ['icon' => 'layers', 'title' => 'Équipements', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Équipements')->banner($banner)->content($content)->status($subtitle)->route($this->assetsRoute($query));
    }

    public function assetNew(Request $request, array $params): ModuleView
    {
        $asset = ['id' => null, 'name' => '', 'category' => 'vehicle', 'brand' => '', 'model' => '', 'identifier' => '', 'acquired_at' => '', 'meter_unit' => 'km', 'meter_value' => null, 'location' => '', 'notes' => ''];
        $content = $this->render('asset_form', ['asset' => $asset, 'isNew' => true, 'tags' => [], 'categories' => AssetRepository::CATEGORIES, 'meterUnits' => AssetRepository::METER_UNITS]);
        $banner = $this->renderCore('banner', ['icon' => 'layers', 'title' => 'Nouvel équipement', 'subtitle' => 'Voiture, chaudière, électroménager…', 'actions' => $this->backLink('assets', 'Équipements')]);
        return ModuleView::make('Nouvel équipement')->banner($banner)->content($content)->status('Création d’un équipement');
    }

    public function assetEdit(Request $request, array $params): ModuleView
    {
        $asset = $this->requireAsset((int) ($params['id'] ?? 0));
        $content = $this->render('asset_form', ['asset' => $asset, 'isNew' => false, 'tags' => $this->tagNames(self::DATASET_ASSET, $asset['id']), 'categories' => AssetRepository::CATEGORIES, 'meterUnits' => AssetRepository::METER_UNITS]);
        $banner = $this->renderCore('banner', ['icon' => 'layers', 'title' => (string) $asset['name'], 'subtitle' => 'Modification', 'actions' => $this->backLink('asset/' . $asset['id'], 'Fiche de l’équipement')]);
        return ModuleView::make('Équipement · ' . $asset['name'])->banner($banner)->content($content)->status('Modification de « ' . $asset['name'] . ' »');
    }

    public function assetShow(Request $request, array $params): ModuleView
    {
        $asset = $this->requireAsset((int) ($params['id'] ?? 0));
        $id = $asset['id'];
        $rights = $this->rights(['create', 'update', 'delete']);
        $jobs = $this->decorateJobs($this->jobRepo()->forAsset($id));
        $logs = $this->logRepo()->forAsset($id, 100);
        $alerts = count(array_filter($jobs, static fn (array $j): bool => in_array($j['state']['code'], Scheduler::ALERTING, true)));
        $info = $this->ctx->shared->registry->find(self::DATASET_ASSET, (string) $id);
        $infoId = $info === null ? null : (string) $info['id'];

        $content = $this->render('asset_show', [
            'asset' => $asset,
            'infoId' => $infoId,
            'jobs' => $jobs,
            'logs' => $logs,
            'logAttachments' => $this->attachmentCounts(self::DATASET_LOG, array_map(static fn (array $l): int => $l['id'], $logs)),
            'tags' => $infoId === null ? [] : $this->ctx->shared->tags->tagsOf($infoId),
            'attachments' => $infoId === null ? [] : $this->ctx->shared->attachments->listFor($infoId),
            'rights' => $rights,
            'author' => $asset['created_by'] !== null ? $this->ctx->users->find($asset['created_by']) : null,
            'attachmentsModule' => $this->ctx->modules()->has('attachments'),
            'categories' => AssetRepository::CATEGORIES,
            'meterUnits' => AssetRepository::METER_UNITS,
        ]);

        $actions = $this->backLink('assets', 'Équipements');
        if ($rights['create']) {
            $actions .= '<a class="btn" href="#" data-route="job/new?asset=' . $id . '">' . $this->icon('clock') . '<span>Planifier un entretien</span></a>';
            $actions .= '<a class="btn" href="#" data-route="job/new?asset=' . $id . '&kind=corrective">' . $this->icon('warning') . '<span>Signaler une panne</span></a>';
            $actions .= '<a class="btn" href="#" data-route="log/new?asset=' . $id . '">' . $this->icon('check') . '<span>Intervention réalisée</span></a>';
        }
        if ($rights['update']) {
            $actions .= '<a class="btn" href="#" data-route="asset/' . $id . '/edit">' . $this->icon('edit') . '<span>Modifier</span></a>';
        }
        if ($rights['delete']) {
            $actions .= '<button type="button" class="btn btn--outline-danger" data-action="asset-delete" data-params=\'{"id":' . $id . '}\' data-confirm="Mettre cet équipement à la corbeille ? Ses tâches et son historique sont conservés jusqu’à la purge." data-danger>' . $this->icon('trash') . '<span>Supprimer</span></button>';
        }
        $subtitle = (AssetRepository::CATEGORIES[$asset['category']] ?? $asset['category']) . ($asset['meter_value'] !== null ? ' · ' . $this->meter($asset['meter_value'], $asset['meter_unit']) : '') . ' · ' . ($alerts === 0 ? 'aucun rappel' : $alerts . ' rappel' . ($alerts > 1 ? 's' : ''));
        $banner = $this->renderCore('banner', ['icon' => $this->categoryIcon((string) $asset['category']), 'title' => (string) $asset['name'], 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Équipement · ' . $asset['name'])->banner($banner)->content($content)->status($asset['name'] . ' · ' . $subtitle)->state(['asset' => $id, 'infoId' => $infoId]);
    }

    public function jobs(Request $request, array $params): ModuleView
    {
        return $this->jobsView($request, 'jobs');
    }

    public function defects(Request $request, array $params): ModuleView
    {
        return $this->jobsView($request, 'defects');
    }

    public function jobNew(Request $request, array $params): ModuleView
    {
        $assetId = (int) $request->query('asset', 0);
        $asset = $assetId > 0 ? $this->assetRepo()->find($assetId) : null;
        $kind = $request->query('kind') === 'corrective' ? 'corrective' : 'preventive';
        $job = [
            'id' => null, 'asset_id' => $asset['id'] ?? 0, 'title' => '', 'kind' => $kind, 'priority' => $kind === 'corrective' ? 'high' : 'normal', 'status' => 'open',
            'description' => '', 'parts' => '', 'contacts' => '', 'tools' => '', 'estimated_minutes' => null, 'estimated_cost' => null,
            'interval_days' => $kind === 'corrective' ? null : 365, 'interval_meter' => null, 'next_due_at' => '', 'next_due_meter' => null,
            'lead_days' => self::DEFAULT_LEAD_DAYS, 'lead_meter' => $asset !== null && $asset['meter_unit'] !== null ? (self::DEFAULT_LEAD_METER[$asset['meter_unit']] ?? null) : null,
        ];
        $content = $this->render('job_form', $this->jobFormVars($job, true, []));
        $title = $kind === 'corrective' ? 'Nouvelle panne' : 'Nouvelle tâche d’entretien';
        $banner = $this->renderCore('banner', ['icon' => $kind === 'corrective' ? 'warning' : 'clock', 'title' => $title, 'subtitle' => $asset !== null ? $asset['name'] : 'Choisissez l’équipement concerné', 'actions' => $this->backLink($asset !== null ? 'asset/' . $asset['id'] : ($kind === 'corrective' ? 'defects' : 'jobs'), 'Retour')]);
        return ModuleView::make($title)->banner($banner)->content($content)->status($title);
    }

    public function jobEdit(Request $request, array $params): ModuleView
    {
        $job = $this->requireJob((int) ($params['id'] ?? 0));
        $content = $this->render('job_form', $this->jobFormVars($job, false, $this->tagNames(self::DATASET_JOB, $job['id'])));
        $banner = $this->renderCore('banner', ['icon' => $job['kind'] === 'corrective' ? 'warning' : 'clock', 'title' => (string) $job['title'], 'subtitle' => 'Modification · ' . $job['asset_name'], 'actions' => $this->backLink('job/' . $job['id'], 'Fiche de la tâche')]);
        return ModuleView::make('Tâche · ' . $job['title'])->banner($banner)->content($content)->status('Modification de « ' . $job['title'] . ' »');
    }

    public function jobShow(Request $request, array $params): ModuleView
    {
        $job = $this->decorateJob($this->requireJob((int) ($params['id'] ?? 0)));
        $id = $job['id'];
        $rights = $this->rights(['create', 'update', 'delete']);
        $info = $this->ctx->shared->registry->find(self::DATASET_JOB, (string) $id);
        $infoId = $info === null ? null : (string) $info['id'];
        $logs = $this->logRepo()->forJob($id);

        $content = $this->render('job_show', [
            'job' => $job,
            'infoId' => $infoId,
            'logs' => $logs,
            'logAttachments' => $this->attachmentCounts(self::DATASET_LOG, array_map(static fn (array $l): int => $l['id'], $logs)),
            'tags' => $infoId === null ? [] : $this->ctx->shared->tags->tagsOf($infoId),
            'attachments' => $infoId === null ? [] : $this->ctx->shared->attachments->listFor($infoId),
            'rights' => $rights,
            'attachmentsModule' => $this->ctx->modules()->has('attachments'),
            'printUrl' => $this->url('job/' . $id . '/print'),
        ]);

        $actions = $this->backLink($job['kind'] === 'corrective' ? 'defects' : 'jobs', 'Liste');
        if ($rights['create'] && $job['status'] === 'open') {
            $actions .= '<a class="btn btn--primary" href="#" data-route="log/new?job=' . $id . '">' . $this->icon('check') . '<span>Marquer comme fait</span></a>';
        }
        if ($rights['update']) {
            $actions .= '<a class="btn" href="#" data-route="job/' . $id . '/edit">' . $this->icon('edit') . '<span>Modifier</span></a>';
            if ($job['status'] === 'open') {
                $actions .= '<button type="button" class="btn" data-action="job-close" data-params=\'{"id":' . $id . '}\' data-confirm="Clôturer cette tâche sans enregistrer d’intervention ? Elle ne déclenchera plus de rappel.">' . $this->icon('close') . '<span>Clôturer</span></button>';
            } else {
                $actions .= '<button type="button" class="btn" data-action="job-reopen" data-params=\'{"id":' . $id . '}\'>' . $this->icon('refresh') . '<span>Rouvrir</span></button>';
            }
        }
        $actions .= '<a class="btn" href="' . $this->e($this->url('job/' . $id . '/print')) . '" target="_blank" rel="noopener" title="Fiche d’intervention imprimable">' . $this->icon('print') . '<span>Imprimer</span></a>';
        if ($rights['delete']) {
            $actions .= '<button type="button" class="btn btn--outline-danger" data-action="job-delete" data-params=\'{"id":' . $id . '}\' data-confirm="Mettre cette tâche à la corbeille ? Les interventions déjà réalisées restent dans l’historique ; la tâche pourra être restaurée." data-danger>' . $this->icon('trash') . '<span>Supprimer</span></button>';
        }
        $subtitle = $job['asset_name'] . ' · ' . $this->stateLabel($job['state']) ;
        $banner = $this->renderCore('banner', ['icon' => $job['kind'] === 'corrective' ? 'warning' : 'clock', 'title' => (string) $job['title'], 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Tâche · ' . $job['title'])->banner($banner)->content($content)->status($subtitle)->state(['job' => $id, 'state' => $job['state']['code']]);
    }

    public function history(Request $request, array $params): ModuleView
    {
        $query = $this->historyQuery($request->allQuery());
        $result = $this->logRepo()->paginate(['asset' => $query['asset'], 'job' => 0, 'q' => $query['q'], 'year' => $query['year']], $query['page'], $query['per_page']);
        $rights = $this->rights(['create', 'update', 'delete']);
        $canExport = $this->can('export', self::EXPORT_RESOURCE);
        $content = $this->render('history', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'cost' => $result['cost'],
            'query' => $query,
            'assets' => $this->assetRepo()->all(),
            'years' => $this->logRepo()->years(),
            'documents' => $this->attachmentsByItem(self::DATASET_LOG, array_map(static fn (array $l): int => $l['id'], $result['rows'])),
            'attachmentsModule' => $this->ctx->modules()->has('attachments'),
            'rights' => $rights,
            'canExport' => $canExport,
            'exportUrl' => $this->url('export.csv') . ($this->historyRoute($query) !== 'history' ? '?' . explode('?', $this->historyRoute($query), 2)[1] : ''),
            'perPageChoices' => self::PER_PAGE_CHOICES,
        ]);
        $actions = $this->navLinks('history');
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="log/new">' . $this->icon('plus') . '<span>Nouvelle intervention</span></a>';
        }
        $subtitle = $this->countLabel($result['total'], 'intervention', $query['q'] !== '' || $query['asset'] > 0 || $query['year'] > 0) . ' · ' . $this->money($result['cost']);
        $banner = $this->renderCore('banner', ['icon' => 'activity', 'title' => 'Historique des interventions', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Historique')->banner($banner)->content($content)->status($subtitle)->route($this->historyRoute($query));
    }

    public function logNew(Request $request, array $params): ModuleView
    {
        $jobId = (int) $request->query('job', 0);
        $job = $jobId > 0 ? $this->jobRepo()->find($jobId) : null;
        if ($jobId > 0 && $job === null) {
            throw new NotFoundException('Tâche introuvable.');
        }
        $assetId = $job !== null ? $job['asset_id'] : (int) $request->query('asset', 0);
        $asset = $assetId > 0 ? $this->assetRepo()->find($assetId) : null;
        $log = [
            'id' => null,
            'asset_id' => $asset['id'] ?? 0,
            'job_id' => $job['id'] ?? null,
            'done_at' => $this->today()->format('Y-m-d'),
            'meter_value' => $asset['meter_value'] ?? null,
            'title' => $job['title'] ?? '',
            'notes' => '',
            'cost' => $job['estimated_cost'] ?? null,
            'performed_by' => '',
        ];
        $content = $this->render('log_form', $this->logFormVars($log, true, $job));
        $title = $job !== null ? 'Intervention · ' . $job['title'] : 'Nouvelle intervention';
        $back = $job !== null ? 'job/' . $job['id'] : ($asset !== null ? 'asset/' . $asset['id'] : 'history');
        $banner = $this->renderCore('banner', ['icon' => 'check', 'title' => $title, 'subtitle' => $asset !== null ? $asset['name'] : 'Enregistrer une intervention réalisée', 'actions' => $this->backLink($back, 'Retour')]);
        return ModuleView::make($title)->banner($banner)->content($content)->status($title);
    }

    public function logEdit(Request $request, array $params): ModuleView
    {
        $log = $this->requireLog((int) ($params['id'] ?? 0));
        $job = $log['job_id'] !== null ? $this->jobRepo()->find($log['job_id']) : null;
        $info = $this->ctx->shared->registry->find(self::DATASET_LOG, (string) $log['id']);
        $infoId = $info === null ? null : (string) $info['id'];
        $vars = $this->logFormVars($log, false, $job) + [
            'infoId' => $infoId,
            'attachments' => $infoId === null ? [] : $this->ctx->shared->attachments->listFor($infoId),
            'attachmentsModule' => $this->ctx->modules()->has('attachments'),
            'rights' => $this->rights(['update', 'delete']),
        ];
        $content = $this->render('log_form', $vars);
        $banner = $this->renderCore('banner', ['icon' => 'check', 'title' => (string) $log['title'], 'subtitle' => $log['asset_name'] . ' · ' . $this->day($log['done_at']), 'actions' => $this->backLink('asset/' . $log['asset_id'], 'Fiche de l’équipement')]);
        return ModuleView::make('Intervention · ' . $log['title'])->banner($banner)->content($content)->status('Intervention du ' . $this->day($log['done_at']));
    }

    /** Corbeille du module : équipements, tâches et interventions supprimés logiquement. */
    public function trash(Request $request, array $params): ModuleView
    {
        $days = $this->retentionDays();
        $assets = $this->assetRepo()->trashed($days);
        $jobs = $this->jobRepo()->trashed($days);
        $logs = $this->logRepo()->trashed($days);
        $total = count($assets) + count($jobs) + count($logs);
        $content = $this->render('trash', [
            'assets' => $assets,
            'jobs' => $jobs,
            'logs' => $logs,
            'retentionDays' => $days,
            'categories' => AssetRepository::CATEGORIES,
            'canRestore' => $this->can('update'),
            'canPurge' => $this->can('delete'),
            'trashModule' => $this->ctx->modules()->has('trash'),
        ]);
        $subtitle = sprintf('%d équipement(s), %d tâche(s), %d intervention(s) · purge automatique après %d jours', count($assets), count($jobs), count($logs), $days);
        $actions = $this->navLinks('trash');
        if ($this->ctx->modules()->has('trash')) {
            $actions .= '<a class="btn" href="#" data-open-module="trash" data-open-route="list?module=maintenance">' . $this->icon('trash') . '<span>Corbeille globale</span></a>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'trash', 'title' => 'Corbeille de l’entretien', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Corbeille · entretien')->banner($banner)->content($content)->status($total . ' élément(s) en corbeille')->state(['counts' => ['asset' => count($assets), 'job' => count($jobs), 'log' => count($logs)]]);
    }

    // =====================================================================
    // Actions
    // =====================================================================

    /** Badge de la colonne : nombre de rappels actifs. */
    public function badge(Request $request, array $params): array
    {
        $count = $this->maintenanceService()->reminderCount();
        return ['count' => $count, 'label' => $count === 0 ? 'Aucun rappel d’entretien' : $count . ' rappel' . ($count > 1 ? 's' : '') . ' d’entretien'];
    }

    public function filterAssets(Request $request, array $params): ActionResult
    {
        $query = $this->assetsQuery($request->all());
        $query['page'] = 1;
        return ActionResult::ok()->navigate($this->assetsRoute($query));
    }

    public function filterJobs(Request $request, array $params): ActionResult
    {
        $screen = $request->string('screen') === 'defects' ? 'defects' : 'jobs';
        return ActionResult::ok()->navigate($this->jobsRoute($screen, $this->jobsQuery($request->all(), $screen)));
    }

    public function filterHistory(Request $request, array $params): ActionResult
    {
        $query = $this->historyQuery($request->all());
        $query['page'] = 1;
        return ActionResult::ok()->navigate($this->historyRoute($query));
    }

    public function assetSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $data = $this->validateAsset($request->all());
        $tags = $this->tagList($request->input('tags'));
        if ($id === null || $id <= 0) {
            $this->require('create', null, 'Vous n’avez pas le droit de créer des équipements.');
            $id = $this->assetRepo()->create($data, $this->ctx->userId());
            $this->applyTags($this->registerAsset($id, $data['name']), $tags);
            $this->log('maintenance.asset_create', 'success', 'maintenance_asset:' . $id, 'Équipement créé : ' . $data['name'], ['category' => $data['category']]);
            return ActionResult::ok(['id' => $id], 'Équipement « ' . $data['name'] . ' » créé.')->dirty(false)->navigate('asset/' . $id);
        }
        $this->require('update', null, 'Vous n’avez pas le droit de modifier des équipements.');
        $asset = $this->requireAsset($id);
        $this->assetRepo()->update($id, $data, $asset['meter_value']);
        $this->applyTags($this->registerAsset($id, $data['name']), $tags);
        $this->log('maintenance.asset_update', 'success', 'maintenance_asset:' . $id, 'Équipement modifié : ' . $data['name']);
        return ActionResult::ok(['id' => $id], 'Équipement « ' . $data['name'] . ' » enregistré.')->dirty(false)->navigate('asset/' . $id);
    }

    /** Nouveau relevé du compteur (bouton avec saisie préalable). */
    public function assetMeter(Request $request, array $params): ActionResult
    {
        $asset = $this->requireAsset($this->requireId($request));
        if ($asset['meter_unit'] === null) {
            throw ValidationException::single('meter_value', 'Cet équipement n’a pas de compteur.');
        }
        $value = $this->parseMeter($request->input('meter_value'), 'meter_value', true);
        $this->assetRepo()->updateMeter($asset['id'], (int) $value);
        $this->log('maintenance.meter', 'success', 'maintenance_asset:' . $asset['id'], 'Relevé du compteur : ' . $this->meter($value, $asset['meter_unit']), ['previous' => $asset['meter_value'], 'value' => $value]);
        $warning = $asset['meter_value'] !== null && $value < $asset['meter_value'];
        $message = 'Compteur mis à jour : ' . $this->meter($value, $asset['meter_unit']) . '.';
        return $warning
            ? ActionResult::warning(['value' => $value], $message . ' Le relevé est inférieur au précédent (' . $this->meter($asset['meter_value'], $asset['meter_unit']) . ').')->refresh()
            : ActionResult::ok(['value' => $value], $message)->refresh();
    }

    public function assetDelete(Request $request, array $params): ActionResult
    {
        $asset = $this->requireAsset($this->requireId($request));
        $this->assetRepo()->softDelete($asset['id']);
        $this->log('maintenance.asset_delete', 'success', 'maintenance_asset:' . $asset['id'], 'Équipement mis à la corbeille : ' . $asset['name']);
        return ActionResult::ok(['id' => $asset['id']], 'Équipement « ' . $asset['name'] . ' » mis à la corbeille.')->navigate('assets');
    }

    public function assetRestore(Request $request, array $params): ActionResult
    {
        return $this->trashAction('asset:' . $this->requireId($request), 'restore');
    }

    /** Suppression définitive d'un équipement en corbeille, avec ses tâches et son historique. */
    public function assetPurge(Request $request, array $params): ActionResult
    {
        return $this->trashAction('asset:' . $this->requireId($request), 'purge');
    }

    /** Restauration depuis la corbeille du module : id = « asset:12 », « job:5 » ou « log:9 ». */
    public function trashRestore(Request $request, array $params): ActionResult
    {
        return $this->trashAction($request->string('id'), 'restore');
    }

    /** Suppression définitive depuis la corbeille du module : id = « asset:12 », « job:5 » ou « log:9 ». */
    public function trashPurge(Request $request, array $params): ActionResult
    {
        return $this->trashAction($request->string('id'), 'purge');
    }

    public function jobSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $data = $this->validateJob($request->all());
        $tags = $this->tagList($request->input('tags'));
        $asset = $this->assetRepo()->find($data['asset_id']);
        $assetName = (string) ($asset['name'] ?? '');
        if ($id === null || $id <= 0) {
            $this->require('create', null, 'Vous n’avez pas le droit de créer des tâches.');
            $id = $this->jobRepo()->create($data, $this->ctx->userId());
            $this->applyTags($this->registerJob($id, $data['title'], $assetName), $tags);
            $this->log('maintenance.job_create', 'success', 'maintenance_job:' . $id, ($data['kind'] === 'corrective' ? 'Panne signalée : ' : 'Tâche planifiée : ') . $data['title'], ['asset_id' => $data['asset_id'], 'kind' => $data['kind']]);
            return ActionResult::ok(['id' => $id], ($data['kind'] === 'corrective' ? 'Panne « ' : 'Tâche « ') . $data['title'] . ' » enregistrée.')->dirty(false)->navigate('job/' . $id);
        }
        $this->require('update', null, 'Vous n’avez pas le droit de modifier des tâches.');
        $this->requireJob($id);
        $this->jobRepo()->update($id, $data);
        $this->applyTags($this->registerJob($id, $data['title'], $assetName), $tags);
        $this->log('maintenance.job_update', 'success', 'maintenance_job:' . $id, 'Tâche modifiée : ' . $data['title']);
        return ActionResult::ok(['id' => $id], 'Tâche « ' . $data['title'] . ' » enregistrée.')->dirty(false)->navigate('job/' . $id);
    }

    public function jobClose(Request $request, array $params): ActionResult
    {
        $job = $this->requireJob($this->requireId($request));
        $this->jobRepo()->setStatus($job['id'], 'closed');
        $this->log('maintenance.job_close', 'success', 'maintenance_job:' . $job['id'], 'Tâche clôturée : ' . $job['title']);
        return ActionResult::ok(['id' => $job['id']], 'Tâche « ' . $job['title'] . ' » clôturée.')->refresh();
    }

    public function jobReopen(Request $request, array $params): ActionResult
    {
        $job = $this->requireJob($this->requireId($request));
        $this->jobRepo()->setStatus($job['id'], 'open');
        $this->log('maintenance.job_reopen', 'success', 'maintenance_job:' . $job['id'], 'Tâche rouverte : ' . $job['title']);
        return ActionResult::ok(['id' => $job['id']], 'Tâche « ' . $job['title'] . ' » rouverte.')->refresh();
    }

    /** Suppression logique d'une tâche : elle rejoint la corbeille, ses interventions restent dans l'historique. */
    public function jobDelete(Request $request, array $params): ActionResult
    {
        $job = $this->requireJob($this->requireId($request));
        $this->jobRepo()->softDelete($job['id']);
        $this->log('maintenance.job_delete', 'success', 'maintenance_job:' . $job['id'], 'Tâche placée dans la corbeille : ' . $job['title']);
        return ActionResult::ok(['id' => $job['id']], ($job['kind'] === 'corrective' ? 'Panne « ' : 'Tâche « ') . $job['title'] . ' » placée dans la corbeille.')->navigate($job['kind'] === 'corrective' ? 'defects' : 'jobs');
    }

    /**
     * Enregistre une intervention. À la création depuis une tâche, la tâche est replanifiée
     * (prochaine échéance par date et/ou compteur) ou clôturée si elle n'est pas périodique,
     * et le compteur de l'équipement est mis à jour si le relevé est plus récent.
     */
    public function logSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $data = $this->validateLog($request->all());
        $asset = $this->requireAsset($data['asset_id']);
        $job = $data['job_id'] !== null ? $this->jobRepo()->find($data['job_id']) : null;
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update', null, 'Vous n’avez pas le droit d’enregistrer des interventions.');
        if (!$isNew) {
            $this->requireLog($id);
        }

        $rescheduled = null;
        $id = $this->ctx->db->transaction(function () use ($isNew, $id, $data, $asset, $job, &$rescheduled): int {
            if ($isNew) {
                $id = $this->logRepo()->create($data, $this->ctx->userId());
                if ($job !== null && $job['status'] === 'open') {
                    $rescheduled = Scheduler::reschedule($job, Scheduler::parseDay($data['done_at']) ?? $this->today(), $data['meter_value']);
                    $this->jobRepo()->markDone($job['id'], $data['done_at'], $data['meter_value'], $rescheduled);
                }
            } else {
                $this->logRepo()->update($id, $data);
            }
            if ($data['meter_value'] !== null && $asset['meter_unit'] !== null && ($asset['meter_value'] === null || $data['meter_value'] > $asset['meter_value'])) {
                $this->assetRepo()->updateMeter($asset['id'], $data['meter_value']);
            }
            return $id;
        });
        $infoId = $this->registerLog($id, $data['title'], $data['done_at'], (string) $asset['name']);
        $this->log('maintenance.log_' . ($isNew ? 'create' : 'update'), 'success', 'maintenance_log:' . $id, 'Intervention ' . ($isNew ? 'enregistrée' : 'modifiée') . ' : ' . $data['title'], ['asset_id' => $asset['id'], 'job_id' => $data['job_id'], 'cost' => $data['cost']]);
        $budgetTransactionId = $this->reportCostToBudget($id, $data, (string) $asset['name']);

        // Documents déposés avec le formulaire (facture, photos…) : joints à l'intervention tout juste créée.
        $stored = [];
        $rejected = [];
        foreach ($request->fileList('files') as $file) {
            try {
                $record = $this->ctx->shared->attachments->store($file, $infoId, $this->ctx->userId(), $request->string('files_description') ?: null);
                $stored[] = ['id' => $record['id'], 'name' => $record['original_name'], 'size' => $record['size']];
                $this->log('maintenance.attach', 'success', 'attachment:' . $record['id'], 'Fichier joint à « ' . $data['title'] . ' »', ['dataset' => self::DATASET_LOG, 'size' => $record['size']]);
            } catch (ValidationException $e) {
                $rejected[] = (string) ($file['name'] ?? 'fichier') . ' (' . implode(' ', $e->fieldErrors()) . ')';
            }
        }

        $message = 'Intervention « ' . $data['title'] . ' » enregistrée' . ($budgetTransactionId !== null ? ' et reportée dans le budget' : '') . '.';
        if ($rescheduled !== null) {
            $message .= $rescheduled['status'] === 'closed'
                ? ' La tâche est clôturée.'
                : ' Prochaine échéance : ' . $this->dueLabel($rescheduled['next_due_at'], $rescheduled['next_due_meter'], $asset['meter_unit']) . '.';
        }
        if ($stored !== []) {
            $message .= ' ' . count($stored) . ' document' . (count($stored) > 1 ? 's joints' : ' joint') . '.';
        }
        $target = $job !== null ? 'job/' . $job['id'] : 'asset/' . $asset['id'];
        $payload = ['id' => $id, 'rescheduled' => $rescheduled, 'budget_transaction_id' => $budgetTransactionId, 'files' => $stored];
        if ($rejected !== []) {
            return ActionResult::warning($payload, $message . ' Document(s) refusé(s) : ' . implode(' ; ', $rejected) . ' — vous pouvez les joindre depuis la fiche de l’intervention.')->dirty(false)->navigate('log/' . $id . '/edit');
        }
        return ActionResult::ok($payload, $message)->dirty(false)->navigate($target);
    }

    /** Suppression logique d'une intervention : elle rejoint la corbeille ; son opération budgétaire éventuelle est retirée. */
    public function logDelete(Request $request, array $params): ActionResult
    {
        $log = $this->requireLog($this->requireId($request));
        $this->logRepo()->softDelete($log['id']);
        $this->removeCostFromBudget($log['id']);
        $this->log('maintenance.log_delete', 'success', 'maintenance_log:' . $log['id'], 'Intervention placée dans la corbeille : ' . $log['title']);
        return ActionResult::ok(['id' => $log['id']], 'Intervention « ' . $log['title'] . ' » placée dans la corbeille.')->refresh();
    }

    /** Téléversement direct d'un fichier sur un équipement, une tâche ou une intervention. */
    public function attach(Request $request, array $params): ActionResult
    {
        [$dataset, $id, $label] = $this->resolveTarget($request->string('target'), $this->requireId($request));
        $files = array_merge($request->fileList('files'), $request->fileList('file'));
        if ($files === []) {
            throw ValidationException::single('files', 'Choisissez au moins un fichier à joindre.');
        }
        $userId = $this->ctx->userId();
        $infoId = $this->ctx->shared->registry->register($dataset, (string) $id, $label, $userId);
        $stored = [];
        foreach ($files as $file) {
            $record = $this->ctx->shared->attachments->store($file, $infoId, $userId, $request->string('description') ?: null);
            $stored[] = ['id' => $record['id'], 'name' => $record['original_name'], 'size' => $record['size']];
            $this->log('maintenance.attach', 'success', 'attachment:' . $record['id'], 'Fichier joint à « ' . $label . ' »', ['dataset' => $dataset, 'size' => $record['size']]);
        }
        $count = count($stored);
        return ActionResult::ok(['files' => $stored], $count === 1 ? 'Fichier « ' . $stored[0]['name'] . ' » joint (' . Str::humanSize((int) $stored[0]['size']) . ').' : $count . ' fichiers joints.')->refresh();
    }

    public function attachmentDelete(Request $request, array $params): ActionResult
    {
        $id = $request->string('id');
        $attachment = $id === '' ? null : $this->ctx->shared->attachments->find($id);
        if ($attachment === null) {
            throw new NotFoundException('Pièce jointe introuvable.');
        }
        $info = $attachment['info_id'] === null ? null : $this->ctx->shared->registry->get((string) $attachment['info_id']);
        if ($info === null || !in_array($info['dataset_code'], self::DATASETS, true)) {
            throw new ForbiddenException('Cette pièce jointe n’appartient pas au module Entretien.');
        }
        $this->ctx->shared->attachments->softDelete($id);
        $this->log('maintenance.attachment_delete', 'success', 'attachment:' . $id, 'Pièce jointe retirée de « ' . ($info['label'] ?? $info['local_key']) . ' »');
        return ActionResult::ok(null, 'Pièce jointe mise à la corbeille des fichiers.')->refresh();
    }

    // =====================================================================
    // Routes brutes : impression, exports
    // =====================================================================

    /** Fiche d'intervention imprimable (page autonome). */
    public function jobPrint(Request $request, array $params): Response
    {
        $job = $this->decorateJob($this->requireJob((int) ($params['id'] ?? 0)));
        $asset = $this->assetRepo()->find($job['asset_id'], true);
        $info = $this->ctx->shared->registry->find(self::DATASET_JOB, (string) $job['id']);
        $html = $this->render('job_print', [
            'job' => $job,
            'asset' => $asset,
            'logs' => $this->logRepo()->forJob($job['id'], 20),
            'attachments' => $info === null ? [] : $this->ctx->shared->attachments->listFor((string) $info['id']),
            'printedAt' => Clock::formatDateTime(Clock::utc()),
            'user' => $this->ctx->user(),
            'appName' => $this->ctx->config->string('app.name', 'Atelier'),
        ]);
        $this->log('maintenance.job_print', 'success', 'maintenance_job:' . $job['id'], 'Fiche imprimée : ' . $job['title']);
        return Response::html($html)->withHeader('Cache-Control', 'private, no-store');
    }

    /** Export CSV de l'historique (filtres de la vue conservés). */
    public function exportCsv(Request $request, array $params): Response
    {
        $this->require('export', self::EXPORT_RESOURCE, 'Vous n’êtes pas autorisé à exporter l’historique.');
        $query = $this->historyQuery($request->allQuery());
        $rows = $this->logRepo()->export(['asset' => $query['asset'], 'job' => 0, 'q' => $query['q'], 'year' => $query['year']]);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de préparer l’export.');
        }
        $write = static function (array $fields) use ($handle): void {
            fputcsv($handle, $fields, ';', '"', '', "\r\n");
        };
        $write(['id', 'date', 'equipement', 'tache', 'titre', 'compteur', 'unite', 'cout_eur', 'intervenant', 'notes']);
        foreach ($rows as $row) {
            $write([
                (string) $row['id'],
                (string) $row['done_at'],
                (string) $row['asset_name'],
                (string) ($row['job_title'] ?? ''),
                (string) $row['title'],
                $row['meter_value'] === null ? '' : (string) $row['meter_value'],
                (string) ($row['asset_meter_unit'] ?? ''),
                $row['cost'] === null ? '' : number_format($row['cost'] / 100, 2, ',', ''),
                (string) ($row['performed_by'] ?? ''),
                BbCode::toText($row['notes']),
            ]);
        }
        rewind($handle);
        $csv = "\xEF\xBB\xBF" . (string) stream_get_contents($handle);
        fclose($handle);
        $this->log('maintenance.export', 'success', 'maintenance_log', sprintf('Export CSV de %d intervention(s)', count($rows)), ['count' => count($rows)]);
        return Response::raw($csv, 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="entretien-historique-' . $this->today()->format('Ymd') . '.csv"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** Calendrier iCalendar des échéances : un événement journée entière par tâche ouverte datée, avec alarme anticipée. */
    public function exportIcs(Request $request, array $params): Response
    {
        $this->require('export', self::EXPORT_RESOURCE, 'Vous n’êtes pas autorisé à exporter les rappels.');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Atelier//Entretien//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:Entretien — rappels'];
        $stamp = Clock::now()->format('Ymd\THis\Z');
        $count = 0;
        foreach ($this->jobRepo()->open() as $job) {
            $due = Scheduler::parseDay($job['next_due_at']);
            if ($due === null) {
                continue;
            }
            $count++;
            $description = $job['asset_name'] . ($job['next_due_meter'] !== null ? ' — ou à ' . $this->meter($job['next_due_meter'], $job['asset_meter_unit']) : '') . '. ' . BbCode::toText($job['description']);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:maintenance-job-' . $job['id'] . '@atelier';
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART;VALUE=DATE:' . $due->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $due->modify('+1 day')->format('Ymd');
            $lines[] = 'SUMMARY:' . $this->icsText(($job['kind'] === 'corrective' ? 'Panne : ' : 'Entretien : ') . $job['title']);
            $lines[] = 'DESCRIPTION:' . $this->icsText($description);
            $lines[] = 'CATEGORIES:' . ($job['kind'] === 'corrective' ? 'Panne' : 'Entretien');
            if ((int) $job['lead_days'] > 0) {
                $lines[] = 'BEGIN:VALARM';
                $lines[] = 'ACTION:DISPLAY';
                $lines[] = 'DESCRIPTION:' . $this->icsText('Rappel : ' . $job['title'] . ' (' . $job['asset_name'] . ')');
                $lines[] = 'TRIGGER:-P' . (int) $job['lead_days'] . 'D';
                $lines[] = 'END:VALARM';
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $ics = implode("\r\n", $lines) . "\r\n";
        $this->log('maintenance.export_ics', 'success', 'maintenance_job', sprintf('Export iCalendar de %d échéance(s)', $count), ['count' => $count]);
        return Response::raw($ics, 'text/calendar; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="entretien-rappels.ics"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    // =====================================================================
    // Corbeille globale (TrashProviderInterface) : équipements, tâches et interventions
    // =====================================================================

    /** Identifiants préfixés (« asset:12 », « job:5 », « log:9 ») ; visibles dès que l'utilisateur peut restaurer ou purger. */
    public function trashItems(): array
    {
        $canRestore = $this->can('update');
        $canPurge = $this->can('delete');
        if (!$canRestore && !$canPurge) {
            return [];
        }
        $retention = $this->retentionDays();
        $items = [];
        $push = function (string $type, int $id, string $label, string $deletedAt) use (&$items, $retention, $canRestore, $canPurge): void {
            $purgeAt = Clock::parseUtc($deletedAt)?->modify('+' . $retention . ' days');
            $items[] = [
                'id' => $type . ':' . $id,
                'label' => $label,
                'dataset' => self::TRASH_TYPES[$type],
                'deleted_at' => $deletedAt,
                'deleted_by' => null,
                'purge_at' => $purgeAt === null ? null : Clock::utc($purgeAt),
                'can_restore' => $canRestore,
                'can_purge' => $canPurge,
            ];
        };
        foreach ($this->assetRepo()->trashed($retention) as $asset) {
            $push('asset', $asset['id'], (string) $asset['name'], (string) $asset['deleted_at']);
        }
        foreach ($this->jobRepo()->trashed($retention) as $job) {
            $push('job', $job['id'], $this->trashLabel('job', $job), (string) $job['deleted_at']);
        }
        foreach ($this->logRepo()->trashed($retention) as $log) {
            $push('log', $log['id'], $this->trashLabel('log', $log), (string) $log['deleted_at']);
        }
        return $items;
    }

    public function restoreTrashItem(string $id): void
    {
        $this->require('update', null, 'Vous n’avez pas le droit de restaurer des éléments de l’entretien.');
        [$type, $localId] = $this->parseTrashId($id);
        $this->restoreTrashed($type, $localId);
    }

    public function purgeTrashItem(string $id): void
    {
        $this->require('delete', null, 'Vous n’avez pas le droit de supprimer définitivement des éléments de l’entretien.');
        [$type, $localId] = $this->parseTrashId($id);
        $this->purgeTrashed($type, $localId);
    }

    // =====================================================================
    // Helpers publics (gabarits)
    // =====================================================================

    public function icon(string $name, string $extra = ''): string
    {
        return '<svg class="icon' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    /** Rend un partiel du module depuis un gabarit. */
    public function partial(string $template, array $vars = []): string
    {
        return $this->render($template, $vars);
    }

    /** « 22/09/2026 » à partir d'une date calendaire AAAA-MM-JJ. */
    public function day(?string $day, string $empty = '—'): string
    {
        $parsed = Scheduler::parseDay($day);
        return $parsed === null ? $empty : $parsed->format('d/m/Y');
    }

    /** « 61 200 km » (ou « 61 200 » sans unité). */
    public function meter(?int $value, ?string $unit, string $empty = '—'): string
    {
        if ($value === null) {
            return $empty;
        }
        return number_format($value, 0, ',', ' ') . ($unit !== null && $unit !== '' ? ' ' . $unit : '');
    }

    /** « 125,00 € » à partir de centimes. */
    public function money(?int $cents, string $empty = '—'): string
    {
        return $cents === null ? $empty : number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    /** « 1 h 30 » à partir de minutes. */
    public function duration(?int $minutes, string $empty = '—'): string
    {
        if ($minutes === null) {
            return $empty;
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h > 0 ? $h . ' h' . ($m > 0 ? ' ' . sprintf('%02d', $m) : '') : $m . ' min';
    }

    /** Lignes non vides d'un champ texte multi-lignes (pièces, contacts, outillage). @return list<string> */
    public function lines(?string $text): array
    {
        if ($text === null) {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), static fn (string $l): bool => $l !== ''));
    }

    public function bbcode(?string $source): string
    {
        return BbCode::toHtml($source);
    }

    public function categoryLabel(string $category): string
    {
        return AssetRepository::CATEGORIES[$category] ?? $category;
    }

    public function categoryIcon(string $category): string
    {
        return match ($category) {
            'vehicle' => 'navigation',
            'heating' => 'power',
            'appliance' => 'settings',
            'house' => 'home',
            'garden' => 'globe',
            'electronics' => 'grid',
            default => 'tool',
        };
    }

    /** Badge HTML d'état d'échéance. @param array{code: string, days_left: ?int, meter_left: ?int, by: ?string} $state */
    public function stateBadge(array $state, ?string $unit = null): string
    {
        [$class, $icon] = match ($state['code']) {
            Scheduler::OVERDUE => ['badge--danger', 'error'],
            Scheduler::DUE => ['badge--danger', 'bell'],
            Scheduler::SOON => ['badge--warning', 'bell'],
            Scheduler::OK => ['badge--success', 'check'],
            Scheduler::CLOSED => ['badge--muted', 'check'],
            default => ['badge--muted', 'info'],
        };
        return '<span class="badge ' . $class . ' maintenance__state" title="' . $this->e($this->stateDetail($state, $unit)) . '">' . $this->icon($icon, 'icon--sm') . ' ' . $this->e($this->stateLabel($state)) . '</span>';
    }

    /** @param array{code: string, days_left: ?int, meter_left: ?int, by: ?string} $state */
    public function stateLabel(array $state): string
    {
        return match ($state['code']) {
            Scheduler::OVERDUE => 'En retard',
            Scheduler::DUE => 'À faire',
            Scheduler::SOON => 'Bientôt',
            Scheduler::OK => 'À jour',
            Scheduler::CLOSED => 'Clôturée',
            default => 'Sans échéance',
        };
    }

    /** Détail lisible : « dans 12 jours », « dépassée de 3 jours », « dans 480 km ». @param array{code: string, days_left: ?int, meter_left: ?int, by: ?string} $state */
    public function stateDetail(array $state, ?string $unit = null): string
    {
        $parts = [];
        if ($state['days_left'] !== null) {
            $d = $state['days_left'];
            $parts[] = $d === 0 ? 'aujourd’hui' : ($d > 0 ? 'dans ' . $d . ' jour' . ($d > 1 ? 's' : '') : 'dépassée de ' . abs($d) . ' jour' . (abs($d) > 1 ? 's' : ''));
        }
        if ($state['meter_left'] !== null) {
            $m = $state['meter_left'];
            $parts[] = $m === 0 ? 'au compteur actuel' : ($m > 0 ? 'dans ' . $this->meter($m, $unit) : 'dépassée de ' . $this->meter(abs($m), $unit));
        }
        return $parts === [] ? $this->stateLabel($state) : implode(' · ', $parts);
    }

    public function kindBadge(string $kind): string
    {
        return $kind === 'corrective'
            ? '<span class="badge badge--warning">' . $this->icon('warning', 'icon--sm') . ' Panne</span>'
            : '<span class="badge badge--info">' . $this->icon('clock', 'icon--sm') . ' Entretien</span>';
    }

    public function priorityBadge(string $priority): string
    {
        $class = match ($priority) {
            'urgent' => 'badge--danger',
            'high' => 'badge--warning',
            'low' => 'badge--muted',
            default => '',
        };
        return '<span class="badge ' . $class . '">' . $this->e(JobRepository::PRIORITIES[$priority] ?? $priority) . '</span>';
    }

    /** « 15/03/2027 ou 76 200 km ». */
    public function dueLabel(?string $day, ?int $meter, ?string $unit): string
    {
        $parts = [];
        if ($day !== null && $day !== '') {
            $parts[] = $this->day($day);
        }
        if ($meter !== null) {
            $parts[] = $this->meter($meter, $unit);
        }
        return $parts === [] ? '—' : implode(' ou ', $parts);
    }

    /** « tous les 365 jours ou 15 000 km ». */
    public function intervalLabel(?int $days, ?int $meter, ?string $unit): string
    {
        $parts = [];
        if ($days !== null && $days > 0) {
            $parts[] = $days % 365 === 0 ? 'tous les ' . ($days === 365 ? 'ans' : intdiv($days, 365) . ' ans') : ($days % 30 === 0 && $days >= 60 ? 'tous les ' . intdiv($days, 30) . ' mois' : 'tous les ' . $days . ' jours');
        }
        if ($meter !== null && $meter > 0) {
            $parts[] = 'tous les ' . $this->meter($meter, $unit);
        }
        return $parts === [] ? 'ponctuelle' : implode(' ou ', $parts);
    }

    public function attachmentUrl(string $id, bool $inline = false): string
    {
        return $this->ctx->baseUrl() . '/files/' . rawurlencode($id) . ($inline ? '?inline=1' : '');
    }

    // =====================================================================
    // Interne
    // =====================================================================

    private function jobsView(Request $request, string $screen): ModuleView
    {
        $query = $this->jobsQuery($request->allQuery(), $screen);
        $jobs = $this->decorateJobs($this->jobRepo()->search(['asset' => $query['asset'], 'kind' => $screen === 'defects' ? 'corrective' : $query['kind'], 'status' => $query['status'], 'q' => $query['q']]));
        if ($query['state'] === 'alert') {
            $jobs = array_values(array_filter($jobs, static fn (array $j): bool => in_array($j['state']['code'], Scheduler::ALERTING, true)));
        }
        $this->sortBySeverity($jobs);
        $rights = $this->rights(['create', 'update', 'delete']);
        $content = $this->render('jobs', [
            'rows' => $jobs,
            'query' => $query,
            'screen' => $screen,
            'assets' => $this->assetRepo()->all(),
            'kinds' => JobRepository::KINDS,
            'statuses' => JobRepository::STATUSES,
            'rights' => $rights,
        ]);
        $isDefects = $screen === 'defects';
        $actions = $this->navLinks($screen);
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="job/new' . ($isDefects ? '?kind=corrective' : '') . '">' . $this->icon('plus') . '<span>' . ($isDefects ? 'Signaler une panne' : 'Nouvelle tâche') . '</span></a>';
        }
        $alerts = count(array_filter($jobs, static fn (array $j): bool => in_array($j['state']['code'], Scheduler::ALERTING, true)));
        $subtitle = $this->countLabel(count($jobs), $isDefects ? 'panne' : 'tâche', $query['q'] !== '' || $query['asset'] > 0) . ($alerts > 0 ? ' · ' . $alerts . ' en rappel' : '');
        $banner = $this->renderCore('banner', ['icon' => $isDefects ? 'warning' : 'clock', 'title' => $isDefects ? 'Pannes et défauts' : 'Tâches d’entretien', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make($isDefects ? 'Pannes' : 'Tâches d’entretien')->banner($banner)->content($content)->status($subtitle)->route($this->jobsRoute($screen, $query));
    }

    /** @param array<string, mixed> $job @param list<string> $tags @return array<string, mixed> */
    private function jobFormVars(array $job, bool $isNew, array $tags): array
    {
        return [
            'job' => $job,
            'isNew' => $isNew,
            'tags' => $tags,
            'assets' => $this->assetRepo()->all(),
            'kinds' => JobRepository::KINDS,
            'priorities' => JobRepository::PRIORITIES,
            'meterUnits' => AssetRepository::METER_UNITS,
            'defaultLeadMeter' => self::DEFAULT_LEAD_METER,
            'today' => $this->today()->format('Y-m-d'),
        ];
    }

    /** @param array<string, mixed> $log @param array<string, mixed>|null $job @return array<string, mixed> */
    private function logFormVars(array $log, bool $isNew, ?array $job): array
    {
        $assets = $this->assetRepo()->all();
        $jobsByAsset = [];
        foreach ($this->jobRepo()->open() as $j) {
            $jobsByAsset[$j['asset_id']][] = ['id' => $j['id'], 'title' => (string) $j['title'], 'kind' => (string) $j['kind']];
        }
        if ($job !== null && $job['status'] !== 'open') {
            $jobsByAsset[$job['asset_id']][] = ['id' => $job['id'], 'title' => (string) $job['title'], 'kind' => (string) $job['kind']];
        }
        return [
            'log' => $log,
            'isNew' => $isNew,
            'job' => $job,
            'assets' => $assets,
            'assetsById' => array_column($assets, null, 'id'),
            'jobsByAsset' => $jobsByAsset,
            'today' => $this->today()->format('Y-m-d'),
        ];
    }

    /**
     * Validation d'un équipement.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateAsset(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        $name = $string('name');
        if ($name === '') {
            $errors['name'] = 'Le nom de l’équipement est obligatoire.';
        } elseif (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            $errors['name'] = sprintf('Le nom ne peut dépasser %d caractères.', self::NAME_MAX);
        }
        $category = $string('category');
        if (!isset(AssetRepository::CATEGORIES[$category])) {
            $errors['category'] = 'Choisissez une catégorie dans la liste.';
        }
        foreach (['brand' => 'La marque', 'model' => 'Le modèle', 'identifier' => 'L’identifiant'] as $key => $label) {
            if (mb_strlen($string($key), 'UTF-8') > self::SHORT_MAX) {
                $errors[$key] = sprintf('%s ne peut dépasser %d caractères.', $label, self::SHORT_MAX);
            }
        }
        if (mb_strlen($string('location'), 'UTF-8') > self::NAME_MAX) {
            $errors['location'] = sprintf('L’emplacement ne peut dépasser %d caractères.', self::NAME_MAX);
        }
        $acquiredAt = null;
        if ($string('acquired_at') !== '') {
            $acquiredAt = $this->parseDayInput($string('acquired_at'));
            if ($acquiredAt === null) {
                $errors['acquired_at'] = 'Date invalide (format attendu : AAAA-MM-JJ).';
            }
        }
        $meterUnit = $string('meter_unit');
        if ($meterUnit !== '' && !isset(AssetRepository::METER_UNITS[$meterUnit])) {
            $errors['meter_unit'] = 'Unité de compteur inconnue.';
        }
        $meterValue = null;
        if ($meterUnit !== '') {
            try {
                $meterValue = $this->parseMeter($input['meter_value'] ?? null, 'meter_value', false);
            } catch (ValidationException $e) {
                $errors += $e->fieldErrors();
            }
        }
        $notes = $string('notes');
        if (mb_strlen($notes, 'UTF-8') > self::TEXT_MAX) {
            $errors['notes'] = sprintf('Les notes ne peuvent dépasser %d caractères.', self::TEXT_MAX);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'name' => $name,
            'category' => $category,
            'brand' => $string('brand'),
            'model' => $string('model'),
            'identifier' => $string('identifier'),
            'acquired_at' => $acquiredAt,
            'meter_unit' => $meterUnit !== '' ? $meterUnit : null,
            'meter_value' => $meterUnit !== '' ? $meterValue : null,
            'location' => $string('location'),
            'notes' => $notes,
        ];
    }

    /**
     * Validation d'une tâche (entretien planifié ou panne).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateJob(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $optionalInt = function (string $key, int $max, string $label) use ($input, &$errors): ?int {
            $raw = is_scalar($input[$key] ?? null) ? str_replace(' ', '', trim((string) $input[$key])) : '';
            if ($raw === '') {
                return null;
            }
            if (!preg_match('/^\d+$/', $raw) || (int) $raw > $max) {
                $errors[$key] = $label . ' doit être un nombre entier positif' . ($max < PHP_INT_MAX ? ' (au plus ' . number_format($max, 0, ',', ' ') . ')' : '') . '.';
                return null;
            }
            return (int) $raw;
        };

        $assetId = (int) ($input['asset_id'] ?? 0);
        $asset = $assetId > 0 ? $this->assetRepo()->find($assetId) : null;
        if ($asset === null) {
            $errors['asset_id'] = 'Choisissez l’équipement concerné.';
        }
        $title = $string('title');
        if ($title === '') {
            $errors['title'] = 'Le titre est obligatoire.';
        } elseif (mb_strlen($title, 'UTF-8') > self::NAME_MAX) {
            $errors['title'] = sprintf('Le titre ne peut dépasser %d caractères.', self::NAME_MAX);
        }
        $kind = $string('kind');
        if (!isset(JobRepository::KINDS[$kind])) {
            $errors['kind'] = 'Nature inconnue.';
        }
        $priority = $string('priority') !== '' ? $string('priority') : 'normal';
        if (!isset(JobRepository::PRIORITIES[$priority])) {
            $errors['priority'] = 'Priorité inconnue.';
        }
        foreach (['description' => 'Le descriptif', 'parts' => 'La liste des pièces', 'contacts' => 'Les contacts', 'tools' => 'L’outillage'] as $key => $label) {
            if (mb_strlen($string($key), 'UTF-8') > self::TEXT_MAX) {
                $errors[$key] = sprintf('%s ne peut dépasser %d caractères.', $label, self::TEXT_MAX);
            }
        }
        $estimatedMinutes = $optionalInt('estimated_minutes', 100000, 'La durée estimée');
        $estimatedCost = null;
        if ($string('estimated_cost') !== '') {
            $estimatedCost = $this->parseMoney($string('estimated_cost'));
            if ($estimatedCost === null) {
                $errors['estimated_cost'] = 'Montant invalide (exemple : 125,50).';
            }
        }

        $hasMeter = $asset !== null && $asset['meter_unit'] !== null;
        $intervalDays = $optionalInt('interval_days', 36500, 'La périodicité en jours');
        $intervalMeter = $hasMeter ? $optionalInt('interval_meter', self::METER_MAX, 'La périodicité au compteur') : null;
        $nextDueAt = null;
        if ($string('next_due_at') !== '') {
            $nextDueAt = $this->parseDayInput($string('next_due_at'));
            if ($nextDueAt === null) {
                $errors['next_due_at'] = 'Date invalide (format attendu : AAAA-MM-JJ).';
            }
        }
        $nextDueMeter = $hasMeter ? $optionalInt('next_due_meter', self::METER_MAX, 'L’échéance au compteur') : null;
        $leadDays = $optionalInt('lead_days', 3650, 'Le rappel anticipé en jours') ?? self::DEFAULT_LEAD_DAYS;
        $leadMeter = $hasMeter ? $optionalInt('lead_meter', self::METER_MAX, 'Le rappel anticipé au compteur') : null;

        if ($kind === 'corrective') {
            // Une panne n'est pas périodique : seule une date cible facultative est conservée.
            $intervalDays = null;
            $intervalMeter = null;
            $nextDueMeter = null;
        } else {
            if ($intervalDays === null && $intervalMeter === null && $nextDueAt === null && $nextDueMeter === null) {
                $errors['next_due_at'] = 'Indiquez au moins une échéance (date ou compteur) ou une périodicité.';
            }
            if ($nextDueAt === null && $intervalDays !== null && $nextDueMeter === null) {
                // Première échéance déduite de la périodicité.
                $nextDueAt = $this->today()->modify('+' . $intervalDays . ' days')->format('Y-m-d');
            }
            if ($nextDueMeter === null && $intervalMeter !== null && $hasMeter && $asset['meter_value'] !== null) {
                $nextDueMeter = $asset['meter_value'] + $intervalMeter;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'asset_id' => $assetId,
            'title' => $title,
            'kind' => $kind,
            'priority' => $priority,
            'description' => $string('description'),
            'parts' => $string('parts'),
            'contacts' => $string('contacts'),
            'tools' => $string('tools'),
            'estimated_minutes' => $estimatedMinutes,
            'estimated_cost' => $estimatedCost,
            'interval_days' => $intervalDays,
            'interval_meter' => $intervalMeter,
            'next_due_at' => $nextDueAt,
            'next_due_meter' => $nextDueMeter,
            'lead_days' => $leadDays,
            'lead_meter' => $leadMeter,
        ];
    }

    /**
     * Validation d'une intervention.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateLog(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        $assetId = (int) ($input['asset_id'] ?? 0);
        $asset = $assetId > 0 ? $this->assetRepo()->find($assetId) : null;
        if ($asset === null) {
            $errors['asset_id'] = 'Choisissez l’équipement concerné.';
        }
        $jobId = (int) ($input['job_id'] ?? 0);
        $job = null;
        if ($jobId > 0) {
            $job = $this->jobRepo()->find($jobId);
            if ($job === null || ($asset !== null && $job['asset_id'] !== $asset['id'])) {
                $errors['job_id'] = 'Cette tâche n’appartient pas à l’équipement choisi.';
            }
        }
        $title = $string('title');
        if ($title === '') {
            $title = $job !== null ? (string) $job['title'] : '';
        }
        if ($title === '') {
            $errors['title'] = 'Le titre de l’intervention est obligatoire.';
        } elseif (mb_strlen($title, 'UTF-8') > self::NAME_MAX) {
            $errors['title'] = sprintf('Le titre ne peut dépasser %d caractères.', self::NAME_MAX);
        }
        $doneAt = $this->parseDayInput($string('done_at'));
        if ($doneAt === null) {
            $errors['done_at'] = 'Date de réalisation invalide (format attendu : AAAA-MM-JJ).';
        } elseif ($doneAt > $this->today()->modify('+1 day')->format('Y-m-d')) {
            $errors['done_at'] = 'La date de réalisation ne peut pas être dans le futur.';
        }
        $meterValue = null;
        if ($asset !== null && $asset['meter_unit'] !== null) {
            try {
                $meterValue = $this->parseMeter($input['meter_value'] ?? null, 'meter_value', false);
            } catch (ValidationException $e) {
                $errors += $e->fieldErrors();
            }
        }
        $cost = null;
        if ($string('cost') !== '') {
            $cost = $this->parseMoney($string('cost'));
            if ($cost === null) {
                $errors['cost'] = 'Montant invalide (exemple : 125,50).';
            }
        }
        if (mb_strlen($string('performed_by'), 'UTF-8') > self::NAME_MAX) {
            $errors['performed_by'] = sprintf('L’intervenant ne peut dépasser %d caractères.', self::NAME_MAX);
        }
        if (mb_strlen($string('notes'), 'UTF-8') > self::TEXT_MAX) {
            $errors['notes'] = sprintf('Les notes ne peuvent dépasser %d caractères.', self::TEXT_MAX);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'asset_id' => $assetId,
            'job_id' => $job !== null ? $job['id'] : null,
            'done_at' => $doneAt,
            'meter_value' => $meterValue,
            'title' => $title,
            'notes' => $string('notes'),
            'cost' => $cost,
            'performed_by' => $string('performed_by'),
        ];
    }

    /** Date calendaire saisie (AAAA-MM-JJ ou JJ/MM/AAAA) normalisée en AAAA-MM-JJ. */
    private function parseDayInput(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) === 1) {
            $value = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return Scheduler::parseDay($value)?->format('Y-m-d');
    }

    /** Montant « 125,50 » / « 125.5 » / « 1 250 € » → centimes. */
    private function parseMoney(string $value): ?int
    {
        $clean = str_replace(['€', ' ', "\u{a0}", "\u{202f}"], '', trim($value));
        $clean = str_replace(',', '.', $clean);
        if ($clean === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
            return null;
        }
        $cents = (int) round((float) $clean * 100);
        return $cents > self::COST_MAX ? null : $cents;
    }

    /** Relevé de compteur (entier positif, espaces tolérés) ; null si vide et facultatif. */
    private function parseMeter(mixed $raw, string $field, bool $required): ?int
    {
        $value = is_scalar($raw) ? str_replace([' ', "\u{a0}", "\u{202f}"], '', trim((string) $raw)) : '';
        if ($value === '') {
            if ($required) {
                throw ValidationException::single($field, 'Saisissez le relevé du compteur.');
            }
            return null;
        }
        if (!preg_match('/^\d+$/', $value) || (int) $value > self::METER_MAX) {
            throw ValidationException::single($field, 'Le relevé doit être un nombre entier positif.');
        }
        return (int) $value;
    }

    /** @return list<string> */
    private function tagList(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : explode(',', (string) (is_scalar($raw) ? $raw : ''));
        $tags = [];
        foreach ($values as $value) {
            $tag = trim(ltrim(trim((string) (is_scalar($value) ? $value : '')), '#'));
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag, 'UTF-8') > 60) {
                throw ValidationException::single('tags', 'Un tag comporte au plus 60 caractères.');
            }
            $tags[] = $tag;
        }
        return array_slice(array_values(array_unique($tags)), 0, 20);
    }

    /** @param list<string> $tags */
    private function applyTags(string $infoId, array $tags): void
    {
        $this->ctx->shared->tags->replace($infoId, $tags, TagService::SHARED, $this->ctx->userId());
    }

    /** @return list<string> */
    private function tagNames(string $dataset, int $id): array
    {
        $info = $this->ctx->shared->registry->find($dataset, (string) $id);
        if ($info === null) {
            return [];
        }
        return array_map(static fn (array $t): string => (string) $t['name'], $this->ctx->shared->tags->tagsOf((string) $info['id']));
    }

    private function registerAsset(int $id, string $name): string
    {
        return $this->ctx->shared->registry->register(self::DATASET_ASSET, (string) $id, $name, $this->ctx->auth->userId());
    }

    private function registerJob(int $id, string $title, string $assetName): string
    {
        return $this->ctx->shared->registry->register(self::DATASET_JOB, (string) $id, $title . ($assetName !== '' ? ' — ' . $assetName : ''), $this->ctx->auth->userId());
    }

    /**
     * Reporte le coût réel d'une intervention dans le module Budget (opération d'origine « Entretien »,
     * référence maintenance_log:<id>), ou retire l'opération si le coût est effacé. Silencieux si le
     * module Budget est absent, désactivé ou si l'utilisateur n'y a pas le droit d'écriture.
     *
     * @param array<string, mixed> $data données validées de l'intervention
     */
    private function reportCostToBudget(int $logId, array $data, string $assetName): ?int
    {
        if (!$this->ctx->modules()->has('budget')) {
            return null;
        }
        $sourceRef = 'maintenance_log:' . $logId;
        try {
            $budget = $this->ctx->moduleService('budget');
            if ($data['cost'] === null || $data['cost'] <= 0) {
                $budget->removeExternal($this->ctx->userId(), $sourceRef);
                return null;
            }
            return $budget->recordExternal($this->ctx->userId(), $sourceRef, 'maintenance', [
                'label' => $data['title'] . ' — ' . $assetName,
                'amount' => -(int) $data['cost'],
                'done_at' => (string) $data['done_at'],
                'payee' => $data['performed_by'] !== '' ? $data['performed_by'] : null,
                'notes' => null,
            ]);
        } catch (ModuleUnavailableException | ForbiddenException $e) {
            $this->debug('Report du coût dans le budget impossible', ['log_id' => $logId, 'reason' => $e->getMessage()]);
            return null;
        }
    }

    private function removeCostFromBudget(int $logId): void
    {
        $userId = $this->ctx->auth->userId();
        if ($userId === null || !$this->ctx->modules()->has('budget')) {
            return; // purge par la console : l'opération budgétaire éventuelle a déjà été retirée à la mise en corbeille
        }
        try {
            $this->ctx->moduleService('budget')->removeExternal($userId, 'maintenance_log:' . $logId);
        } catch (ModuleUnavailableException | ForbiddenException) {
            // le module Budget est absent ou l'utilisateur n'y a pas de droit : l'opération éventuelle y reste
        }
    }

    private function registerLog(int $id, string $title, string $doneAt, string $assetName): string
    {
        return $this->ctx->shared->registry->register(self::DATASET_LOG, (string) $id, $title . ' (' . $this->day($doneAt) . ')' . ($assetName !== '' ? ' — ' . $assetName : ''), $this->ctx->auth->userId());
    }

    /** Cible d'un téléversement : jeu de données, identifiant local et libellé. @return array{0: string, 1: int, 2: string} */
    private function resolveTarget(string $target, int $id): array
    {
        return match ($target) {
            'asset' => [self::DATASET_ASSET, $id, (string) $this->requireAsset($id)['name']],
            'job' => (static function (array $job): array {
                return [self::DATASET_JOB, $job['id'], $job['title'] . ' — ' . $job['asset_name']];
            })($this->requireJob($id)),
            'log' => (function (array $log): array {
                return [self::DATASET_LOG, $log['id'], $log['title'] . ' (' . $this->day($log['done_at']) . ') — ' . $log['asset_name']];
            })($this->requireLog($id)),
            default => throw ValidationException::single('target', 'Cible de rattachement inconnue.'),
        };
    }

    /** Suppression physique d'un équipement, de ses tâches, de son historique et de leurs inscriptions au registre. */
    private function destroyAsset(int $id): void
    {
        $this->ctx->db->transaction(function () use ($id): void {
            $registry = $this->ctx->shared->registry;
            foreach ($this->logRepo()->idsForAsset($id) as $logId) {
                $registry->unregister(self::DATASET_LOG, (string) $logId);
                $this->removeCostFromBudget($logId);
            }
            foreach ($this->jobRepo()->idsForAsset($id) as $jobId) {
                $registry->unregister(self::DATASET_JOB, (string) $jobId);
            }
            $registry->unregister(self::DATASET_ASSET, (string) $id);
            $this->logRepo()->deleteForAsset($id);
            $this->jobRepo()->deleteForAsset($id);
            $this->assetRepo()->purge($id);
        });
    }

    /** Nombre de pièces jointes par identifiant local d'un jeu. @param list<int> $ids @return array<int, int> */
    private function attachmentCounts(string $dataset, array $ids): array
    {
        $counts = [];
        foreach ($ids as $id) {
            $info = $this->ctx->shared->registry->find($dataset, (string) $id);
            $counts[$id] = $info === null ? 0 : $this->ctx->shared->attachments->countFor((string) $info['id']);
        }
        return $counts;
    }

    /**
     * Pièces jointes (liste complète) et identifiant de registre par identifiant local d'un jeu.
     *
     * @param list<int> $ids
     * @return array<int, array{info_id: ?string, files: list<array<string, mixed>>}>
     */
    private function attachmentsByItem(string $dataset, array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $info = $this->ctx->shared->registry->find($dataset, (string) $id);
            $infoId = $info === null ? null : (string) $info['id'];
            $result[$id] = ['info_id' => $infoId, 'files' => $infoId === null ? [] : $this->ctx->shared->attachments->listFor($infoId)];
        }
        return $result;
    }

    // ----- Corbeille (commun à la vue du module et à la corbeille globale) -----

    private function retentionDays(): int
    {
        return max(1, $this->ctx->config->int('trash.retention_days', 30));
    }

    /** « job:5 » → ['job', 5] ; ValidationException si le préfixe ou le numéro est invalide. @return array{0: string, 1: int} */
    private function parseTrashId(string $id): array
    {
        $parts = explode(':', trim($id), 2);
        if (count($parts) !== 2 || !isset(self::TRASH_TYPES[$parts[0]]) || !ctype_digit($parts[1]) || (int) $parts[1] <= 0) {
            throw ValidationException::single('id', 'Identifiant d’élément de corbeille invalide.');
        }
        return [$parts[0], (int) $parts[1]];
    }

    /** Libellé d'un élément de corbeille. @param array<string, mixed> $row */
    private function trashLabel(string $type, array $row): string
    {
        return match ($type) {
            'asset' => (string) $row['name'],
            'job' => ($row['kind'] === 'corrective' ? 'Panne : ' : 'Tâche : ') . $row['title'] . ' — ' . $row['asset_name'],
            default => 'Intervention : ' . $row['title'] . ' (' . $this->day($row['done_at']) . ') — ' . $row['asset_name'],
        };
    }

    /** Action de la corbeille du module (restauration ou purge), avec message. */
    private function trashAction(string $id, string $operation): ActionResult
    {
        [$type, $localId] = $this->parseTrashId($id);
        if ($operation === 'restore') {
            $this->require('update', null, 'Vous n’avez pas le droit de restaurer des éléments de l’entretien.');
            $label = $this->restoreTrashed($type, $localId);
            return ActionResult::ok(['id' => $id], '« ' . $label . ' » restauré' . ($type === 'job' ? 'e' : ($type === 'log' ? 'e' : '')) . '.')->refresh();
        }
        $this->require('delete', null, 'Vous n’avez pas le droit de supprimer définitivement des éléments de l’entretien.');
        $label = $this->purgeTrashed($type, $localId);
        return ActionResult::ok(['id' => $id], '« ' . $label . ' » supprimé' . ($type === 'asset' ? '' : 'e') . ' définitivement.')->refresh();
    }

    /**
     * Restaure un élément en corbeille et retourne son libellé. Une tâche ou une intervention dont
     * l'équipement est lui-même en corbeille restaure aussi l'équipement, dans la même transaction.
     */
    private function restoreTrashed(string $type, int $id): string
    {
        if ($type === 'asset') {
            $asset = $this->assetRepo()->find($id, true);
            if ($asset === null || $asset['deleted_at'] === null || !$this->assetRepo()->restore($id)) {
                throw new NotFoundException('Cet équipement n’est pas dans la corbeille.');
            }
            $this->log('maintenance.asset_restore', 'success', 'maintenance_asset:' . $id, 'Équipement restauré : ' . $asset['name']);
            return (string) $asset['name'];
        }
        $row = $type === 'job' ? $this->jobRepo()->findTrashed($id) : $this->logRepo()->findTrashed($id);
        if ($row === null) {
            throw new NotFoundException($type === 'job' ? 'Cette tâche n’est pas dans la corbeille.' : 'Cette intervention n’est pas dans la corbeille.');
        }
        $label = $this->trashLabel($type, $row);
        $assetRestored = false;
        $this->ctx->db->transaction(function () use ($type, $id, $row, &$assetRestored): void {
            if ($row['asset_deleted_at'] !== null) {
                $assetRestored = $this->assetRepo()->restore($row['asset_id']);
            }
            if ($type === 'job') {
                $this->jobRepo()->restore($id);
            } else {
                $this->logRepo()->restore($id);
            }
        });
        if ($assetRestored) {
            $this->log('maintenance.asset_restore', 'success', 'maintenance_asset:' . $row['asset_id'], 'Équipement restauré avec « ' . $row['title'] . ' » : ' . $row['asset_name']);
        }
        if ($type === 'job') {
            $this->log('maintenance.job_restore', 'success', 'maintenance_job:' . $id, 'Tâche restaurée : ' . $row['title'] . ($assetRestored ? ' (équipement restauré également)' : ''));
        } else {
            $this->log('maintenance.log_restore', 'success', 'maintenance_log:' . $id, 'Intervention restaurée : ' . $row['title'] . ($assetRestored ? ' (équipement restauré également)' : ''));
            $this->reportCostToBudget($id, ['title' => (string) $row['title'], 'cost' => $row['cost'], 'done_at' => (string) $row['done_at'], 'performed_by' => (string) ($row['performed_by'] ?? '')], (string) $row['asset_name']);
        }
        return $label;
    }

    /** Supprime définitivement un élément en corbeille et retourne son libellé. */
    private function purgeTrashed(string $type, int $id): string
    {
        if ($type === 'asset') {
            $asset = $this->assetRepo()->find($id, true);
            if ($asset === null || $asset['deleted_at'] === null) {
                throw new NotFoundException('Cet équipement n’est pas dans la corbeille.');
            }
            $this->destroyAsset($id);
            $this->log('maintenance.asset_purge', 'success', 'maintenance_asset:' . $id, 'Équipement supprimé définitivement : ' . $asset['name']);
            return (string) $asset['name'];
        }
        $row = $type === 'job' ? $this->jobRepo()->findTrashed($id) : $this->logRepo()->findTrashed($id);
        if ($row === null) {
            throw new NotFoundException($type === 'job' ? 'Cette tâche n’est pas dans la corbeille.' : 'Cette intervention n’est pas dans la corbeille.');
        }
        $label = $this->trashLabel($type, $row);
        if ($type === 'job') {
            $this->purgeJob($id);
            $this->log('maintenance.job_purge', 'success', 'maintenance_job:' . $id, 'Tâche supprimée définitivement : ' . $row['title']);
        } else {
            $this->purgeLog($id);
            $this->log('maintenance.log_purge', 'success', 'maintenance_log:' . $id, 'Intervention supprimée définitivement : ' . $row['title']);
        }
        return $label;
    }

    /** Suppression physique d'une tâche en corbeille : ses interventions sont détachées (l'historique est conservé). */
    private function purgeJob(int $id): void
    {
        $this->ctx->db->transaction(function () use ($id): void {
            $this->logRepo()->detachJob($id);
            $this->ctx->shared->registry->unregister(self::DATASET_JOB, (string) $id);
            $this->jobRepo()->purge($id);
        });
    }

    /** Suppression physique d'une intervention en corbeille et de son inscription au registre. */
    private function purgeLog(int $id): void
    {
        $this->ctx->db->transaction(function () use ($id): void {
            $this->ctx->shared->registry->unregister(self::DATASET_LOG, (string) $id);
            $this->logRepo()->purge($id);
        });
        $this->removeCostFromBudget($id);
    }

    /** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
    private function decorateJobs(array $jobs): array
    {
        return array_map([$this, 'decorateJob'], $jobs);
    }

    /** Ajoute l'état d'échéance (clé state) à une tâche. @param array<string, mixed> $job @return array<string, mixed> */
    private function decorateJob(array $job): array
    {
        $job['state'] = Scheduler::state($job, $job['asset_meter_value'], $this->today());
        return $job;
    }

    /** @param list<array<string, mixed>> $jobs */
    private function sortBySeverity(array &$jobs): void
    {
        $priorityRank = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
        usort($jobs, static function (array $a, array $b) use ($priorityRank): int {
            return Scheduler::SEVERITY[$b['state']['code']] <=> Scheduler::SEVERITY[$a['state']['code']]
                ?: ($a['state']['days_left'] ?? PHP_INT_MAX) <=> ($b['state']['days_left'] ?? PHP_INT_MAX)
                ?: ($priorityRank[$a['priority']] ?? 9) <=> ($priorityRank[$b['priority']] ?? 9)
                ?: $a['id'] <=> $b['id'];
        });
    }

    /** Date du jour dans le fuseau d'affichage (les échéances sont des jours calendaires). */
    private function today(): DateTimeImmutable
    {
        return Clock::now()->setTimezone(new DateTimeZone($this->ctx->config->string('app.timezone', 'Europe/Paris')))->setTime(0, 0);
    }

    /** Échappement d'une valeur texte iCalendar (RFC 5545) : antislash, virgule, point-virgule, retours à la ligne. */
    private function icsText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace([',', ';'], ['\\,', '\\;'], $text);
        return str_replace(["\r\n", "\r", "\n"], '\\n', $text);
    }

    /** @return array<string, mixed>|null */
    private function requireAsset(int $id): array
    {
        $asset = $id > 0 ? $this->assetRepo()->find($id) : null;
        if ($asset === null) {
            throw new NotFoundException('Équipement introuvable.');
        }
        return $asset;
    }

    /** @return array<string, mixed> */
    private function requireJob(int $id): array
    {
        $job = $id > 0 ? $this->jobRepo()->find($id) : null;
        if ($job === null || $job['asset_deleted_at'] !== null) {
            throw new NotFoundException('Tâche introuvable.');
        }
        return $job;
    }

    /** @return array<string, mixed> */
    private function requireLog(int $id): array
    {
        $log = $id > 0 ? $this->logRepo()->find($id) : null;
        if ($log === null) {
            throw new NotFoundException('Intervention introuvable.');
        }
        return $log;
    }

    private function requireId(Request $request, string $key = 'id'): int
    {
        $id = $request->int($key);
        if ($id === null || $id <= 0) {
            throw ValidationException::single($key, 'Identifiant manquant.');
        }
        return $id;
    }

    /** @param array<string, mixed> $input @return array{q: string, category: string, sort: string, dir: string, page: int, per_page: int} */
    private function assetsQuery(array $input): array
    {
        $default = (int) $this->ctx->settings->preference($this->ctx->userId(), 'pageSize', 25);
        $perPage = (int) ($input['per_page'] ?? $default);
        $sort = (string) ($input['sort'] ?? 'name');
        $category = (string) ($input['category'] ?? '');
        return [
            'q' => is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '',
            'category' => isset(AssetRepository::CATEGORIES[$category]) ? $category : '',
            'sort' => AssetRepository::isSortable($sort) ? $sort : 'name',
            'dir' => strtolower((string) ($input['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PER_PAGE_CHOICES, true) ? $perPage : 25,
        ];
    }

    /** @param array{q: string, category: string, sort: string, dir: string, page: int, per_page: int} $query */
    private function assetsRoute(array $query): string
    {
        $params = array_filter([
            'q' => $query['q'],
            'category' => $query['category'],
            'sort' => $query['sort'] !== 'name' ? $query['sort'] : null,
            'dir' => $query['dir'] !== 'asc' ? $query['dir'] : null,
            'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null,
            'page' => $query['page'] > 1 ? $query['page'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return $params === [] ? 'assets' : 'assets?' . http_build_query($params);
    }

    /** @param array<string, mixed> $input @return array{q: string, asset: int, kind: string, status: string, state: string} */
    private function jobsQuery(array $input, string $screen): array
    {
        $kind = (string) ($input['kind'] ?? '');
        $status = (string) ($input['status'] ?? 'open');
        $state = (string) ($input['state'] ?? '');
        return [
            'q' => is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '',
            'asset' => max(0, (int) ($input['asset'] ?? 0)),
            'kind' => $screen === 'defects' ? 'corrective' : (isset(JobRepository::KINDS[$kind]) ? $kind : ''),
            'status' => $status === 'all' ? '' : (isset(JobRepository::STATUSES[$status]) ? $status : 'open'),
            'state' => $state === 'alert' ? 'alert' : '',
        ];
    }

    /** @param array{q: string, asset: int, kind: string, status: string, state: string} $query */
    private function jobsRoute(string $screen, array $query): string
    {
        $params = array_filter([
            'q' => $query['q'],
            'asset' => $query['asset'] > 0 ? $query['asset'] : null,
            'kind' => $screen === 'jobs' && $query['kind'] !== '' ? $query['kind'] : null,
            'status' => $query['status'] === 'open' ? null : ($query['status'] === '' ? 'all' : $query['status']),
            'state' => $query['state'] !== '' ? $query['state'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return $params === [] ? $screen : $screen . '?' . http_build_query($params);
    }

    /** @param array<string, mixed> $input @return array{q: string, asset: int, year: int, page: int, per_page: int} */
    private function historyQuery(array $input): array
    {
        $default = (int) $this->ctx->settings->preference($this->ctx->userId(), 'pageSize', 25);
        $perPage = (int) ($input['per_page'] ?? $default);
        return [
            'q' => is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '',
            'asset' => max(0, (int) ($input['asset'] ?? 0)),
            'year' => max(0, (int) ($input['year'] ?? 0)),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PER_PAGE_CHOICES, true) ? $perPage : 25,
        ];
    }

    /** @param array{q: string, asset: int, year: int, page: int, per_page: int} $query */
    private function historyRoute(array $query): string
    {
        $params = array_filter([
            'q' => $query['q'],
            'asset' => $query['asset'] > 0 ? $query['asset'] : null,
            'year' => $query['year'] > 0 ? $query['year'] : null,
            'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null,
            'page' => $query['page'] > 1 ? $query['page'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return $params === [] ? 'history' : 'history?' . http_build_query($params);
    }

    private function countLabel(int $total, string $noun, bool $filtered): string
    {
        $label = $total === 1 ? '1 ' . $noun : number_format($total, 0, ',', ' ') . ' ' . $noun . 's';
        return $filtered ? $label . ' (filtrés)' : $label;
    }

    private function backLink(string $route, string $label): string
    {
        return '<a class="btn btn--ghost" href="#" data-route="' . $this->e($route) . '">' . $this->icon('chevron-left') . '<span>' . $this->e($label) . '</span></a>';
    }

    /** Liens du bandeau vers les écrans principaux (celui en cours est marqué actif). */
    private function navLinks(string $current): string
    {
        $links = [
            'dashboard' => ['Tableau de bord', 'home'],
            'assets' => ['Équipements', 'layers'],
            'jobs' => ['Tâches', 'clock'],
            'defects' => ['Pannes', 'warning'],
            'history' => ['Historique', 'activity'],
        ];
        if ($current === 'trash') {
            $links['trash'] = ['Corbeille', 'trash'];
        }
        $html = '<div class="btn-group" role="group" aria-label="Écrans du module Entretien">';
        foreach ($links as $route => [$label, $icon]) {
            $active = $route === $current;
            $html .= '<a class="btn btn--sm' . ($active ? ' is-active' : '') . '" href="#" data-route="' . $this->e($route) . '" title="' . $this->e($label) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $this->icon($icon, 'icon--sm') . '<span class="maintenance__nav-label">' . $this->e($label) . '</span></a>';
        }
        return $html . '</div>';
    }

    private function assetRepo(): AssetRepository
    {
        return $this->assets ??= new AssetRepository($this->ctx->db);
    }

    private function jobRepo(): JobRepository
    {
        return $this->jobs ??= new JobRepository($this->ctx->db);
    }

    private function logRepo(): LogRepository
    {
        return $this->logs ??= new LogRepository($this->ctx->db);
    }

    private function maintenanceService(): MaintenanceService
    {
        /** @var MaintenanceService $service */
        $service = $this->service();
        return $service;
    }
}
