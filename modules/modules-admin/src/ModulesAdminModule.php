<?php

declare(strict_types=1);

namespace Atelier\Modules\ModulesAdmin;

use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\Manifest;
use Atelier\Modules\ModuleDescriptor;
use Atelier\Modules\ModuleManager;
use Atelier\Modules\ModuleSynchronizer;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Support\Files;
use Atelier\Support\Str;

/**
 * Gestion des modules : liste et état des modules installés, détail d'un module, ordre
 * d'affichage de la colonne de navigation (groupes, modules, accès directs) et catalogue
 * des jeux de données.
 *
 * Toutes les écritures passent par ModuleManager (fichier var/config/modules.json) et exigent
 * la permission « admin » sur le module ; chacune est journalisée.
 */
final class ModulesAdminModule extends AbstractModule
{
    /** Pas d'ordre explicite entre deux éléments réordonnés. */
    private const ORDER_STEP = 10;

    private ?ModuleInfoRepository $info = null;

    public function routes(RouteCollection $r): void
    {
        $r->view('list', [$this, 'list'], permission: 'open');
        $r->view('detail/{id}', [$this, 'detail'], permission: 'open');
        $r->view('order', [$this, 'order'], permission: 'admin');
        $r->view('datasets', [$this, 'datasets'], permission: 'open');

        $r->action('set-state', [$this, 'setState'], permission: 'admin');
        $r->action('sync', [$this, 'sync'], permission: 'admin');
        $r->action('move', [$this, 'move'], permission: 'admin');
        $r->action('reorder', [$this, 'reorder'], permission: 'admin');
        $r->action('set-group', [$this, 'setGroup'], permission: 'admin');
        $r->action('group-save', [$this, 'groupSave'], permission: 'admin');
        $r->action('reset-order', [$this, 'resetOrder'], permission: 'admin');
    }

    // ----- Vues -----

    public function list(Request $request, array $params): ModuleView
    {
        $manager = $this->ctx->modules();
        $isAdmin = $this->can('admin');
        $groups = $manager->groups();
        $catalog = $this->ctx->shared->catalog;

        $rows = [];
        $counts = ['active' => 0, 'inactive' => 0, 'maintenance' => 0, 'error' => 0];
        foreach ($manager->sorted() as $descriptor) {
            $state = $descriptor->state();
            $counts[$state] = ($counts[$state] ?? 0) + 1;
            $consumed = [];
            if ($isAdmin && $descriptor->manifest !== null) {
                foreach ($catalog->ofModule($descriptor->id) as $dataset) {
                    $others = array_values(array_diff($dataset['consumers'], [$descriptor->id]));
                    if ($others !== []) {
                        $consumed[] = $dataset['code'] . ' (' . implode(', ', $others) . ')';
                    }
                }
            }
            $rows[] = [
                'descriptor' => $descriptor,
                'groupLabel' => $groups[$descriptor->group()]['label'] ?? $descriptor->group(),
                'consumedByOthers' => $consumed,
                'isSelf' => $descriptor->id === $this->id(),
            ];
        }

        $actions = '<a class="btn" href="' . $this->e($this->url('list')) . '" data-route="list" title="Recharger la liste">'
            . $this->icon('refresh') . '<span>Actualiser</span></a>';
        if ($isAdmin) {
            $actions = '<button type="button" class="btn btn--primary" data-action="sync" title="Relire les manifestes et synchroniser ressources, permissions et catalogue">'
                . $this->icon('layers') . '<span>Resynchroniser les manifestes</span></button>' . $actions;
        }

        $subtitle = sprintf('%d module(s) : %d actif(s), %d inactif(s), %d en maintenance, %d en erreur', count($rows), $counts['active'], $counts['inactive'], $counts['maintenance'], $counts['error']);
        $banner = $this->renderCore('banner', ['icon' => 'puzzle', 'title' => 'Modules installés', 'subtitle' => $subtitle, 'actions' => $actions]);
        $content = $this->render('list', ['rows' => $rows, 'isAdmin' => $isAdmin, 'counts' => $counts]);

        return ModuleView::make('Modules installés')->banner($banner)->content($content)->status(count($rows) . ' module(s)');
    }

