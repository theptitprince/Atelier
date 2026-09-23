<?php

declare(strict_types=1);

namespace Atelier\Modules;

use Atelier\Error\ModuleUnavailableException;
use Atelier\Error\NotFoundException;
use Atelier\Kernel\Autoloader;
use Atelier\Kernel\Config;
use Atelier\Logging\Logger;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Json;
use Atelier\Support\Str;
use Throwable;

/**
 * Découverte des modules depuis le répertoire modules/, validation des manifestes,
 * application des surcharges administratives (fichier protégé var/config/modules.json),
 * instanciation et construction de l'arborescence de navigation.
 *
 * La découverte ne dépend pas de la base de données : elle fonctionne même si le stockage
 * est indisponible. Un manifeste invalide n'empêche que le module concerné.
 */
final class ModuleManager
{
    /** @var array<string, ModuleDescriptor> */
    private array $descriptors = [];

    /** @var array<string, ModuleInterface> */
    private array $instances = [];

    /** @var array<string, RouteCollection> */
    private array $routes = [];

    /** @var array<string, mixed>|null */
    private ?array $overrides = null;

    /** @var array<string, array{hash: string, reason: string}>|null échecs d'installation enregistrés */
    private ?array $failures = null;

    private bool $discovered = false;

    public function __construct(
        private readonly string $modulesDirectory,
        private readonly string $overridesFile,
        private readonly Autoloader $autoloader,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function modulesDirectory(): string
    {
        return $this->modulesDirectory;
    }

    /**
     * Parcourt le répertoire des modules et charge les manifestes. Idempotent.
     */
    public function discover(bool $force = false): void
    {
        if ($this->discovered && !$force) {
            return;
        }
        $this->descriptors = [];
        $this->instances = [];
        $this->routes = [];
        $overrides = $this->overrides()['modules'] ?? [];

        if (!is_dir($this->modulesDirectory)) {
            $this->discovered = true;
            return;
        }

        $seen = [];
        foreach (scandir($this->modulesDirectory) ?: [] as $entry) {
            $directory = $this->modulesDirectory . '/' . $entry;
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || str_starts_with($entry, '_') || !is_dir($directory)) {
                continue;
            }
            $id = $entry;
            $moduleOverrides = is_array($overrides[$id] ?? null) ? $overrides[$id] : [];
            try {
                $manifest = Manifest::load($directory);
                if (isset($seen[$manifest->id()])) {
                    throw new ManifestException($manifest->id(), ['Identifiant déjà utilisé par un autre module.']);
                }
                $seen[$manifest->id()] = true;
                $this->autoloader->addNamespace($manifest->namespace(), $manifest->sourceDirectory());
                // Un module dont l'installation a échoué (migration défectueuse) reste isolé tant
                // que son manifeste n'a pas changé ou qu'une resynchronisation n'a pas réussi.
                $failure = $this->failures()[$id] ?? null;
                $failed = $failure !== null && ($failure['hash'] ?? '') === $manifest->hash() ? [(string) $failure['reason']] : [];
                $this->descriptors[$id] = new ModuleDescriptor($id, $directory, $manifest, $failed, $moduleOverrides);
            } catch (ManifestException $e) {
                $this->logger->warning('Manifeste invalide : ' . $id, ['errors' => $e->errors]);
                $this->descriptors[$id] = new ModuleDescriptor($id, $directory, null, $e->errors, $moduleOverrides);
            } catch (Throwable $e) {
                $this->logger->error('Découverte du module impossible : ' . $id, ['error' => $e->getMessage()]);
                $this->descriptors[$id] = new ModuleDescriptor($id, $directory, null, ['Erreur inattendue : ' . $e->getMessage()], $moduleOverrides);
            }
        }
        ksort($this->descriptors);
        $this->discovered = true;
    }

    /** @return array<string, ModuleDescriptor> */
    public function all(): array
    {
        $this->discover();
        return $this->descriptors;
    }

