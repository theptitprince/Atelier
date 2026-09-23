<?php

declare(strict_types=1);

namespace Atelier\Modules;

use Atelier\Persistence\Database;
use Atelier\Persistence\Migrator;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Clock;
use Atelier\Support\Files;
use Atelier\Support\Json;

/**
 * Synchronise en base ce que déclarent les manifestes : ressources protégées, permissions
 * propres aux modules, catalogue des jeux de données et dépendances. Applique aussi les
 * migrations du noyau et des modules.
 *
 * La synchronisation est déclenchée lorsque l'empreinte des manifestes change (fichier
 * var/cache/manifests.hash) ou explicitement depuis la console / l'administration.
 */
final class ModuleSynchronizer
{
    public function __construct(
        private readonly Database $db,
        private readonly ModuleManager $modules,
        private readonly string $coreMigrationsDirectory,
        private readonly string $cacheDirectory,
        private readonly ?\Atelier\Activity\ActivityLog $activity = null,
    ) {
    }

    /** Synchronisation si nécessaire (empreinte changée). Retourne vrai si exécutée. */
    public function syncIfNeeded(): bool
    {
        $hashFile = $this->cacheDirectory . '/manifests.hash';
        $current = $this->modules->manifestsHash();
        $previous = is_file($hashFile) ? trim((string) file_get_contents($hashFile)) : '';
        if ($previous === $current) {
            return false;
        }
        $this->syncAll();
        Files::writeAtomic($hashFile, $current);
        return true;
    }

    /** Invalide l'empreinte pour forcer la prochaine synchronisation. */
    public function invalidate(): void
    {
        $hashFile = $this->cacheDirectory . '/manifests.hash';
        if (is_file($hashFile)) {
            @unlink($hashFile);
        }
    }

    /**
     * Migrations du noyau puis de chaque module valide, synchronisation des déclarations.
     *
     * @return list<string> journal des opérations
     */
    public function syncAll(): array
    {
        $log = [];
        $migrator = new Migrator($this->db);
        foreach ($migrator->migrate('core', $this->coreMigrationsDirectory) as $done) {
            $log[] = 'Migration appliquée : ' . $done;
        }
        // Un module dont l'installation échoue est isolé, les autres continuent : même principe
        // qu'un manifeste invalide (cahier des charges §6.2.1).
        $this->modules->clearFailures();
        $failed = [];
        foreach ($this->modules->all() as $descriptor) {
            if ($descriptor->manifest === null) {
                continue;
            }
            $directory = $descriptor->manifest->migrationsDirectory();
            if (!is_dir($directory)) {
                continue;
            }
            try {
                foreach ($migrator->migrate($descriptor->id, $directory) as $done) {
                    $log[] = 'Migration appliquée : ' . $done;
                }
            } catch (\Throwable $e) {
                $reason = 'Installation impossible : ' . $e->getMessage();
                $failed[$descriptor->id] = $reason;
                $this->modules->markFailed($descriptor->id, $reason);
                $log[] = 'ÉCHEC du module ' . $descriptor->id . ' : ' . $e->getMessage();
                $this->activity?->technical($descriptor->id, 'module.migration_failed', \Atelier\Activity\ActivityLog::ERROR, $reason, ['exception' => $e::class], 'module:' . $descriptor->id);
            }
        }
        $this->syncResources();
        $this->syncPermissions();
        $this->syncDatasets();
        $log[] = 'Ressources, permissions et catalogue synchronisés.';

        if ($this->activity !== null) {
            $applied = array_values(array_filter($log, static fn (string $l): bool => str_starts_with($l, 'Migration appliquée')));
            foreach ($applied as $line) {
                $this->activity->technical('core', 'module.migration', \Atelier\Activity\ActivityLog::SUCCESS, $line);
            }
            $invalid = [];
            foreach ($this->modules->all() as $descriptor) {
                if (!$descriptor->isValid()) {
                    $invalid[] = $descriptor->id;
                    $this->activity->technical($descriptor->id, 'module.manifest_invalid', \Atelier\Activity\ActivityLog::ERROR, 'Manifeste invalide : ' . implode(' ', $descriptor->errors), ['errors' => $descriptor->errors], 'module:' . $descriptor->id);
                }
            }
            $this->activity->technical('core', 'module.sync', \Atelier\Activity\ActivityLog::SUCCESS, sprintf('Synchronisation des manifestes : %d module(s), %d migration(s), %d invalide(s)', count($this->modules->all()), count($applied), count($invalid)), ['modules' => array_keys($this->modules->all()), 'invalid' => $invalid]);
        }
        return $log;
    }