    public function detail(Request $request, array $params): ModuleView
    {
        $id = (string) ($params['id'] ?? '');
        $descriptor = $this->ctx->modules()->get($id);
        if ($descriptor === null) {
            throw new NotFoundException('Module inconnu : ' . Str::e($id));
        }
        $manager = $this->ctx->modules();
        $catalog = $this->ctx->shared->catalog;
        $info = $this->info();
        $states = $this->moduleStates();

        $data = $descriptor->toArray();
        $produced = $descriptor->manifest !== null ? $catalog->ofModule($id) : [];
        foreach ($produced as &$dataset) {
            $dataset['tableCounts'] = $info->tableCounts($dataset['tables']);
        }
        unset($dataset);

        $consumed = [];
        foreach ($descriptor->manifest?->consumes() ?? [] as $code) {
            $dataset = $catalog->find($code);
            $consumed[] = [
                'code' => $code,
                'dataset' => $dataset,
                'ownerState' => $dataset !== null ? ($states[$dataset['module_id']] ?? 'missing') : 'missing',
            ];
        }

        // Consommateurs connus des jeux produits par ce module (dépendants).
        $dependents = [];
        foreach ($produced as $dataset) {
            foreach ($dataset['consumers'] as $consumer) {
                if ($consumer !== $id) {
                    $dependents[$consumer][] = $dataset['code'];
                }
            }
        }
        ksort($dependents);

        $migrationsDir = $descriptor->manifest?->migrationsDirectory();
        $available = [];
        if ($migrationsDir !== null && is_dir($migrationsDir)) {
            foreach (scandir($migrationsDir) ?: [] as $file) {
                if (preg_match('/^(\d{3,})_([a-z0-9_]+)\.php$/i', $file, $m) === 1) {
                    $available[] = ['version' => (int) $m[1], 'name' => $m[2]];
                }
            }
            usort($available, static fn (array $a, array $b): int => $a['version'] <=> $b['version']);
        }

        $content = $this->render('detail', [
            'descriptor' => $descriptor,
            'data' => $data,
            'groupLabel' => $manager->groups()[$descriptor->group()]['label'] ?? $descriptor->group(),
            'manifestResources' => $descriptor->manifest?->resources() ?? [],
            'aclResources' => $info->aclResources($id),
            'dbPermissions' => $info->permissions($id),
            'produced' => $produced,
            'consumed' => $consumed,
            'dependents' => $dependents,
            'states' => $states,
            'errors' => $info->recentErrors($id, 10),
            'activityCount' => $info->activityCount($id),
            'migrations' => $info->appliedMigrations($id),
            'migrationVersion' => $info->currentMigrationVersion($id),
            'availableMigrations' => $available,
            'isAdmin' => $this->can('admin'),
            'isSelf' => $id === $this->id(),
        ]);

        $actions = '<a class="btn" href="' . $this->e($this->url('list')) . '" data-route="list">' . $this->icon('chevron-left') . '<span>Retour à la liste</span></a>';
        $banner = $this->renderCore('banner', [
            'icon' => $descriptor->icon(),
            'title' => $descriptor->name(),
            'subtitle' => $descriptor->id . ' · v' . $descriptor->version() . ' · ' . self::stateLabel($descriptor->state()),
            'actions' => $actions,
        ]);

        return ModuleView::make('Module ' . $descriptor->name())->banner($banner)->content($content)->status('Détail du module ' . $descriptor->id);
    }