    /** @return list<ModuleDescriptor> triés par groupe, ordre puis nom */
    public function sorted(): array
    {
        $groups = $this->groups();
        $list = array_values($this->all());
        usort($list, static function (ModuleDescriptor $a, ModuleDescriptor $b) use ($groups): int {
            $ga = $groups[$a->group()]['order'] ?? 1000;
            $gb = $groups[$b->group()]['order'] ?? 1000;
            return [$ga, $a->group(), $a->order(), $a->name(), $a->id] <=> [$gb, $b->group(), $b->order(), $b->name(), $b->id];
        });
        return $list;
    }

    public function get(string $id): ?ModuleDescriptor
    {
        $this->discover();
        return $this->descriptors[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Descripteur d'un module utilisable, sinon exception adaptée (introuvable / indisponible).
     */
    public function requireUsable(string $id): ModuleDescriptor
    {
        $descriptor = $this->get($id);
        if ($descriptor === null) {
            throw new NotFoundException('Module inconnu : ' . Str::e($id));
        }
        if (!$descriptor->isUsable()) {
            $message = match ($descriptor->state()) {
                'maintenance' => 'Le module « ' . $descriptor->name() . ' » est en maintenance.',
                'inactive' => 'Le module « ' . $descriptor->name() . ' » est désactivé.',
                // L'état « error » couvre un manifeste invalide comme une installation échouée :
                // on reprend le motif exact plutôt qu'une cause supposée.
                default => 'Le module « ' . $descriptor->name() . ' » est indisponible : ' . ($descriptor->errors[0] ?? 'cause inconnue'),
            };
            throw new ModuleUnavailableException($message, $id, $descriptor->state());
        }
        return $descriptor;
    }

    /**
     * Instancie le module (même inactif : utile pour les migrations et l'installation).
     */
    public function instance(string $id): ModuleInterface
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        $descriptor = $this->get($id);
        if ($descriptor === null || $descriptor->manifest === null) {
            throw new ModuleUnavailableException('Le module « ' . Str::e($id) . ' » ne peut pas être chargé.', $id, 'error');
        }
        $class = $descriptor->manifest->entryClass();
        if (!class_exists($class)) {
            throw new ModuleUnavailableException('Classe d’entrée introuvable pour le module « ' . $descriptor->name() . ' ».', $id, 'error');
        }
        $module = new $class($descriptor->manifest);
        if (!$module instanceof ModuleInterface) {
            throw new ModuleUnavailableException('Le point d’entrée du module « ' . $descriptor->name() . ' » ne respecte pas le contrat.', $id, 'error');
        }
        $this->instances[$id] = $module;
        return $module;
    }

    /**
     * Instancie, démarre et collecte les routes d'un module pour la requête courante.
     *
     * @return array{0: ModuleInterface, 1: RouteCollection}
     */
    public function boot(string $id, ModuleContext $context): array
    {
        $module = $this->instance($id);
        if (!isset($this->routes[$id])) {
            $module->boot($context);
            $routes = new RouteCollection();
            $module->routes($routes);
            $this->routes[$id] = $routes;
        }
        return [$module, $this->routes[$id]];
    }

    /** Oublie les instances (tests, rafraîchissement). */
    public function reset(): void
    {
        $this->instances = [];
        $this->routes = [];
        $this->overrides = null;
        $this->discovered = false;
    }

    // ----- Groupes et surcharges -----

    /**
     * Groupes d'affichage : configuration par défaut, surcharges administratives, groupes inconnus des manifestes.
     *
     * @return array<string, array{label: string, order: int}>
     */
    public function groups(): array
    {
        $groups = [];
        foreach ($this->config->array('navigation.groups') as $id => $group) {
            $groups[(string) $id] = ['label' => (string) ($group['label'] ?? $id), 'order' => (int) ($group['order'] ?? 100)];
        }
        foreach ($this->overrides()['groups'] ?? [] as $id => $group) {
            if (!is_array($group)) {
                continue;
            }
            $groups[(string) $id] = [
                'label' => (string) ($group['label'] ?? $groups[$id]['label'] ?? $id),
                'order' => isset($group['order']) && is_numeric($group['order']) ? (int) $group['order'] : ($groups[$id]['order'] ?? 100),
            ];
        }
        $this->discover();
        foreach ($this->descriptors as $descriptor) {
            $group = $descriptor->group();
            if (!isset($groups[$group])) {
                $groups[$group] = ['label' => ucfirst($group), 'order' => 500];
            }
        }
        uasort($groups, static fn (array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);
        return $groups;
    }

    // ----- Échecs d'installation (migrations) -----

    /**
     * Échecs enregistrés, par identifiant de module : empreinte du manifeste au moment de l'échec
     * et motif. Conservés dans le cache pour que le module reste isolé d'une requête à l'autre.
     *
     * @return array<string, array{hash: string, reason: string}>
     */
    private function failures(): array
    {
        if ($this->failures === null) {
            $this->failures = [];
            $file = $this->failuresFile();
            if (is_file($file)) {
                try {
                    /** @var array<string, array{hash: string, reason: string}> $data */
                    $data = Json::readFile($file);
                    $this->failures = $data;
                } catch (Throwable) {
                    $this->failures = [];
                }
            }
        }
        return $this->failures;
    }

    private function failuresFile(): string
    {
        return rtrim($this->config->path('cache'), '/') . '/module-failures.json';
    }

    /** Isole un module dont l'installation a échoué ; les autres modules continuent de fonctionner. */
    public function markFailed(string $id, string $reason): void
    {
        $descriptor = $this->descriptors[$id] ?? null;
        $failures = $this->failures();
        $failures[$id] = ['hash' => $descriptor?->manifest?->hash() ?? '', 'reason' => $reason];
        $this->failures = $failures;
        $this->writeFailures($failures);
        if ($descriptor !== null) {
            $this->descriptors[$id] = new ModuleDescriptor($id, $descriptor->directory, $descriptor->manifest, array_merge($descriptor->errors, [$reason]), $descriptor->overrides);
        }
    }

    /** Oublie tous les échecs enregistrés : la prochaine synchronisation réessaie chaque module. */
    public function clearFailures(): void
    {
        $this->failures = [];
        $file = $this->failuresFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** @param array<string, array{hash: string, reason: string}> $failures */
    private function writeFailures(array $failures): void
    {
        try {
            Json::writeFile($this->failuresFile(), $failures);
        } catch (Throwable $e) {
            $this->logger->error('Échecs de modules non enregistrés', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    public function overrides(): array
    {
        if ($this->overrides === null) {
            $this->overrides = ['groups' => [], 'modules' => []];
            if (is_file($this->overridesFile)) {
                try {
                    $data = Json::readFile($this->overridesFile);
                    $this->overrides['groups'] = is_array($data['groups'] ?? null) ? $data['groups'] : [];
                    $this->overrides['modules'] = is_array($data['modules'] ?? null) ? $data['modules'] : [];
                } catch (Throwable $e) {
                    $this->logger->error('Fichier de configuration des modules illisible', ['file' => $this->overridesFile, 'error' => $e->getMessage()]);
                }
            }
        }
        return $this->overrides;
    }

    public function overridesFile(): string
    {
        return $this->overridesFile;
    }

    /**
     * Modifie une surcharge de module (status, group, order, navigation) et réécrit le fichier atomiquement.
     */
    public function setModuleOverride(string $moduleId, string $key, mixed $value): void
    {
        if (!in_array($key, ['status', 'group', 'order', 'navigation'], true)) {
            throw new \InvalidArgumentException('Clé de surcharge inconnue : ' . $key);
        }
        if ($key === 'status' && $value !== null && !in_array($value, Manifest::STATUSES, true)) {
            throw new \InvalidArgumentException('État invalide : ' . (string) $value);
        }
        $overrides = $this->overrides();
        if ($value === null) {
            unset($overrides['modules'][$moduleId][$key]);
            if (($overrides['modules'][$moduleId] ?? []) === []) {
                unset($overrides['modules'][$moduleId]);
            }
        } else {
            $overrides['modules'][$moduleId][$key] = $value;
        }
        $this->writeOverrides($overrides);
    }

    public function setNavigationOrder(string $moduleId, string $navId, ?int $order): void
    {
        $overrides = $this->overrides();
        if ($order === null) {
            unset($overrides['modules'][$moduleId]['navigation'][$navId]);
        } else {
            $overrides['modules'][$moduleId]['navigation'][$navId]['order'] = $order;
        }
        $this->writeOverrides($overrides);
    }

    public function setGroupOverride(string $groupId, ?string $label, ?int $order): void
    {
        $overrides = $this->overrides();
        if ($label === null && $order === null) {
            unset($overrides['groups'][$groupId]);
        } else {
            $current = is_array($overrides['groups'][$groupId] ?? null) ? $overrides['groups'][$groupId] : [];
            if ($label !== null) {
                $current['label'] = $label;
            }
            if ($order !== null) {
                $current['order'] = $order;
            }
            $overrides['groups'][$groupId] = $current;
        }
        $this->writeOverrides($overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function writeOverrides(array $overrides): void
    {
        Json::writeFile($this->overridesFile, $overrides);
        $this->overrides = $overrides;
        // Réappliquer les surcharges aux descripteurs sans relire les manifestes.
        foreach ($this->descriptors as $id => $descriptor) {
            $this->descriptors[$id] = new ModuleDescriptor($id, $descriptor->directory, $descriptor->manifest, $descriptor->errors, is_array($overrides['modules'][$id] ?? null) ? $overrides['modules'][$id] : []);
        }
    }

    // ----- Navigation -----

    /**
     * Arborescence de la colonne de gauche pour un utilisateur : groupes > modules > accès directs.
     * Sans ACL (stockage indisponible), tous les modules sont présentés verrouillés.
     *
     * @return list<array<string, mixed>>
     */
    public function navigationTree(?int $userId, ?AclService $acl, string $baseUrl = ''): array
    {
        $groups = $this->groups();
        $tree = [];
        foreach ($groups as $groupId => $group) {
            $tree[$groupId] = ['id' => $groupId, 'label' => $group['label'], 'order' => $group['order'], 'modules' => []];
        }

        foreach ($this->sorted() as $descriptor) {
            $state = $descriptor->state();
            $accessible = false;
            $children = [];
            if ($state === 'active' && $userId !== null && $acl !== null) {
                $moduleResource = AclService::module($descriptor->id);
                $accessible = $acl->can($userId, $moduleResource, 'open');
                if ($accessible) {
                    $children = $this->buildChildren($descriptor, $userId, $acl, $baseUrl);
                }
            }
            $displayState = $state === 'active' ? ($accessible ? 'active' : 'locked') : $state;

            $tree[$descriptor->group()]['modules'][] = [
                'id' => $descriptor->id,
                'name' => $descriptor->name(),
                'icon' => $descriptor->icon(),
                'description' => $descriptor->description(),
                'state' => $displayState,
                'accessible' => $accessible,
                'route' => $descriptor->defaultRoute(),
                'url' => $baseUrl . '/m/' . $descriptor->id,
                'badge' => $descriptor->manifest?->badgeRoute(),
                'children' => $children,
            ];
        }

        return array_values(array_filter($tree, static fn (array $g): bool => $g['modules'] !== []));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildChildren(ModuleDescriptor $descriptor, int $userId, AclService $acl, string $baseUrl, ?string $parent = null, int $depth = 1): array
    {
        if ($depth > 3) {
            return [];
        }
        $children = [];
        foreach ($descriptor->navigation() as $entry) {
            if ($entry['parent'] !== $parent) {
                continue;
            }
            $resource = AclService::module($descriptor->id) . '/' . $entry['resource'];
            if (!$acl->can($userId, $resource, 'view') || !$acl->can($userId, $resource, $entry['permission'])) {
                continue;
            }
            $children[] = [
                'id' => $entry['id'],
                'label' => $entry['label'],
                'route' => $entry['route'],
                'url' => $baseUrl . '/m/' . $descriptor->id . '/' . $entry['route'],
                'icon' => $entry['icon'],
                'description' => $entry['description'],
                'children' => $this->buildChildren($descriptor, $userId, $acl, $baseUrl, $entry['id'], $depth + 1),
            ];
        }
        return $children;
    }

    /**
     * Empreinte globale des manifestes valides (détection de changement pour la synchronisation).
     */
    public function manifestsHash(): string
    {
        $parts = [];
        foreach ($this->all() as $descriptor) {
            $parts[] = $descriptor->id . ':' . ($descriptor->manifest?->hash() ?? 'invalid');
        }
        return sha1(implode('|', $parts));
    }
}
