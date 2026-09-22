<?php

declare(strict_types=1);

namespace Atelier\Modules;

use Atelier\Support\Json;
use Atelier\Support\Str;

/**
 * Manifeste d'un module (manifest.json), validé et normalisé.
 *
 * Voir docs/manifest-schema.md pour le schéma complet.
 */
final class Manifest
{
    public const STATUSES = ['active', 'inactive', 'maintenance'];

    /** @param array<string, mixed> $data données normalisées */
    private function __construct(public readonly array $data, public readonly string $directory)
    {
    }

    /**
     * Charge et valide un manifeste. Lève ManifestException avec la liste des erreurs.
     */
    public static function load(string $directory): self
    {
        $file = rtrim($directory, '/\\') . '/manifest.json';
        if (!is_file($file)) {
            throw new ManifestException(basename($directory), ['Fichier manifest.json absent.']);
        }
        try {
            $raw = Json::readFile($file);
        } catch (\Throwable $e) {
            throw new ManifestException(basename($directory), ['manifest.json illisible : ' . $e->getMessage()]);
        }
        return self::fromArray($raw, $directory);
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw, string $directory): self
    {
        $directory = str_replace('\\', '/', rtrim($directory, '/\\'));
        $dirName = basename($directory);
        $errors = [];

        $id = is_string($raw['id'] ?? null) ? $raw['id'] : '';
        if (!Str::isSlug($id)) {
            $errors[] = 'Identifiant "id" absent ou invalide (minuscules, chiffres, tirets).';
        } elseif ($id !== $dirName) {
            $errors[] = sprintf('L’identifiant "%s" doit correspondre au nom du répertoire "%s".', $id, $dirName);
        }
        if (in_array($id, ['core', 'atelier', 'assets', 'm', 'api', 'files', 'login', 'logout'], true)) {
            $errors[] = 'Identifiant réservé : ' . $id;
        }

        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Le nom affiché "name" est obligatoire.';
        }
        $version = trim((string) ($raw['version'] ?? ''));
        if (preg_match('/^\d+\.\d+(\.\d+)?([-.][0-9A-Za-z-]+)*$/', $version) !== 1) {
            $errors[] = 'La version "version" doit suivre le format X.Y.Z.';
        }

