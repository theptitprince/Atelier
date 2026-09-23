<?php

declare(strict_types=1);

namespace Atelier\Modules\Demo;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès à la table demo_item. Seule cette classe (et DemoService) touche à la base :
 * le module et ses gabarits passent toujours par le dépôt.
 */
final class ItemRepository
{
    public const CATEGORIES = ['Outillage', 'Papeterie', 'Mobilier', 'Informatique'];
    public const SEED_COUNT = 120;

    private const ADJECTIVES = ['Compact', 'Robuste', 'Pliable', 'Ergonomique', 'Standard', 'Premium', 'Léger', 'Recyclé', 'Double', 'Magnétique'];
    private const NOUNS = [
        'Outillage' => ['Marteau', 'Tournevis', 'Clé à molette', 'Pince', 'Scie', 'Niveau', 'Mètre ruban', 'Perceuse'],
        'Papeterie' => ['Cahier', 'Stylo', 'Classeur', 'Agrafeuse', 'Bloc-notes', 'Surligneur', 'Enveloppe', 'Trombone'],
        'Mobilier' => ['Chaise', 'Bureau', 'Étagère', 'Lampe', 'Caisson', 'Tabouret', 'Armoire', 'Table basse'],
        'Informatique' => ['Clavier', 'Souris', 'Écran', 'Câble HDMI', 'Casque', 'Webcam', 'Concentrateur USB', 'Disque SSD'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    // ----- Lecture -----

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM demo_item WHERE id = :id', ['id' => $id]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function count(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM demo_item');
    }

    public function countActive(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM demo_item WHERE active = 1');
    }

    /** @return list<array<string, mixed>> */
    public function all(int $limit = 500): array
    {
        return array_map([$this, 'hydrate'], $this->db->select('SELECT * FROM demo_item ORDER BY name LIMIT ' . $limit));
    }

    /**
     * Quantité totale par catégorie (pour les indicateurs et la mini-courbe).
     *
     * @return array<string, int> catégorie => quantité
     */
    public function quantityByCategory(): array
    {
        $result = array_fill_keys(self::CATEGORIES, 0);
        foreach ($this->db->select('SELECT category, SUM(quantity) AS total FROM demo_item GROUP BY category') as $row) {
            $result[(string) $row['category']] = (int) $row['total'];
        }
        return $result;
    }

    /**
     * Liste paginée, filtrée et triée.
     *
     * @param array{q: string, category: string, active: ?bool} $criteria
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $criteria, int $page, int $perPage, string $sort, string $direction): array
    {
        [$where, $params] = $this->whereClause($criteria);
        $total = $this->db->count('SELECT COUNT(*) FROM demo_item' . $where, $params);
        // Garde-fou : un numéro de page démesuré déborderait l’entier et rendrait la clause OFFSET invalide.
        $offset = max(0, (min($page, 1000000) - 1) * $perPage);
        $rows = $this->db->select(
            'SELECT * FROM demo_item' . $where . $this->orderClause($sort, $direction) . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        return ['rows' => array_map([$this, 'hydrate'], $rows), 'total' => $total];
    }

    /**
     * Toutes les lignes correspondant aux critères (export).
     *
     * @param array{q: string, category: string, active: ?bool} $criteria
     * @return list<array<string, mixed>>
     */
    public function export(array $criteria, string $sort, string $direction, int $limit = 5000): array
    {
        [$where, $params] = $this->whereClause($criteria);
        return array_map([$this, 'hydrate'], $this->db->select('SELECT * FROM demo_item' . $where . $this->orderClause($sort, $direction) . ' LIMIT ' . $limit, $params));
    }

    /**
     * Recherche rapide (filtre instantané) : au plus $limit résultats et le total.
     *
     * @param array{q: string, category: string, active: ?bool} $criteria
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function quickSearch(array $criteria, int $limit = 8): array
    {
        return $this->paginate($criteria, 1, $limit, 'name', 'asc');
    }

    // ----- Écriture -----

    public function toggleActive(int $id): bool
    {
        $item = $this->find($id);
        if ($item === null) {
            return false;
        }
        $this->db->update('demo_item', ['active' => !$item['active']], 'id = :id', ['id' => $id]);
        return !$item['active'];
    }

    /** @param list<int> $ids */
    public function setActive(array $ids, bool $active): int
    {
        if ($ids === []) {
            return 0;
        }
        [$in, $params] = $this->inClause($ids);
        $params['active'] = $active;
        return $this->db->execute('UPDATE demo_item SET active = :active WHERE id IN (' . $in . ')', $params);
    }