    public function order(Request $request, array $params): ModuleView
    {
        $manager = $this->ctx->modules();
        $groups = $manager->groups();
        $overrides = $manager->overrides();

        $tree = [];
        foreach ($groups as $groupId => $group) {
            $tree[$groupId] = [
                'id' => $groupId,
                'label' => $group['label'],
                'order' => $group['order'],
                'overridden' => isset($overrides['groups'][$groupId]),
                'modules' => [],
            ];
        }
        foreach ($manager->sorted() as $descriptor) {
            $tree[$descriptor->group()]['modules'][] = $descriptor;
        }

        $actions = '<button type="button" class="btn btn--outline-danger" data-action="reset-order" data-params=\'{"scope":"all"}\' '
            . 'data-confirm="Supprimer toutes les surcharges d’ordre, de groupe et de libellé ? Les états des modules (actif, inactif, maintenance) sont conservés." data-confirm-label="Réinitialiser">'
            . $this->icon('refresh') . '<span>Réinitialiser l’ordre par défaut</span></button>';
        $banner = $this->renderCore('banner', [
            'icon' => 'sort',
            'title' => 'Ordre d’affichage',
            'subtitle' => count($groups) . ' groupe(s) · ' . count($manager->all()) . ' module(s)',
            'actions' => $actions,
        ]);
        $content = $this->render('order', [
            'tree' => array_values($tree),
            'groups' => $groups,
            'overrides' => $overrides,
            'overridesFile' => $manager->overridesFile(),
        ]);

        return ModuleView::make('Ordre d’affichage')->banner($banner)->content($content)
            ->status('Glisser-déposer ou boutons Monter / Descendre')
            ->state(['route' => 'order']);
    }

    public function datasets(Request $request, array $params): ModuleView
    {
        $catalog = $this->ctx->shared->catalog;
        $states = $this->moduleStates();
        $all = $catalog->all();
        $orphaned = $catalog->orphaned($states);
        $names = [];
        foreach ($this->ctx->modules()->all() as $descriptor) {
            $names[$descriptor->id] = $descriptor->name();
        }

        $shared = count(array_filter($all, static fn (array $d): bool => $d['visibility'] === 'shared'));
        $banner = $this->renderCore('banner', [
            'icon' => 'database',
            'title' => 'Catalogue des jeux de données',
            'subtitle' => sprintf('%d jeu(x) : %d partagé(s), %d privé(s)', count($all), $shared, count($all) - $shared),
            'actions' => '<a class="btn" href="' . $this->e($this->url('datasets')) . '" data-route="datasets">' . $this->icon('refresh') . '<span>Actualiser</span></a>',
        ]);
        $content = $this->render('datasets', ['datasets' => $all, 'orphaned' => $orphaned, 'states' => $states, 'names' => $names]);

        return ModuleView::make('Catalogue des jeux de données')->banner($banner)->content($content)->status(count($all) . ' jeu(x) de données');
    }

    // ----- Actions -----

    /** Change l'état effectif d'un module (surcharge du manifeste). */
    public function setState(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $id = $request->string('id');
        $state = $request->string('state');
        $descriptor = $this->requireDescriptor($id);

        if (!in_array($state, Manifest::STATUSES, true)) {
            throw ValidationException::single('state', 'État inconnu : ' . $state);
        }
        if (!$descriptor->isValid()) {
            throw ValidationException::single('id', 'Le manifeste de ce module est invalide : corrigez-le puis resynchronisez avant de changer son état.');
        }
        if ($id === $this->id() && $state !== 'active') {
            throw ValidationException::single('id', 'La gestion des modules ne peut pas se désactiver elle-même (utilisez la console : modules:set).');
        }

        $previous = $descriptor->state();
        // Si l'état demandé est celui du manifeste, on retire la surcharge plutôt que de la figer.
        $manifestStatus = (string) $descriptor->manifest?->get('status', 'active');
        $this->ctx->modules()->setModuleOverride($id, 'status', $state === $manifestStatus ? null : $state);

        $message = match ($state) {
            'active' => 'Module activé.',
            'inactive' => 'Module désactivé. Ses données sont conservées.',
            default => 'Module mis en maintenance.',
        };
        $this->log('module.state', 'success', 'module:' . $id, $message, ['from' => $previous, 'to' => $state]);

        return ActionResult::ok(['id' => $id, 'state' => $state], $message)->refresh();
    }