        $namespace = trim((string) ($raw['namespace'] ?? ''), '\\');
        if ($namespace === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace) !== 1) {
            $errors[] = 'L’espace de noms "namespace" est absent ou invalide.';
        }
        $entry = trim((string) ($raw['entry'] ?? ''));
        if ($entry === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $entry) !== 1) {
            $errors[] = 'La classe d’entrée "entry" est absente ou invalide.';
        } elseif ($namespace !== '' && !is_file($directory . '/src/' . $entry . '.php')) {
            $errors[] = sprintf('Le fichier src/%s.php du point d’entrée est introuvable.', $entry);
        }

        $status = (string) ($raw['status'] ?? 'active');
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'L’état "status" doit être active, inactive ou maintenance.';
        }

        $defaultRoute = self::normalizeRoute((string) ($raw['defaultRoute'] ?? 'index'));
        if ($defaultRoute === null) {
            $errors[] = 'La route par défaut "defaultRoute" est invalide.';
            $defaultRoute = 'index';
        }

        $group = (string) ($raw['group'] ?? 'tools');
        if (!Str::isSlug($group)) {
            $errors[] = 'Le groupe "group" est invalide.';
        }
        $order = is_numeric($raw['order'] ?? null) ? (int) $raw['order'] : 100;

        // Permissions propres au module
        $permissions = [];
        foreach (self::list($raw['permissions'] ?? []) as $i => $permission) {
            if (is_string($permission)) {
                $permission = ['code' => $permission, 'label' => ucfirst($permission)];
            }
            if (!is_array($permission) || !Str::isSlug((string) ($permission['code'] ?? ''), 32)) {
                $errors[] = sprintf('Permission n°%d invalide.', $i + 1);
                continue;
            }
            $permissions[] = [
                'code' => (string) $permission['code'],
                'label' => trim((string) ($permission['label'] ?? $permission['code'])),
                'description' => isset($permission['description']) ? (string) $permission['description'] : null,
            ];
        }

        // Navigation
        $navigation = [];
        $navIds = [];
        foreach (self::list($raw['navigation'] ?? []) as $i => $navEntry) {
            if (!is_array($navEntry)) {
                $errors[] = sprintf('Entrée de navigation n°%d invalide.', $i + 1);
                continue;
            }
            $navId = (string) ($navEntry['id'] ?? '');
            if (!Str::isSlug($navId)) {
                $errors[] = sprintf('Entrée de navigation n°%d : identifiant absent ou invalide.', $i + 1);
                continue;
            }
            if (in_array($navId, $navIds, true)) {
                $errors[] = sprintf('Entrée de navigation "%s" dupliquée.', $navId);
                continue;
            }
            $navIds[] = $navId;
            $route = self::normalizeRoute((string) ($navEntry['route'] ?? ''));
            if ($route === null) {
                $errors[] = sprintf('Entrée de navigation "%s" : route invalide.', $navId);
                continue;
            }
            $label = trim((string) ($navEntry['label'] ?? ''));
            if ($label === '') {
                $errors[] = sprintf('Entrée de navigation "%s" : libellé absent.', $navId);
            }
            $navigation[] = [
                'id' => $navId,
                'label' => $label,
                'route' => $route,
                'order' => is_numeric($navEntry['order'] ?? null) ? (int) $navEntry['order'] : 100,
                'permission' => Str::isSlug((string) ($navEntry['permission'] ?? 'open'), 32) ? (string) ($navEntry['permission'] ?? 'open') : 'open',
                'icon' => isset($navEntry['icon']) ? (string) $navEntry['icon'] : null,
                'description' => isset($navEntry['description']) ? (string) $navEntry['description'] : null,
                'parent' => isset($navEntry['parent']) && $navEntry['parent'] !== '' ? (string) $navEntry['parent'] : null,
                'resource' => isset($navEntry['resource']) ? trim((string) $navEntry['resource'], '/') : 'screen/' . $navId,
            ];
        }
        // Parents et profondeur (3 niveaux maximum : module > page > sous-page)
        foreach ($navigation as $navItem) {
            if ($navItem['parent'] !== null) {
                if (!in_array($navItem['parent'], $navIds, true)) {
                    $errors[] = sprintf('Entrée "%s" : parent "%s" inconnu.', $navItem['id'], $navItem['parent']);
                } else {
                    $parent = self::findNav($navigation, $navItem['parent']);
                    if ($parent !== null && $parent['parent'] !== null) {
                        $grand = self::findNav($navigation, $parent['parent']);
                        if ($grand !== null && $grand['parent'] !== null) {
                            $errors[] = sprintf('Entrée "%s" : profondeur supérieure à trois niveaux.', $navItem['id']);
                        }
                    }
                }
            }
        }

        // Ressources protégées supplémentaires
        $resources = [];
        foreach (self::list($raw['resources'] ?? []) as $i => $resource) {
            if (!is_array($resource) || trim((string) ($resource['path'] ?? '')) === '') {
                $errors[] = sprintf('Ressource protégée n°%d invalide.', $i + 1);
                continue;
            }
            $path = trim(str_replace('\\', '/', (string) $resource['path']), '/');
            if (str_contains($path, '..')) {
                $errors[] = sprintf('Ressource protégée "%s" : chemin invalide.', $path);
                continue;
            }
            $resources[] = [
                'path' => $path,
                'label' => trim((string) ($resource['label'] ?? $path)),
                'kind' => in_array($resource['kind'] ?? '', ['screen', 'dataset', 'action', 'group'], true) ? (string) $resource['kind'] : 'action',
                'description' => isset($resource['description']) ? (string) $resource['description'] : null,
                'permissions' => array_values(array_filter(array_map('strval', self::list($resource['permissions'] ?? [])), static fn (string $p): bool => Str::isSlug($p, 32))),
            ];
        }

        // Ressources statiques
        $assets = ['css' => [], 'js' => [], 'vendor' => []];
        $rawAssets = is_array($raw['assets'] ?? null) ? $raw['assets'] : [];
        foreach (['css', 'js'] as $type) {
            foreach (self::list($rawAssets[$type] ?? []) as $asset) {
                $asset = str_replace('\\', '/', (string) $asset);
                if ($asset === '' || str_starts_with($asset, '/') || str_contains($asset, '..')) {
                    $errors[] = sprintf('Ressource %s "%s" : chemin relatif au module attendu.', $type, $asset);
                    continue;
                }
                if (!is_file($directory . '/' . $asset)) {
                    $errors[] = sprintf('Ressource %s "%s" introuvable.', $type, $asset);
                    continue;
                }
                $assets[$type][] = $asset;
            }
        }
        foreach (self::list($rawAssets['vendor'] ?? []) as $i => $vendor) {
            if (!is_array($vendor) || !in_array($vendor['type'] ?? '', ['css', 'js'], true) || trim((string) ($vendor['path'] ?? '')) === '') {
                $errors[] = sprintf('Ressource tierce n°%d invalide (type css|js et path requis).', $i + 1);
                continue;
            }
            $path = str_replace('\\', '/', (string) $vendor['path']);
            if (str_starts_with($path, '/') || str_contains($path, '..') || !is_file($directory . '/' . $path)) {
                $errors[] = sprintf('Ressource tierce "%s" introuvable ou chemin invalide.', $path);
                continue;
            }
            $assets['vendor'][] = ['type' => (string) $vendor['type'], 'path' => $path, 'name' => (string) ($vendor['name'] ?? basename($path)), 'version' => (string) ($vendor['version'] ?? ''), 'license' => (string) ($vendor['license'] ?? '')];
        }

        // Jeux de données
        $datasets = [];
        foreach (self::list($raw['datasets'] ?? []) as $i => $dataset) {
            if (!is_array($dataset)) {
                $errors[] = sprintf('Jeu de données n°%d invalide.', $i + 1);
                continue;
            }
            $code = (string) ($dataset['code'] ?? '');
            if ($id !== '' && !str_starts_with($code, $id . '.')) {
                $errors[] = sprintf('Jeu de données "%s" : le code doit être préfixé par "%s.".', $code, $id);
                continue;
            }
            $visibility = (string) ($dataset['visibility'] ?? '');
            if (!in_array($visibility, ['shared', 'private'], true)) {
                $errors[] = sprintf('Jeu de données "%s" : la classification "visibility" (shared|private) est obligatoire.', $code);
                continue;
            }
            $datasets[] = [
                'code' => $code,
                'name' => trim((string) ($dataset['name'] ?? $code)),
                'description' => isset($dataset['description']) ? (string) $dataset['description'] : null,
                'visibility' => $visibility,
                'tables' => array_map('strval', self::list($dataset['tables'] ?? [])),
                'fields' => is_array($dataset['fields'] ?? null) ? $dataset['fields'] : [],
                'operations' => array_values(array_intersect(array_map('strval', self::list($dataset['operations'] ?? ['read'])), ['read', 'create', 'update', 'delete'])),
                'version' => is_numeric($dataset['version'] ?? null) ? (int) $dataset['version'] : 1,
            ];
        }
        $consumes = array_values(array_filter(array_map('strval', self::list($raw['consumes'] ?? [])), static fn (string $c): bool => $c !== ''));

        $migrations = isset($raw['migrations']) ? trim(str_replace('\\', '/', (string) $raw['migrations']), '/') : 'migrations';
        if (str_contains($migrations, '..')) {
            $errors[] = 'Répertoire de migrations invalide.';
        }

        $badge = isset($raw['badge']) && $raw['badge'] !== '' ? self::normalizeRoute((string) $raw['badge']) : null;

        if ($errors !== []) {
            throw new ManifestException($id !== '' ? $id : $dirName, $errors);
        }

        return new self([
            'id' => $id,
            'name' => $name,
            'version' => $version,
            'description' => trim((string) ($raw['description'] ?? '')),
            'icon' => trim((string) ($raw['icon'] ?? 'module')),
            'group' => $group,
            'order' => $order,
            'status' => $status,
            'namespace' => $namespace,
            'entry' => $entry,
            'defaultRoute' => $defaultRoute,
            'permissions' => $permissions,
            'navigation' => $navigation,
            'resources' => $resources,
            'assets' => $assets,
            'datasets' => $datasets,
            'consumes' => $consumes,
            'migrations' => $migrations,
            'badge' => $badge,
            'author' => trim((string) ($raw['author'] ?? '')),
            'keepAlive' => (bool) ($raw['keepAlive'] ?? false),
        ], $directory);
    }

    public function id(): string
    {
        return $this->data['id'];
    }

    public function name(): string
    {
        return $this->data['name'];
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    public function entryClass(): string
    {
        return $this->data['namespace'] . '\\' . $this->data['entry'];
    }

    public function namespace(): string
    {
        return $this->data['namespace'];
    }

    public function sourceDirectory(): string
    {
        return $this->directory . '/src';
    }

    public function templatesDirectory(): string
    {
        return $this->directory . '/templates';
    }

    public function migrationsDirectory(): string
    {
        return $this->directory . '/' . $this->data['migrations'];
    }

    public function defaultRoute(): string
    {
        return $this->data['defaultRoute'];
    }

    /** @return list<array<string, mixed>> */
    public function navigation(): array
    {
        return $this->data['navigation'];
    }

    /** @return list<array<string, mixed>> */
    public function permissions(): array
    {
        return $this->data['permissions'];
    }

    /** @return list<array<string, mixed>> */
    public function datasets(): array
    {
        return $this->data['datasets'];
    }

    /** @return list<array<string, mixed>> */
    public function resources(): array
    {
        return $this->data['resources'];
    }

    /** @return array{css: list<string>, js: list<string>, vendor: list<array<string, string>>} */
    public function assets(): array
    {
        return $this->data['assets'];
    }

    /** @return list<string> */
    public function consumes(): array
    {
        return $this->data['consumes'];
    }

    public function badgeRoute(): ?string
    {
        return $this->data['badge'];
    }

    public function keepAlive(): bool
    {
        return $this->data['keepAlive'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** Empreinte du manifeste pour détecter un changement nécessitant une synchronisation. */
    public function hash(): string
    {
        return sha1(Json::encode($this->data));
    }

    /** Normalise une route interne : segments séparés par "/", sans "..", sans slash initial. */
    public static function normalizeRoute(string $route): ?string
    {
        $route = trim(str_replace('\\', '/', $route), '/');
        if ($route === '') {
            return 'index';
        }
        if (str_contains($route, '..') || preg_match('#^[A-Za-z0-9_.\-/{}*]+$#', $route) !== 1) {
            return null;
        }
        return $route;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param list<array<string, mixed>> $navigation
     * @return array<string, mixed>|null
     */
    private static function findNav(array $navigation, string $id): ?array
    {
        foreach ($navigation as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }
}
