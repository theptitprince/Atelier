<?php

declare(strict_types=1);

namespace Atelier\Modules\Maintenance;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès à la table maintenance_log : interventions réalisées (historique). Une intervention est
 * rattachée à un équipement et, facultativement, à la tâche qui l'a déclenchée.
 */
final class LogRepository
{
    public const TABLE = 'maintenance_log';

    private const COLUMNS = 'l.id, l.asset_id, l.job_id, l.done_at, l.meter_value, l.title, l.notes, l.cost, l.performed_by, l.created_by, l.created_at, l.updated_at, a.name AS asset_name, a.meter_unit AS asset_meter_unit, j.title AS job_title';
    private const FROM = ' FROM maintenance_log l INNER JOIN maintenance_asset a ON a.id = l.asset_id LEFT JOIN maintenance_job j ON j.id = l.job_id';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . self::FROM . ' WHERE l.id = :id', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array{asset: int, job: int, q: string, year: int} $criteria
     * @return array{rows: list<array<string, mixed>>, total: int, cost: int}
     */
    public function paginate(array $criteria, int $page, int $perPage): array
    {
        [$where, $params] = $this->where($criteria);
        $total = $this->db->count('SELECT COUNT(*)' . self::FROM . " WHERE $where", $params);
        $cost = (int) ($this->db->scalar('SELECT COALESCE(SUM(l.cost), 0)' . self::FROM . " WHERE $where", $params) ?? 0);
        $perPage = max(1, min(500, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select('SELECT ' . self::COLUMNS . self::FROM . " WHERE $where ORDER BY l.done_at DESC, l.id DESC LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => array_map([$this, 'hydrate'], $rows), 'total' => $total, 'cost' => $cost];
    }

    /**
     * @param array{asset: int, job: int, q: string, year: int} $criteria
     * @return list<array<string, mixed>>
     */
    public function export(array $criteria, int $limit = 10000): array
    {
        [$where, $params] = $this->where($criteria);
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . " WHERE $where ORDER BY l.done_at DESC, l.id DESC LIMIT " . max(1, $limit), $params));
    }

    /** @return list<array<string, mixed>> interventions d'un équipement, les plus récentes d'abord */
    public function forAsset(int $assetId, int $limit = 200): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE l.asset_id = :a ORDER BY l.done_at DESC, l.id DESC LIMIT ' . max(1, $limit), ['a' => $assetId]));
    }

    /** @return list<array<string, mixed>> interventions issues d'une tâche */
    public function forJob(int $jobId, int $limit = 100): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE l.job_id = :j ORDER BY l.done_at DESC, l.id DESC LIMIT ' . max(1, $limit), ['j' => $jobId]));
    }

    /** @return list<array<string, mixed>> dernières interventions tous équipements confondus */
    public function recent(int $limit = 8): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . self::FROM . ' WHERE a.deleted_at IS NULL ORDER BY l.done_at DESC, l.id DESC LIMIT ' . max(1, $limit)));
    }

    /** Coût total des interventions réalisées depuis une date (AAAA-MM-JJ), en centimes. */
    public function costSince(string $day): int
    {
        return (int) ($this->db->scalar('SELECT COALESCE(SUM(l.cost), 0)' . self::FROM . ' WHERE a.deleted_at IS NULL AND l.done_at >= :d', ['d' => $day]) ?? 0);
    }

    /** @return list<int> années présentes dans l'historique, décroissantes */
    public function years(): array
    {
        $rows = $this->db->select('SELECT DISTINCT SUBSTR(done_at, 1, 4) AS y FROM ' . self::TABLE . ' ORDER BY y DESC');
        return array_values(array_filter(array_map(static fn (array $r): int => (int) $r['y'], $rows), static fn (int $y): bool => $y > 0));
    }

    public function countAll(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::TABLE, $this->columns($data) + ['created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update(self::TABLE, $this->columns($data) + ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id', ['id' => $id]) > 0;
    }

    /** Détache les interventions d'une tâche supprimée (l'historique est conservé). */
    public function detachJob(int $jobId): int
    {
        return $this->db->update(self::TABLE, ['job_id' => null], 'job_id = :j', ['j' => $jobId]);
    }

    /** @return list<int> */
    public function idsForAsset(int $assetId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE asset_id = :a', ['a' => $assetId]));
    }

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
            'job_id' => $int('job_id'),
            'done_at' => (string) $data['done_at'],
            'meter_value' => $int('meter_value'),
            'title' => (string) $data['title'],
            'notes' => $string('notes'),
            'cost' => $int('cost'),
            'performed_by' => $string('performed_by'),
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function where(array $criteria): array
    {
        $where = ['a.deleted_at IS NULL'];
        $params = [];
        if (($criteria['asset'] ?? 0) > 0) {
            $where[] = 'l.asset_id = :asset';
            $params['asset'] = (int) $criteria['asset'];
        }
        if (($criteria['job'] ?? 0) > 0) {
            $where[] = 'l.job_id = :job';
            $params['job'] = (int) $criteria['job'];
        }
        if (($criteria['year'] ?? 0) > 0) {
            $where[] = 'l.done_at >= :y1 AND l.done_at <= :y2';
            $params['y1'] = sprintf('%04d-01-01', (int) $criteria['year']);
            $params['y2'] = sprintf('%04d-12-31', (int) $criteria['year']);
        }
        $q = trim((string) ($criteria['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(' . $this->db->lower('l.title') . ' LIKE :q OR ' . $this->db->lower("COALESCE(l.performed_by, '')") . ' LIKE :q OR ' . $this->db->lower('a.name') . ' LIKE :q)';
            $params['q'] = '%' . mb_strtolower($q, 'UTF-8') . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['asset_id'] = (int) $row['asset_id'];
        foreach (['job_id', 'meter_value', 'cost', 'created_by'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        return $row;
    }
}
