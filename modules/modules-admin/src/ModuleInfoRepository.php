<?php

declare(strict_types=1);

namespace Atelier\Modules\ModulesAdmin;

use Atelier\Persistence\Database;
use Atelier\Support\Json;

/**
 * Lectures en base nécessaires à l'administration des modules : état des migrations,
 * ressources ACL et permissions synchronisées, erreurs récentes du journal d'activité.
 *
 * Seul endroit du module où du SQL est écrit.
 */
final class ModuleInfoRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Migrations appliquées pour un périmètre (identifiant de module ou "core").
     *
     * @return list<array{version: int, name: string, applied_at: string}>
     */
    public function appliedMigrations(string $scope): array
    {
        if (!$this->db->tableExists('migrations')) {
            return [];
        }
        $rows = $this->db->select('SELECT version, name, applied_at FROM migrations WHERE scope = :s ORDER BY version', ['s' => $scope]);
        return array_map(static fn (array $r): array => [
            'version' => (int) $r['version'],
            'name' => (string) $r['name'],
            'applied_at' => (string) $r['applied_at'],
        ], $rows);
    }

    /** Version courante des migrations d'un périmètre (0 si aucune). */
    public function currentMigrationVersion(string $scope): int
    {
        $applied = $this->appliedMigrations($scope);
        return $applied === [] ? 0 : max(array_column($applied, 'version'));
    }

    /**
     * Ressources ACL synchronisées pour un module (présentes ou disparues).
     *
     * @return list<array<string, mixed>>
     */
    public function aclResources(string $moduleId): array
    {
        if (!$this->db->tableExists('resources')) {
            return [];
        }
        $rows = $this->db->select('SELECT path, kind, label, permissions, is_present FROM resources WHERE module_id = :m ORDER BY path', ['m' => $moduleId]);
        foreach ($rows as &$row) {
            $row['permissions'] = $row['permissions'] ? Json::decode((string) $row['permissions']) : [];
            $row['is_present'] = (int) $row['is_present'] === 1;
        }
        unset($row);
        return $rows;
    }

    /**
     * Permissions propres au module enregistrées en base.
     *
     * @return list<array<string, mixed>>
     */
    public function permissions(string $moduleId): array
    {
        if (!$this->db->tableExists('permissions')) {
            return [];
        }
        return $this->db->select('SELECT code, label, description FROM permissions WHERE module_id = :m ORDER BY code', ['m' => $moduleId]);
    }

    /**
     * Dernières entrées en erreur, refusées ou en échec du journal d'activité pour un module.
     *
     * @return list<array<string, mixed>>
     */
    public function recentErrors(string $moduleId, int $limit = 10): array
    {
        if (!$this->db->tableExists('activity_log')) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        return $this->db->select(
            "SELECT id, occurred_at, username, action, result, resource_ref, message, error_id
             FROM activity_log
             WHERE module_id = :m AND result IN ('error', 'denied', 'failure')
             ORDER BY occurred_at DESC, id DESC
             LIMIT $limit",
            ['m' => $moduleId]
        );
    }

    /** Nombre total d'entrées du journal d'activité produites par un module. */
    public function activityCount(string $moduleId): int
    {
        if (!$this->db->tableExists('activity_log')) {
            return 0;
        }
        return $this->db->count('SELECT COUNT(*) FROM activity_log WHERE module_id = :m', ['m' => $moduleId]);
    }

    /**
     * Existence et volume des tables déclarées par les jeux de données (informations d'exploitation).
     *
     * @param list<string> $tables
     * @return array<string, int|null> table => nombre de lignes, null si absente
     */
    public function tableCounts(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || !$this->db->tableExists($table)) {
                $result[$table] = null;
                continue;
            }
            $result[$table] = $this->db->count('SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($table));
        }
        return $result;
    }
}
