<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès aux tables budget_category (arbre à deux niveaux : catégorie et sous-catégorie) et
 * budget_envelope (budgets datés par catégorie, mensuels ou annuels).
 */
final class CategoryRepository
{
    public const TABLE = 'budget_category';
    public const ENVELOPES = 'budget_envelope';
    public const KINDS = ['expense' => 'Dépense', 'income' => 'Recette'];
    public const PERIODS = ['month' => 'par mois', 'year' => 'par an'];

    /** Catégories courantes proposées à la création (nature, nom, sous-catégories). */
    public const DEFAULTS = [
        ['expense', 'Logement', ['Loyer ou crédit', 'Charges et copropriété', 'Énergie', 'Eau', 'Assurance habitation', 'Travaux']],
        ['expense', 'Alimentation', ['Courses', 'Restaurants']],
        ['expense', 'Véhicule', ['Carburant', 'Assurance auto', 'Entretien et réparations', 'Péages et stationnement']],
        ['expense', 'Santé', []],
        ['expense', 'Abonnements', ['Téléphone et internet', 'Streaming', 'Presse']],
        ['expense', 'Loisirs', ['Sorties', 'Vacances', 'Sport']],
        ['expense', 'Impôts et taxes', []],
        ['expense', 'Enfants', []],
        ['expense', 'Divers', []],
        ['income', 'Salaires', []],
        ['income', 'Aides et allocations', []],
        ['income', 'Autres recettes', []],
    ];