    /** Relit les manifestes et force la synchronisation (migrations, ressources, permissions, catalogue). */
    public function sync(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $manager = $this->ctx->modules();
        $manager->discover(true);

        $synchronizer = $this->synchronizer();
        $synchronizer->invalidate();
        $log = $synchronizer->syncAll();
        // syncIfNeeded() écrit l'empreinte mais ne retourne pas le journal : on la réécrit ici.
        Files::writeAtomic($this->ctx->config->path('cache') . '/manifests.hash', $manager->manifestsHash());

        $invalid = array_values(array_filter($manager->all(), static fn (ModuleDescriptor $d): bool => !$d->isValid()));
        $this->log('module.sync', 'success', null, 'Manifestes resynchronisés.', ['log' => $log, 'invalid' => array_map(static fn (ModuleDescriptor $d): string => $d->id, $invalid)]);

        $message = 'Synchronisation effectuée : ' . implode(' ', $log);
        if ($invalid !== []) {
            $message .= sprintf(' %d module(s) en erreur.', count($invalid));
        }
        return ActionResult::ok(['log' => $log], $message)->refresh();
    }

    /** Monte ou descend un groupe, un module (dans son groupe) ou un accès direct (dans son module). */
    public function move(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $type = $request->string('type');
        $id = $request->string('id');
        $direction = $request->string('direction');
        if (!in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::single('direction', 'Direction invalide.');
        }

        $manager = $this->ctx->modules();
        switch ($type) {
            case 'group':
                $ids = array_keys($manager->groups());
                $container = null;
                break;
            case 'module':
                $descriptor = $this->requireDescriptor($id);
                $container = $descriptor->group();
                $ids = $this->moduleIdsOfGroup($container);
                break;
            case 'nav':
                $moduleId = $request->string('module');
                $descriptor = $this->requireDescriptor($moduleId);
                $container = $moduleId;
                $ids = array_column($descriptor->navigation(), 'id');
                break;
            default:
                throw ValidationException::single('type', 'Type d’élément inconnu.');
        }

        $index = array_search($id, $ids, true);
        if ($index === false) {
            throw ValidationException::single('id', 'Élément introuvable : ' . $id);
        }
        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($ids)) {
            return ActionResult::info(null, 'Déjà en ' . ($direction === 'up' ? 'première' : 'dernière') . ' position.');
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        $this->applyOrder($type, $container, $ids);
        $this->log('module.reorder', 'success', $type . ':' . $id, 'Élément déplacé (' . $direction . ').', ['type' => $type, 'container' => $container, 'order' => $ids]);

        return ActionResult::ok(['order' => $ids], 'Ordre mis à jour.')->refresh();
    }

    /** Applique une liste ordonnée complète (glisser-déposer). */
    public function reorder(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $type = $request->string('type');
        $ids = array_values(array_map(static fn (mixed $v): string => trim((string) $v), $request->arrayInput('ids')));
        $ids = array_values(array_unique(array_filter($ids, static fn (string $v): bool => $v !== '')));
        if ($ids === []) {
            throw ValidationException::single('ids', 'Liste vide.');
        }

        $container = null;
        switch ($type) {
            case 'group':
                $known = array_keys($this->ctx->modules()->groups());
                foreach ($ids as $gid) {
                    if (!in_array($gid, $known, true)) {
                        throw ValidationException::single('ids', 'Groupe inconnu : ' . $gid);
                    }
                }
                // Les groupes absents de la liste conservent leur position relative après les autres.
                foreach ($known as $gid) {
                    if (!in_array($gid, $ids, true)) {
                        $ids[] = $gid;
                    }
                }
                break;
            case 'module':
                $container = $request->string('group');
                if (!isset($this->ctx->modules()->groups()[$container])) {
                    throw ValidationException::single('group', 'Groupe inconnu : ' . $container);
                }
                foreach ($ids as $mid) {
                    $this->requireDescriptor($mid);
                }
                foreach ($this->moduleIdsOfGroup($container) as $mid) {
                    if (!in_array($mid, $ids, true)) {
                        $ids[] = $mid;
                    }
                }
                break;
            case 'nav':
                $container = $request->string('module');
                $descriptor = $this->requireDescriptor($container);
                $navIds = array_column($descriptor->navigation(), 'id');
                foreach ($ids as $nid) {
                    if (!in_array($nid, $navIds, true)) {
                        throw ValidationException::single('ids', 'Accès direct inconnu : ' . $nid);
                    }
                }
                foreach ($navIds as $nid) {
                    if (!in_array($nid, $ids, true)) {
                        $ids[] = $nid;
                    }
                }
                break;
            default:
                throw ValidationException::single('type', 'Type d’élément inconnu.');
        }

        $this->applyOrder($type, $container, $ids);
        $this->log('module.reorder', 'success', $type . ($container !== null ? ':' . $container : ''), 'Ordre réappliqué par glisser-déposer.', ['type' => $type, 'container' => $container, 'order' => $ids]);

        return ActionResult::ok(['order' => $ids], 'Ordre mis à jour.')->refresh();
    }