    /**
     * Ressources protégées : atelier/{module}, atelier/{module}/{nav.resource}, atelier/{module}/data/{dataset},
     * atelier/{module}/{resource.path}. Les ressources disparues sont marquées is_present = 0.
     */
    public function syncResources(): void
    {
        $now = Clock::utc();
        $present = [AclService::ROOT];
        $this->db->transaction(function (Database $db) use ($now, &$present): void {
            foreach ($this->modules->all() as $descriptor) {
                $manifest = $descriptor->manifest;
                if ($manifest === null) {
                    continue;
                }
                $modulePath = AclService::module($descriptor->id);
                $permissionCodes = array_merge(AclService::GENERIC_PERMISSIONS, array_column($manifest->permissions(), 'code'));
                $this->upsertResource($db, $modulePath, AclService::ROOT, $descriptor->id, 'module', $manifest->name(), $manifest->get('description', ''), $permissionCodes, $now);
                $present[] = $modulePath;

                foreach ($manifest->navigation() as $entry) {
                    $path = $modulePath . '/' . $entry['resource'];
                    $this->ensureIntermediate($db, $path, $modulePath, $descriptor->id, $now, $present);
                    $this->upsertResource($db, $path, self::parentOf($path), $descriptor->id, 'screen', $entry['label'], $entry['description'], ['view', $entry['permission']], $now);
                    $present[] = $path;
                }
                foreach ($manifest->datasets() as $dataset) {
                    $short = substr($dataset['code'], strlen($descriptor->id) + 1);
                    $path = $modulePath . '/data/' . $short;
                    $this->ensureIntermediate($db, $path, $modulePath, $descriptor->id, $now, $present);
                    $ops = array_map(static fn (string $o): string => $o, $dataset['operations']);
                    $this->upsertResource($db, $path, self::parentOf($path), $descriptor->id, 'dataset', $dataset['name'], $dataset['description'], array_values(array_unique(array_merge(['view'], $ops, ['import', 'export']))), $now);
                    $present[] = $path;
                }
                foreach ($manifest->resources() as $resource) {
                    $path = $modulePath . '/' . $resource['path'];
                    $this->ensureIntermediate($db, $path, $modulePath, $descriptor->id, $now, $present);
                    $this->upsertResource($db, $path, self::parentOf($path), $descriptor->id, $resource['kind'], $resource['label'], $resource['description'], $resource['permissions'] ?: ['execute'], $now);
                    $present[] = $path;
                }
            }
            $rows = $db->select('SELECT path FROM resources');
            foreach ($rows as $row) {
                $isPresent = in_array($row['path'], $present, true) ? 1 : 0;
                $db->update('resources', ['is_present' => $isPresent], 'path = :p', ['p' => $row['path']]);
            }
        });
    }

    public function syncPermissions(): void
    {
        foreach ($this->modules->all() as $descriptor) {
            if ($descriptor->manifest === null) {
                continue;
            }
            foreach ($descriptor->manifest->permissions() as $permission) {
                $existing = $this->db->selectOne('SELECT id FROM permissions WHERE code = :c AND module_id = :m', ['c' => $permission['code'], 'm' => $descriptor->id]);
                if ($existing === null) {
                    $this->db->insert('permissions', ['code' => $permission['code'], 'module_id' => $descriptor->id, 'label' => $permission['label'], 'description' => $permission['description']]);
                } else {
                    $this->db->update('permissions', ['label' => $permission['label'], 'description' => $permission['description']], 'id = :id', ['id' => (int) $existing['id']]);
                }
            }
        }
    }