    private const COLUMNS = 'c.id, c.parent_id, c.name, c.kind, c.archived, c.sort_order, c.created_at, c.updated_at';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> toutes les catégories, parents puis enfants, triées par nature et nom */
    public function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ', p.name AS parent_name FROM ' . self::TABLE . ' c LEFT JOIN ' . self::TABLE . ' p ON p.id = c.parent_id' . ($includeArchived ? '' : ' WHERE c.archived = 0') . ' ORDER BY c.kind ASC, COALESCE(p.sort_order, c.sort_order) ASC, COALESCE(p.name, c.name) ASC, c.parent_id ASC, c.sort_order ASC, c.name ASC';
        return array_map([$this, 'hydrate'], $this->db->select($sql));
    }

    /** @return array<int, array<string, mixed>> */
    public function allById(bool $includeArchived = true): array
    {
        $result = [];
        foreach ($this->all($includeArchived) as $row) {
            $result[$row['id']] = $row;
        }
        return $result;
    }

    /**
     * Arbre : parents avec leurs enfants (clé children), pour les sélecteurs et la vue budget.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(bool $includeArchived = false, ?string $kind = null): array
    {
        $parents = [];
        $children = [];
        foreach ($this->all($includeArchived) as $row) {
            if ($kind !== null && $row['kind'] !== $kind) {
                continue;
            }
            if ($row['parent_id'] === null) {
                $row['children'] = [];
                $parents[$row['id']] = $row;
            } else {
                $children[$row['parent_id']][] = $row;
            }
        }
        foreach ($children as $parentId => $rows) {
            if (isset($parents[$parentId])) {
                $parents[$parentId]['children'] = $rows;
            }
        }
        return array_values($parents);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ', p.name AS parent_name FROM ' . self::TABLE . ' c LEFT JOIN ' . self::TABLE . ' p ON p.id = c.parent_id WHERE c.id = :id', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    /** Catégorie active par nom exact (insensible à la casse) et nature. */
    public function findByName(string $name, string $kind): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ', p.name AS parent_name FROM ' . self::TABLE . ' c LEFT JOIN ' . self::TABLE . ' p ON p.id = c.parent_id WHERE ' . $this->db->lower('c.name') . ' = :n AND c.kind = :k AND c.archived = 0 ORDER BY c.parent_id ASC LIMIT 1', ['n' => mb_strtolower(trim($name), 'UTF-8'), 'k' => $kind]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function count(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /** @return list<int> identifiants de la catégorie et de ses enfants */
    public function familyIds(int $id): array
    {
        $ids = [$id];
        foreach ($this->db->select('SELECT id FROM ' . self::TABLE . ' WHERE parent_id = :p', ['p' => $id]) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    public function hasChildren(int $id): bool
    {
        return $this->db->count('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE parent_id = :p', ['p' => $id]) > 0;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = Clock::utc();
        return $this->db->insert(self::TABLE, $this->columns($data) + ['archived' => false, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update(self::TABLE, $this->columns($data) + ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $now = Clock::utc();
        $this->db->update(self::TABLE, ['archived' => $archived, 'updated_at' => $now], 'id = :id OR parent_id = :p', ['id' => $id, 'p' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete(self::ENVELOPES, 'category_id = :c', ['c' => $id]);
        $this->db->delete(self::TABLE, 'id = :id', ['id' => $id]);
    }

    /** Crée les catégories courantes absentes ; retourne le nombre créé. */
    public function createDefaults(): int
    {
        $created = 0;
        $order = 0;
        foreach (self::DEFAULTS as [$kind, $name, $children]) {
            $order += 10;
            $parent = $this->findByName($name, $kind);
            if ($parent === null) {
                $parentId = $this->create(['name' => $name, 'kind' => $kind, 'parent_id' => null, 'sort_order' => $order]);
                $created++;
            } else {
                $parentId = $parent['id'];
            }
            $childOrder = 0;
            foreach ($children as $child) {
                $childOrder += 10;
                $existing = $this->db->selectOne('SELECT id FROM ' . self::TABLE . ' WHERE parent_id = :p AND ' . $this->db->lower('name') . ' = :n', ['p' => $parentId, 'n' => mb_strtolower($child, 'UTF-8')]);
                if ($existing === null) {
                    $this->create(['name' => $child, 'kind' => $kind, 'parent_id' => $parentId, 'sort_order' => $childOrder]);
                    $created++;
                }
            }
        }
        return $created;
    }

    // ----- Enveloppes (budgets) -----

    /** @return list<array<string, mixed>> toutes les enveloppes, les plus récentes d'abord par catégorie */
    public function envelopes(): array
    {
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['category_id'] = (int) $row['category_id'];
            $row['amount'] = (int) $row['amount'];
            return $row;
        }, $this->db->select('SELECT * FROM ' . self::ENVELOPES . ' ORDER BY category_id ASC, valid_from DESC'));
    }

    /**
     * Enveloppe applicable à chaque catégorie pour un mois donné (la plus récente dont valid_from <= mois).
     *
     * @return array<int, array{period: string, amount: int, monthly: int, yearly: int, valid_from: string}>
     */
    public function envelopesFor(string $month): array
    {
        $result = [];
        foreach ($this->envelopes() as $row) {
            if (isset($result[$row['category_id']]) || $row['valid_from'] > $month . '-01') {
                continue;
            }
            $monthly = $row['period'] === 'year' ? (int) round($row['amount'] / 12) : $row['amount'];
            $result[$row['category_id']] = ['period' => (string) $row['period'], 'amount' => $row['amount'], 'monthly' => $monthly, 'yearly' => $row['period'] === 'year' ? $row['amount'] : $row['amount'] * 12, 'valid_from' => (string) $row['valid_from']];
        }
        return $result;
    }

    public function saveEnvelope(int $categoryId, string $period, int $amount, string $validFrom): void
    {
        $now = Clock::utc();
        $existing = $this->db->selectOne('SELECT id FROM ' . self::ENVELOPES . ' WHERE category_id = :c AND valid_from = :v', ['c' => $categoryId, 'v' => $validFrom]);
        if ($existing !== null) {
            $this->db->update(self::ENVELOPES, ['period' => $period, 'amount' => $amount, 'updated_at' => $now], 'id = :id', ['id' => (int) $existing['id']]);
            return;
        }
        $this->db->insert(self::ENVELOPES, ['category_id' => $categoryId, 'period' => $period, 'amount' => $amount, 'valid_from' => $validFrom, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function deleteEnvelope(int $id): bool
    {
        return $this->db->delete(self::ENVELOPES, 'id = :id', ['id' => $id]) > 0;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function columns(array $data): array
    {
        return [
            'parent_id' => isset($data['parent_id']) && (int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null,
            'name' => (string) $data['name'],
            'kind' => (string) $data['kind'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        $row['archived'] = (bool) $row['archived'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['path'] = ($row['parent_name'] !== null ? $row['parent_name'] . ' › ' : '') . $row['name'];
        return $row;
    }
}
