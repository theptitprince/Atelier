<?php

declare(strict_types=1);

namespace Atelier\Modules\Maintenance;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès à la table maintenance_asset (équipements). Suppression logique (corbeille) ;
 * la purge physique est réalisée par le hook purge() du module.
 */
final class AssetRepository
{
    public const TABLE = 'maintenance_asset';

    /** Catégories d'équipement : code => libellé. */
    public const CATEGORIES = [
        'vehicle' => 'Véhicule',
        'heating' => 'Chauffage et eau chaude',
        'appliance' => 'Électroménager',
        'house' => 'Habitation',
        'garden' => 'Jardin et extérieur',
        'electronics' => 'Informatique et électronique',
        'other' => 'Autre',
    ];

    /** Unités de compteur : code => libellé. */
    public const METER_UNITS = ['km' => 'kilomètres', 'h' => 'heures de fonctionnement', 'cycles' => 'cycles'];

    /** @var array<string, string> colonne de tri autorisée => expression SQL */
    private const SORTS = [
        'name' => 'a.name',
        'category' => 'a.category',
        'meter_value' => 'a.meter_value',
        'updated_at' => 'a.updated_at',
        'acquired_at' => 'a.acquired_at',
    ];

    private const COLUMNS = 'a.id, a.name, a.category, a.brand, a.model, a.identifier, a.acquired_at, a.meter_unit, a.meter_value, a.meter_updated_at, a.location, a.notes, a.created_by, a.created_at, a.updated_at, a.deleted_at';

    public function __construct(private readonly Database $db)
    {
    }

    public static function isSortable(string $column): bool
    {
        return isset(self::SORTS[$column]);
    }

    /**
     * @param array{q: string, category: string} $criteria
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $criteria, int $page, int $perPage, string $sort = 'name', string $direction = 'asc'): array
    {
        [$where, $params] = $this->whereActive($criteria);
        $orderBy = (self::SORTS[$sort] ?? self::SORTS['name']) . (strtolower($direction) === 'desc' ? ' DESC' : ' ASC');
        $total = $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . " a WHERE $where", $params);
        $perPage = max(1, min(500, $perPage));
        // Garde-fou : un numéro de page démesuré déborderait l'entier et rendrait la clause OFFSET invalide.
        $offset = max(0, (min($page, 1000000) - 1) * $perPage);
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . " a WHERE $where ORDER BY $orderBy, a.id ASC LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => array_map([$this, 'hydrate'], $rows), 'total' => $total];
    }

    /** @return list<array<string, mixed>> tous les équipements actifs, triés par nom */
    public function all(): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' a WHERE a.deleted_at IS NULL ORDER BY a.name ASC, a.id ASC'));
    }

    /** @return array<int, array<string, mixed>> équipements actifs indexés par identifiant */
    public function allById(): array
    {
        $result = [];
        foreach ($this->all() as $row) {
            $result[(int) $row['id']] = $row;
        }
        return $result;
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
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' a WHERE a.id = :id', ['id' => $id]);
        if ($row === null || (!$includeDeleted && $row['deleted_at'] !== null)) {
            return null;
        }
        return $this->hydrate($row);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::TABLE, $this->columns($data) + [
            'meter_updated_at' => isset($data['meter_value']) && $data['meter_value'] !== null ? $now : null,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, ?int $previousMeter): void
    {
        $now = Clock::utc();
        $columns = $this->columns($data) + ['updated_at' => $now];
        if (($columns['meter_value'] ?? null) !== $previousMeter) {
            $columns['meter_updated_at'] = $columns['meter_value'] === null ? null : $now;
        }
        $this->db->update(self::TABLE, $columns, 'id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    /** Nouveau relevé du compteur ; retourne faux si le relevé est inférieur au précédent. */
    public function updateMeter(int $id, int $value): void
    {
        $now = Clock::utc();
        $this->db->update(self::TABLE, ['meter_value' => $value, 'meter_updated_at' => $now, 'updated_at' => $now], 'id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function softDelete(int $id): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => Clock::utc()], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->db->update(self::TABLE, ['deleted_at' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** Suppression physique d'un équipement en corbeille. */
    public function purge(int $id): bool
    {
        return $this->db->delete(self::TABLE, 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** @return list<array<string, mixed>> équipements en corbeille depuis moins de $retentionDays jours */
    public function trashed(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map([$this, 'hydrate'], $this->db->select('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' a WHERE a.deleted_at IS NOT NULL AND a.deleted_at >= :l ORDER BY a.deleted_at DESC', ['l' => $limit]));
    }

    /** @return list<int> */
    public function expiredTrashIds(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]));
    }

    /** @return array<string, int> catégorie => nombre d'équipements actifs */
    public function countByCategory(): array
    {
        $result = [];
        foreach ($this->db->select('SELECT category, COUNT(*) AS n FROM ' . self::TABLE . ' WHERE deleted_at IS NULL GROUP BY category') as $row) {
            $result[(string) $row['category']] = (int) $row['n'];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;
        return [
            'name' => (string) $data['name'],
            'category' => (string) $data['category'],
            'brand' => $string('brand'),
            'model' => $string('model'),
            'identifier' => $string('identifier'),
            'acquired_at' => $string('acquired_at'),
            'meter_unit' => $string('meter_unit'),
            'meter_value' => isset($data['meter_value']) && $data['meter_value'] !== '' && $data['meter_value'] !== null ? (int) $data['meter_value'] : null,
            'location' => $string('location'),
            'notes' => $string('notes'),
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function whereActive(array $criteria): array
    {
        $where = ['a.deleted_at IS NULL'];
        $params = [];
        $search = trim((string) ($criteria['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(' . $this->db->lower('a.name') . ' LIKE :search OR ' . $this->db->lower("COALESCE(a.brand, '')") . ' LIKE :search OR ' . $this->db->lower("COALESCE(a.model, '')") . ' LIKE :search OR ' . $this->db->lower("COALESCE(a.identifier, '')") . ' LIKE :search OR ' . $this->db->lower("COALESCE(a.location, '')") . ' LIKE :search)';
            $params['search'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
        }
        $category = (string) ($criteria['category'] ?? '');
        if ($category !== '' && isset(self::CATEGORIES[$category])) {
            $where[] = 'a.category = :category';
            $params['category'] = $category;
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['meter_value'] = $row['meter_value'] === null ? null : (int) $row['meter_value'];
        $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
        return $row;
    }
}