    public function syncDatasets(): void
    {
        $now = Clock::utc();
        $presentCodes = [];
        $this->db->transaction(function (Database $db) use ($now, &$presentCodes): void {
            $db->execute('DELETE FROM dataset_dependencies');
            foreach ($this->modules->all() as $descriptor) {
                $manifest = $descriptor->manifest;
                if ($manifest === null) {
                    continue;
                }
                foreach ($manifest->datasets() as $dataset) {
                    $presentCodes[] = $dataset['code'];
                    $data = [
                        'module_id' => $descriptor->id,
                        'name' => $dataset['name'],
                        'description' => $dataset['description'],
                        'visibility' => $dataset['visibility'],
                        'tables' => Json::encode($dataset['tables']),
                        'fields' => Json::encode($dataset['fields']),
                        'operations' => Json::encode($dataset['operations']),
                        'structure_version' => $dataset['version'],
                        'is_present' => 1,
                        'updated_at' => $now,
                    ];
                    $existing = $db->selectOne('SELECT id FROM datasets WHERE code = :c', ['c' => $dataset['code']]);
                    if ($existing === null) {
                        $db->insert('datasets', ['code' => $dataset['code']] + $data);
                    } else {
                        $db->update('datasets', $data, 'id = :id', ['id' => (int) $existing['id']]);
                    }
                    $db->insert('dataset_dependencies', ['dataset_code' => $dataset['code'], 'module_id' => $descriptor->id, 'role' => 'producer', 'updated_at' => $now]);
                }
                foreach ($manifest->consumes() as $code) {
                    $db->insert('dataset_dependencies', ['dataset_code' => $code, 'module_id' => $descriptor->id, 'role' => 'consumer', 'updated_at' => $now]);
                }
            }
            foreach ($db->select('SELECT code FROM datasets') as $row) {
                if (!in_array($row['code'], $presentCodes, true)) {
                    $db->update('datasets', ['is_present' => 0, 'updated_at' => $now], 'code = :c', ['c' => $row['code']]);
                }
            }
        });
    }

    /** @param list<string> $permissions */
    private function upsertResource(Database $db, string $path, ?string $parent, ?string $moduleId, string $kind, string $label, ?string $description, array $permissions, string $now): void
    {
        $data = [
            'parent_path' => $parent,
            'module_id' => $moduleId,
            'kind' => $kind,
            'label' => $label,
            'description' => $description,
            'permissions' => Json::encode(array_values(array_unique($permissions))),
            'is_present' => 1,
            'updated_at' => $now,
        ];
        $existing = $db->selectOne('SELECT id FROM resources WHERE path = :p', ['p' => $path]);
        if ($existing === null) {
            $db->insert('resources', ['path' => $path] + $data);
        } else {
            $db->update('resources', $data, 'id = :id', ['id' => (int) $existing['id']]);
        }
    }

    /**
     * Crée les nœuds intermédiaires (ex. atelier/notes/screen, atelier/notes/data) comme groupes.
     *
     * @param list<string> $present
     */
    private function ensureIntermediate(Database $db, string $path, string $modulePath, string $moduleId, string $now, array &$present): void
    {
        $relative = substr($path, strlen($modulePath) + 1);
        $segments = explode('/', $relative);
        array_pop($segments);
        $current = $modulePath;
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            if (!in_array($current, $present, true)) {
                $label = match ($segment) {
                    'screen' => 'Écrans',
                    'data' => 'Jeux de données',
                    'action' => 'Actions',
                    default => ucfirst($segment),
                };
                $this->upsertResource($db, $current, self::parentOf($current), $moduleId, 'group', $label, null, AclService::GENERIC_PERMISSIONS, $now);
                $present[] = $current;
            }
        }
    }

    private static function parentOf(string $path): ?string
    {
        $pos = strrpos($path, '/');
        return $pos === false ? null : substr($path, 0, $pos);
    }
}