    /** @param list<int> $ids */
    public function deleteMany(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        [$in, $params] = $this->inClause($ids);
        return $this->db->execute('DELETE FROM demo_item WHERE id IN (' . $in . ')', $params);
    }

    public function rename(int $id, string $name): void
    {
        $this->db->update('demo_item', ['name' => $name], 'id = :id', ['id' => $id]);
    }

    /**
     * Régénération déterministe des SEED_COUNT articles : les identifiants 1..N sont créés ou
     * remis à leur valeur d'origine, les articles au-delà sont supprimés. Les références du
     * registre commun (clé locale = id) restent donc valides.
     */
    public function regenerate(): int
    {
        return $this->db->transaction(function (Database $db): int {
            $existing = array_map('intval', array_column($db->select('SELECT id FROM demo_item'), 'id'));
            $count = 0;
            foreach ($this->generate() as $item) {
                if (in_array($item['id'], $existing, true)) {
                    $id = $item['id'];
                    unset($item['id']);
                    $db->update('demo_item', $item, 'id = :id', ['id' => $id]);
                } else {
                    $db->insert('demo_item', $item);
                }
                $count++;
            }
            $db->execute('DELETE FROM demo_item WHERE id > :max', ['max' => self::SEED_COUNT]);
            return $count;
        });
    }

    /**
     * Jeu d'articles déterministe (même résultat à chaque appel) : générateur congruentiel
     * simple, indépendant de mt_rand et de la plate-forme.
     *
     * @return list<array<string, mixed>>
     */
    public function generate(): array
    {
        $items = [];
        $state = 20260922;
        $next = static function () use (&$state): int {
            $state = ($state * 1103515245 + 12345) % 2147483648;
            return $state;
        };
        $base = new \DateTimeImmutable('2026-01-05 08:00:00', new \DateTimeZone('UTC'));
        for ($i = 1; $i <= self::SEED_COUNT; $i++) {
            $category = self::CATEGORIES[($i - 1) % count(self::CATEGORIES)];
            $nouns = self::NOUNS[$category];
            $noun = $nouns[$next() % count($nouns)];
            $adjective = self::ADJECTIVES[$next() % count(self::ADJECTIVES)];
            $quantity = $next() % 60;                         // 0..59 : quelques stocks faibles (< 5)
            $price = 150 + ($next() % 400) * 25;              // 1,50 € à 101,25 €, en centimes
            $active = ($next() % 7) !== 0;                    // environ un article sur sept inactif
            $items[] = [
                'id' => $i,
                'name' => sprintf('%s %s n°%03d', $noun, mb_strtolower($adjective, 'UTF-8'), $i),
                'category' => $category,
                'quantity' => $quantity,
                'price' => $price,
                'active' => $active,
                'created_at' => Clock::utc($base->modify('+' . ($i * 7) . ' hours')),
            ];
        }
        return $items;
    }

    // ----- Helpers -----

    /**
     * @param array{q: string, category: string, active: ?bool} $criteria
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function whereClause(array $criteria): array
    {
        $conditions = [];
        $params = [];
        if ($criteria['q'] !== '') {
            $conditions[] = $this->db->lower('name') . ' LIKE :q';
            $params['q'] = '%' . mb_strtolower($criteria['q'], 'UTF-8') . '%';
        }
        if ($criteria['category'] !== '') {
            $conditions[] = 'category = :category';
            $params['category'] = $criteria['category'];
        }
        if ($criteria['active'] !== null) {
            $conditions[] = 'active = :active';
            $params['active'] = $criteria['active'];
        }
        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function orderClause(string $sort, string $direction): string
    {
        $column = in_array($sort, ItemFilters::SORTS, true) ? $sort : 'id';
        $dir = $direction === 'desc' ? 'DESC' : 'ASC';
        // Second critère stable pour que la pagination soit déterministe.
        return ' ORDER BY ' . $this->db->quoteIdentifier($column) . ' ' . $dir . ($column === 'id' ? '' : ', id ASC');
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function inClause(array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique($ids)) as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params['id' . $i] = (int) $id;
        }
        return [implode(', ', $placeholders), $params];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['quantity'] = (int) $row['quantity'];
        $row['price'] = (int) $row['price'];
        $row['active'] = (bool) $row['active'];
        return $row;
    }
}
