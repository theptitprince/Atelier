<?php

declare(strict_types=1);

namespace Atelier\Modules\Geo;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès à la table geo_point. Les points sont partagés entre tous les utilisateurs autorisés :
 * les contrôles de droits sont réalisés par le module et par le service intermodule.
 */
final class GeoPointRepository
{
    public const TABLE = 'geo_point';

    /** @var array<string, string> colonne de tri autorisée => expression SQL */
    private const SORTS = [
        'name' => 'p.name',
        'code' => 'p.code',
        'created_at' => 'p.created_at',
        'updated_at' => 'p.updated_at',
        'altitude' => 'p.altitude',
    ];

    private const COLUMNS = 'p.id, p.code, p.name, p.latitude, p.longitude, p.altitude, p.address, p.description, p.created_by, p.created_at, p.updated_at, p.deleted_at';

    public function __construct(private readonly Database $db)
    {
    }

    public static function isSortable(string $column): bool
    {
        return isset(self::SORTS[$column]);
    }

    /**
     * Points actifs, paginés, avec recherche sur le nom, le code et l'adresse.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(string $search, int $page, int $perPage, string $sort = 'name', string $direction = 'asc'): array
    {
        [$where, $params] = $this->whereActive($search);
        $orderBy = (self::SORTS[$sort] ?? self::SORTS['name']) . (strtolower($direction) === 'desc' ? ' DESC' : ' ASC');
        $total = $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . " p WHERE $where", $params);
        $perPage = max(1, min(500, $perPage));
        // Garde-fou : un numéro de page démesuré déborderait l'entier et rendrait la clause OFFSET invalide.
        $offset = max(0, (min($page, 1000000) - 1) * $perPage);
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . " p WHERE $where ORDER BY $orderBy, p.id ASC LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Points actifs situés à moins de $radiusKm du centre, triés par distance croissante (clé distance_km).
     *
     * @return list<array<string, mixed>>
     */
    public function nearby(Coordinates $center, float $radiusKm, int $limit = 50, ?int $excludeId = null): array
    {
        [$latMin, $latMax, $lonMin, $lonMax] = $center->boundingBox($radiusKm);
        $params = ['latMin' => $latMin, 'latMax' => $latMax, 'lonMin' => $lonMin, 'lonMax' => $lonMax];
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' p WHERE p.deleted_at IS NULL AND p.latitude BETWEEN :latMin AND :latMax AND p.longitude BETWEEN :lonMin AND :lonMax';
        if ($excludeId !== null) {
            $sql .= ' AND p.id <> :exclude';
            $params['exclude'] = $excludeId;
        }
        $rows = [];
        foreach ($this->db->select($sql, $params) as $row) {
            $distance = Coordinates::haversineKm($center->latitude, $center->longitude, (float) $row['latitude'], (float) $row['longitude']);
            if ($distance <= $radiusKm) {
                $row['distance_km'] = $distance;
                $rows[] = $row;
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['distance_km'] <=> $b['distance_km'] ?: strcmp((string) $a['name'], (string) $b['name']));
        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Recherche courte (sélecteurs, autocomplétion) sur le nom, le code et l'adresse.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, int $limit = 20): array
    {
        [$where, $params] = $this->whereActive($term);
        return $this->db->select('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . " p WHERE $where ORDER BY p.name ASC, p.id ASC LIMIT " . max(1, min(200, $limit)), $params);
    }

    /** @return list<array<string, mixed>> tous les points actifs (export, sélecteurs) */
    public function all(): array
    {
        return $this->db->select('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' p WHERE p.deleted_at IS NULL ORDER BY p.name ASC, p.id ASC');
    }

    public function countActive(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE deleted_at IS NULL');
    }

    public function countAll(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $includeDeleted = false): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' p WHERE p.id = :id', ['id' => $id]);
        if ($row === null || (!$includeDeleted && $row['deleted_at'] !== null)) {
            return null;
        }
        return $row;
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' p WHERE p.code = :c AND p.deleted_at IS NULL', ['c' => $code]);
    }

    /**
     * Points par identifiants (ordre non garanti), actifs seulement.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> indexés par identifiant
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params['id' . $i] = $id;
        }
        $result = [];
        foreach ($this->db->select('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' p WHERE p.deleted_at IS NULL AND p.id IN (' . implode(', ', $placeholders) . ')', $params) as $row) {
            $result[(int) $row['id']] = $row;
        }
        return $result;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $params = ['c' => $code];
        $sql = 'SELECT id FROM ' . self::TABLE . ' WHERE code = :c';
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return $this->db->scalar($sql, $params) !== null;
    }

    /** @param array<string, mixed> $data name, code, latitude, longitude, altitude, address, description */
    public function create(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::TABLE, $this->columns($data) + [
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): string
    {
        $now = Clock::utc();
        $this->db->update(self::TABLE, $this->columns($data) + ['updated_at' => $now], 'id = :id AND deleted_at IS NULL', ['id' => $id]);
        return $now;
    }

    public function softDelete(int $id): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => Clock::utc()], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique d'un point en corbeille. */
    public function purge(int $id): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** @return list<array<string, mixed>> points en corbeille depuis moins de $retentionDays jours */
    public function trashed(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return $this->db->select('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' p WHERE p.deleted_at IS NOT NULL AND p.deleted_at >= :l ORDER BY p.deleted_at DESC', ['l' => $limit]);
    }

    /** @return list<int> identifiants des points en corbeille depuis plus de $retentionDays jours */
    public function expiredTrashIds(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        $rows = $this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]);
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    public function deleteById(int $id): void
    {
        $this->db->delete(self::TABLE, 'id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return [
            'code' => isset($data['code']) && $data['code'] !== '' ? (string) $data['code'] : null,
            'name' => (string) $data['name'],
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'altitude' => isset($data['altitude']) && $data['altitude'] !== '' && $data['altitude'] !== null ? (float) $data['altitude'] : null,
            'address' => isset($data['address']) && $data['address'] !== '' ? (string) $data['address'] : null,
            'description' => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function whereActive(string $search): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(' . $this->db->lower('p.name') . ' LIKE :search OR ' . $this->db->lower("COALESCE(p.code, '')") . ' LIKE :search OR ' . $this->db->lower("COALESCE(p.address, '')") . ' LIKE :search)';
            $params['search'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
        }
        return [implode(' AND ', $where), $params];
    }
}
