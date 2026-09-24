<?php

declare(strict_types=1);

namespace Atelier\Modules\Maintenance;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès à la table maintenance_job : tâches d'entretien planifiées (preventive) et pannes
 * à corriger (corrective). Chaque ligne porte sa fiche d'intervention et ses règles d'échéance.
 * Les lignes sont retournées avec les colonnes utiles de l'équipement (préfixe asset_).
 * Suppression logique (deleted_at = corbeille) : les lectures excluent les lignes en corbeille
 * sauf demande explicite ; la purge physique est réalisée par le module (rétention ou corbeille).
 */
final class JobRepository
{
    public const TABLE = 'maintenance_job';

    public const KINDS = ['preventive' => 'Entretien planifié', 'corrective' => 'Panne ou défaut'];
    public const PRIORITIES = ['low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute', 'urgent' => 'Urgente'];
    public const STATUSES = ['open' => 'Ouverte', 'closed' => 'Clôturée'];

    private const COLUMNS = 'j.id, j.asset_id, j.title, j.kind, j.priority, j.status, j.description, j.parts, j.contacts, j.tools, j.estimated_minutes, j.estimated_cost, j.interval_days, j.interval_meter, j.next_due_at, j.next_due_meter, j.lead_days, j.lead_meter, j.last_done_at, j.last_done_meter, j.closed_at, j.created_by, j.created_at, j.updated_at, j.deleted_at, a.name AS asset_name, a.category AS asset_category, a.meter_unit AS asset_meter_unit, a.meter_value AS asset_meter_value, a.deleted_at AS asset_deleted_at';
    private const FROM = ' FROM maintenance_job j INNER JOIN maintenance_asset a ON a.id = j.asset_id';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $includeDeleted = false): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::FROM . ' WHERE j.id = :id', ['id' => $id]);
        if ($row === null || (!$includeDeleted && $row['deleted_at'] !== null)) {
            return null;
        }
        return $this->hydrate($row);
    }

    /** Tâche en corbeille (quel que soit l'état de son équipement). @return array<string, mixed>|null */
    public function findTrashed(int $id): ?array
    {
        $row = $this->find($id, true);
        return $row === null || $row['deleted_at'] === null ? null : $row;
    }

    /**
     * Tâches filtrées, sans pagination (les états d'échéance sont calculés par le module, qui trie ensuite).
     *
     * @param array{asset: int, kind: string, status: string, q: string} $criteria
     * @return list<array<string, mixed>>
     */
    public function search(array $criteria, int $limit = 1000): array
    {
        $where = ['j.deleted_at IS NULL', 'a.deleted_at IS NULL'];
        $params = [];
        if (($criteria['asset'] ?? 0) > 0) {
            $where[] = 'j.asset_id = :asset';
            $params['asset'] = (int) $criteria['asset'];
        }
        if (($criteria['kind'] ?? '') !== '' && isset(self::KINDS[$criteria['kind']])) {
            $where[] = 'j.kind = :kind';
            $params['kind'] = $criteria['kind'];
        }
        if (($criteria['status'] ?? '') !== '' && isset(self::STATUSES[$criteria['status']])) {
            $where[] = 'j.status = :status';
            $params['status'] = $criteria['status'];
        }
        $q = trim((string) ($criteria['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(' . $this->db->lower('j.title') . ' LIKE :q OR ' . $this->db->lower('a.name') . ' LIKE :q)';
            $params['q'] = '%' . mb_strtolower($q, 'UTF-8') . '%';
        }
        $rows = $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY j.status ASC, j.next_due_at ASC, j.id ASC LIMIT ' . max(1, min(5000, $limit)), $params);
        return array_map([$this, 'hydrate'], $rows);
    }

    /** @return list<array<string, mixed>> tâches (hors corbeille) d'un équipement */
    public function forAsset(int $assetId, bool $includeClosed = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . self::FROM . ' WHERE j.asset_id = :asset AND j.deleted_at IS NULL' . ($includeClosed ? '' : " AND j.status = 'open'") . ' ORDER BY j.status ASC, j.next_due_at ASC, j.id ASC';
        return array_map([$this, 'hydrate'], $this->db->select($sql, ['asset' => $assetId]));
    }

    /** @return list<array<string, mixed>> toutes les tâches ouvertes (hors corbeille) d'équipements actifs */
    public function open(): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . " WHERE j.status = 'open' AND j.deleted_at IS NULL AND a.deleted_at IS NULL ORDER BY j.next_due_at ASC, j.id ASC"));
    }

    public function countOpen(?string $kind = null): int
    {
        $params = [];
        $sql = 'SELECT COUNT(*)' . self::FROM . " WHERE j.status = 'open' AND j.deleted_at IS NULL AND a.deleted_at IS NULL";
        if ($kind !== null) {
            $sql .= ' AND j.kind = :kind';
            $params['kind'] = $kind;
        }
        return $this->db->count($sql, $params);
    }

    public function countAll(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::TABLE, $this->columns($data) + [
            'status' => 'open',
            'last_done_at' => null,
            'last_done_meter' => null,
            'closed_at' => null,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update(self::TABLE, $this->columns($data) + ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    /**
     * Enregistre la réalisation d'une intervention : dernière réalisation, prochaine échéance, état.
     *
     * @param array{status: string, next_due_at: ?string, next_due_meter: ?int} $schedule
     */
    public function markDone(int $id, string $doneAt, ?int $doneMeter, array $schedule): void
    {
        $now = Clock::utc();
        $this->db->update(self::TABLE, [
            'last_done_at' => $doneAt,
            'last_done_meter' => $doneMeter,
            'next_due_at' => $schedule['next_due_at'],
            'next_due_meter' => $schedule['next_due_meter'],
            'status' => $schedule['status'],
            'closed_at' => $schedule['status'] === 'closed' ? $now : null,
            'updated_at' => $now,
        ], 'id = :id', ['id' => $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $now = Clock::utc();
        $this->db->update(self::TABLE, ['status' => $status, 'closed_at' => $status === 'closed' ? $now : null, 'updated_at' => $now], 'id = :id', ['id' => $id]);
    }

    // ----- Corbeille -----

    public function softDelete(int $id): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => Clock::utc()], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique d'une tâche en corbeille. */
    public function purge(int $id): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** @return list<array<string, mixed>> tâches en corbeille depuis moins de $retentionDays jours (équipement inclus, même en corbeille) */
    public function trashed(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE j.deleted_at IS NOT NULL AND j.deleted_at >= :l ORDER BY j.deleted_at DESC, j.id DESC', ['l' => $limit]));
    }

    /** @return list<int> tâches en corbeille depuis plus de $retentionDays jours */
    public function expiredTrashIds(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]));
    }

    /** @return list<int> identifiants de toutes les tâches d'un équipement (corbeille comprise, pour la purge en cascade) */
    public function idsForAsset(int $assetId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE asset_id = :a', ['a' => $assetId]));
    }

    /** @return list<int> tâches d'un équipement qui ne sont pas en corbeille pour leur propre compte (restauration de l'équipement) */
    public function liveIdsForAsset(int $assetId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE asset_id = :a AND deleted_at IS NULL', ['a' => $assetId]));
    }

    /** Suppression physique de toutes les tâches d'un équipement (purge en cascade). */
    public function deleteForAsset(int $assetId): int
    {
        return $this->db->delete(self::TABLE, 'asset_id = :a', ['a' => $assetId]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;
        $int = static fn (string $key): ?int => isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null ? (int) $data[$key] : null;
        return [
            'asset_id' => (int) $data['asset_id'],
            'title' => (string) $data['title'],
            'kind' => (string) $data['kind'],
            'priority' => (string) ($data['priority'] ?? 'normal'),
            'description' => $string('description'),
            'parts' => $string('parts'),
            'contacts' => $string('contacts'),
            'tools' => $string('tools'),
            'estimated_minutes' => $int('estimated_minutes'),
            'estimated_cost' => $int('estimated_cost'),
            'interval_days' => $int('interval_days'),
            'interval_meter' => $int('interval_meter'),
            'next_due_at' => $string('next_due_at'),
            'next_due_meter' => $int('next_due_meter'),
            'lead_days' => max(0, (int) ($data['lead_days'] ?? 14)),
            'lead_meter' => $int('lead_meter'),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        foreach (['id', 'asset_id', 'lead_days'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['estimated_minutes', 'estimated_cost', 'interval_days', 'interval_meter', 'next_due_meter', 'lead_meter', 'last_done_meter', 'asset_meter_value', 'created_by'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        return $row;
    }
}