    /** Déplace un module vers un autre groupe (fin de liste). */
    public function setGroup(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $id = $request->string('id');
        $group = $request->string('group');
        $descriptor = $this->requireDescriptor($id);
        $manager = $this->ctx->modules();
        if (!isset($manager->groups()[$group])) {
            throw ValidationException::single('group', 'Groupe inconnu : ' . $group);
        }
        if ($group === $descriptor->group()) {
            return ActionResult::info(null, 'Le module est déjà dans ce groupe.');
        }

        $manifestGroup = (string) ($descriptor->manifest?->get('group', 'tools') ?? 'tools');
        $manager->setModuleOverride($id, 'group', $group === $manifestGroup ? null : $group);
        // Placé en fin du groupe cible.
        $ids = $this->moduleIdsOfGroup($group);
        $ids = array_values(array_diff($ids, [$id]));
        $ids[] = $id;
        $this->applyOrder('module', $group, $ids);

        $this->log('module.group', 'success', 'module:' . $id, 'Module déplacé vers le groupe « ' . $group . ' ».', ['group' => $group]);
        return ActionResult::ok(['id' => $id, 'group' => $group], 'Module déplacé vers le groupe « ' . ($manager->groups()[$group]['label'] ?? $group) . ' ».')->refresh();
    }

    /** Libellé et ordre d'un groupe. */
    public function groupSave(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $id = $request->string('id');
        $label = $request->string('label');
        $order = $request->int('order');
        $manager = $this->ctx->modules();
        $groups = $manager->groups();
        if (!isset($groups[$id])) {
            throw ValidationException::single('id', 'Groupe inconnu : ' . $id);
        }
        $errors = [];
        if ($label === '' || mb_strlen($label, 'UTF-8') > 60) {
            $errors['label'] = 'Le libellé est obligatoire (60 caractères maximum).';
        }
        if ($order === null || $order < 0 || $order > 9999) {
            $errors['order'] = 'L’ordre doit être un entier entre 0 et 9999.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $manager->setGroupOverride($id, $label, $order);
        $this->log('module.group_save', 'success', 'group:' . $id, 'Groupe modifié.', ['label' => $label, 'order' => $order]);
        return ActionResult::ok(['id' => $id], 'Groupe « ' . $label . ' » enregistré.')->refresh();
    }

    /** Supprime les surcharges d'ordre / de groupe (tous, un groupe ou un module). */
    public function resetOrder(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $scope = $request->string('scope', 'all');
        $id = $request->string('id');
        $manager = $this->ctx->modules();

        $resetModule = static function (ModuleManager $m, string $moduleId): void {
            $m->setModuleOverride($moduleId, 'order', null);
            $m->setModuleOverride($moduleId, 'group', null);
            $m->setModuleOverride($moduleId, 'navigation', null);
        };

        switch ($scope) {
            case 'all':
                foreach (array_keys($manager->overrides()['groups'] ?? []) as $gid) {
                    $manager->setGroupOverride((string) $gid, null, null);
                }
                foreach (array_keys($manager->all()) as $mid) {
                    $resetModule($manager, $mid);
                }
                // Surcharges de modules disparus : nettoyage aussi.
                foreach (array_keys($manager->overrides()['modules'] ?? []) as $mid) {
                    $resetModule($manager, (string) $mid);
                }
                $message = 'Ordre par défaut rétabli pour tous les groupes et modules.';
                break;
            case 'group':
                if (!isset($manager->groups()[$id])) {
                    throw ValidationException::single('id', 'Groupe inconnu : ' . $id);
                }
                $manager->setGroupOverride($id, null, null);
                foreach ($this->moduleIdsOfGroup($id) as $mid) {
                    $manager->setModuleOverride($mid, 'order', null);
                }
                $message = 'Groupe « ' . $id . ' » réinitialisé.';
                break;
            case 'module':
                $this->requireDescriptor($id);
                $resetModule($manager, $id);
                $message = 'Module « ' . $id . ' » réinitialisé.';
                break;
            default:
                throw ValidationException::single('scope', 'Portée inconnue.');
        }

        $this->log('module.reorder_reset', 'success', $scope . ($id !== '' ? ':' . $id : ''), $message);
        return ActionResult::ok(null, $message)->refresh();
    }

    // ----- Helpers -----

    private function info(): ModuleInfoRepository
    {
        return $this->info ??= new ModuleInfoRepository($this->ctx->db);
    }

    private function synchronizer(): ModuleSynchronizer
    {
        $srcDirectory = dirname((new \ReflectionClass(Application::class))->getFileName(), 2);
        return new ModuleSynchronizer(
            $this->ctx->db,
            $this->ctx->modules(),
            $srcDirectory . '/Persistence/migrations/core',
            $this->ctx->config->path('cache')
        );
    }

    private function requireDescriptor(string $id): ModuleDescriptor
    {
        $descriptor = $id !== '' ? $this->ctx->modules()->get($id) : null;
        if ($descriptor === null) {
            throw new NotFoundException('Module inconnu : ' . Str::e($id));
        }
        return $descriptor;
    }

    /** @return array<string, string> id => état effectif */
    private function moduleStates(): array
    {
        $states = [];
        foreach ($this->ctx->modules()->all() as $descriptor) {
            $states[$descriptor->id] = $descriptor->state();
        }
        return $states;
    }

    /** @return list<string> identifiants des modules du groupe, dans l'ordre effectif */
    private function moduleIdsOfGroup(string $group): array
    {
        $ids = [];
        foreach ($this->ctx->modules()->sorted() as $descriptor) {
            if ($descriptor->group() === $group) {
                $ids[] = $descriptor->id;
            }
        }
        return $ids;
    }

    /**
     * Écrit des ordres explicites (10, 20, 30…) pour une liste ordonnée d'identifiants.
     *
     * @param list<string> $ids
     */
    private function applyOrder(string $type, ?string $container, array $ids): void
    {
        $manager = $this->ctx->modules();
        foreach ($ids as $position => $id) {
            $order = ($position + 1) * self::ORDER_STEP;
            switch ($type) {
                case 'group':
                    $manager->setGroupOverride($id, null, $order);
                    break;
                case 'module':
                    $descriptor = $this->requireDescriptor($id);
                    if ($container !== null && $descriptor->group() !== $container) {
                        $manifestGroup = (string) ($descriptor->manifest?->get('group', 'tools') ?? 'tools');
                        $manager->setModuleOverride($id, 'group', $container === $manifestGroup ? null : $container);
                    }
                    $manager->setModuleOverride($id, 'order', $order);
                    break;
                case 'nav':
                    $manager->setNavigationOrder((string) $container, $id, $order);
                    break;
            }
        }
    }

    private function icon(string $name): string
    {
        return '<svg class="icon" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'active' => 'Actif',
            'inactive' => 'Inactif',
            'maintenance' => 'Maintenance',
            'error' => 'Erreur',
            'missing' => 'Absent',
            default => ucfirst($state),
        };
    }

    public static function stateBadge(string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'inactive' => 'muted',
            'maintenance' => 'warning',
            default => 'danger',
        };
    }
}
