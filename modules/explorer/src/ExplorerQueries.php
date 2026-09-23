<?php

declare(strict_types=1);

namespace Atelier\Modules\Explorer;

use Atelier\Persistence\Database;
use Atelier\Shared\TagService;

/**
 * Requêtes de lecture transversales de l'Explorateur sur les tables du noyau
 * (info_registry, tags, info_tags, relations, attachments).
 *
 * Toute requête est restreinte à une liste de codes de jeux de données fournie par l'appelant :
 * le module ne transmet que les jeux partagés et lisibles par l'utilisateur courant. Une liste
 * vide ne renvoie jamais rien (aucun repli vers « tout »).
 */
final class ExplorerQueries
{
    /** @var array<string, string> tri autorisé => expression SQL */
    public const SORTS = [
        'label' => 'r.label ASC, r.created_at DESC',
        'recent' => 'r.created_at DESC, r.label ASC',
        'dataset' => 'r.dataset_code ASC, r.label ASC',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public static function isSortable(string $sort): bool
    {
        return isset(self::SORTS[$sort]);
    }

    // ----- Informations -----

    /**
     * Recherche paginée dans le registre.
     *
     * @param list<string> $codes jeux de données visibles
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function searchInfos(array $codes, string $term, ?string $dataset, ?string $module, ?int $tagId, int $page, int $perPage, string $sort): array
    {
        if ($codes === []) {
            return ['rows' => [], 'total' => 0];
        }
        [$in, $params] = $this->inList('c', $codes);
        $where = ['r.dataset_code IN (' . $in . ')'];
        if ($term !== '') {
            $where[] = $this->db->lower("COALESCE(r.label, '')") . ' LIKE :term';
            $params['term'] = '%' . mb_strtolower($term, 'UTF-8') . '%';
        }
        if ($dataset !== null && $dataset !== '') {
            $where[] = 'r.dataset_code = :dataset';
            $params['dataset'] = $dataset;
        }
        if ($module !== null && $module !== '') {
            $where[] = 'r.module_id = :module';
            $params['module'] = $module;
        }
        if ($tagId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM info_tags it WHERE it.info_id = r.id AND it.tag_id = :tag)';
            $params['tag'] = $tagId;
        }
        $whereSql = implode(' AND ', $where);
        $total = $this->db->count("SELECT COUNT(*) FROM info_registry r WHERE $whereSql", $params);
        $order = self::SORTS[$sort] ?? self::SORTS['label'];
        $perPage = max(1, min(200, $perPage));
        // Garde-fou : un numéro de page démesuré déborderait l’entier et rendrait la clause OFFSET invalide.
        $offset = max(0, (min($page, 1000000) - 1) * $perPage);
        $rows = $this->db->select(
            'SELECT r.*, ' . $this->countColumns() . " FROM info_registry r WHERE $whereSql ORDER BY $order LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Nombre d'informations enregistrées par jeu de données.
     *
     * @param list<string> $codes
     * @return array<string, int> code => nombre
     */
    public function countByDataset(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        [$in, $params] = $this->inList('c', $codes);
        $rows = $this->db->select("SELECT dataset_code, COUNT(*) AS n FROM info_registry WHERE dataset_code IN ($in) GROUP BY dataset_code", $params);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['dataset_code']] = (int) $row['n'];
        }
        return $result;
    }

    /**
     * Tags partagés de plusieurs informations en une requête.
     *
     * @param list<string> $infoIds
     * @return array<string, list<array<string, mixed>>> infoId => tags
     */
    public function tagsOfMany(array $infoIds): array
    {
        if ($infoIds === []) {
            return [];
        }
        [$in, $params] = $this->inList('i', $infoIds);
        $params['scope'] = TagService::SHARED;
        $rows = $this->db->select(
            "SELECT it.info_id, t.id, t.name, t.normalized FROM info_tags it INNER JOIN tags t ON t.id = it.tag_id WHERE it.info_id IN ($in) AND t.scope = :scope ORDER BY t.normalized",
            $params
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['info_id']][] = $row;
        }
        return $result;
    }

    /** Tag partagé par son nom normalisé (filtre de recherche). @return array<string, mixed>|null */
    public function findTagByName(string $normalized): ?array
    {
        if ($normalized === '') {
            return null;
        }
        return $this->db->selectOne('SELECT * FROM tags WHERE scope = :s AND normalized = :n', ['s' => TagService::SHARED, 'n' => $normalized]);
    }

    // ----- Relations -----

    /**
     * Relations dont les deux informations appartiennent aux jeux visibles.
     *
     * @param list<string> $codes
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function relations(array $codes, ?string $type, int $page, int $perPage): array
    {
        if ($codes === []) {
            return ['rows' => [], 'total' => 0];
        }
        [$inFrom, $params] = $this->inList('f', $codes);
        [$inTo, $paramsTo] = $this->inList('t', $codes);
        $params += $paramsTo;
        $where = ["f.dataset_code IN ($inFrom)", "t.dataset_code IN ($inTo)"];
        if ($type !== null && $type !== '') {
            $where[] = 'rel.type = :type';
            $params['type'] = $type;
        }
        $whereSql = implode(' AND ', $where);
        $from = 'FROM relations rel INNER JOIN info_registry f ON f.id = rel.from_info INNER JOIN info_registry t ON t.id = rel.to_info';
        $total = $this->db->count("SELECT COUNT(*) $from WHERE $whereSql", $params);
        $perPage = max(1, min(200, $perPage));
        // Garde-fou : un numéro de page démesuré déborderait l’entier et rendrait la clause OFFSET invalide.
        $offset = max(0, (min($page, 1000000) - 1) * $perPage);
        $rows = $this->db->select(
            "SELECT rel.*, u.username AS creator,
                    f.label AS from_label, f.dataset_code AS from_dataset, f.module_id AS from_module, f.local_key AS from_key,
                    t.label AS to_label, t.dataset_code AS to_dataset, t.module_id AS to_module, t.local_key AS to_key
             $from LEFT JOIN users u ON u.id = rel.created_by
             WHERE $whereSql ORDER BY rel.created_at DESC, rel.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Types de relation présents entre informations visibles, avec leur nombre.
     *
     * @param list<string> $codes
     * @return array<string, int> type => nombre
     */
    public function relationTypes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        [$inFrom, $params] = $this->inList('f', $codes);
        [$inTo, $paramsTo] = $this->inList('t', $codes);
        $params += $paramsTo;
        $rows = $this->db->select(
            "SELECT rel.type, COUNT(*) AS n FROM relations rel
             INNER JOIN info_registry f ON f.id = rel.from_info INNER JOIN info_registry t ON t.id = rel.to_info
             WHERE f.dataset_code IN ($inFrom) AND t.dataset_code IN ($inTo) GROUP BY rel.type ORDER BY rel.type",
            $params
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['type']] = (int) $row['n'];
        }
        return $result;
    }

    // ----- Pièces jointes -----

    /**
     * Pièces jointes actives rattachées à des informations visibles.
     *
     * @param list<string> $codes
     * @return array{rows: list<array<string, mixed>>, total: int, size: int}
     */
    public function attachments(array $codes, ?string $dataset, int $page, int $perPage): array
    {
        if ($codes === []) {
            return ['rows' => [], 'total' => 0, 'size' => 0];
        }
        [$in, $params] = $this->inList('c', $codes);
        $where = ['a.deleted_at IS NULL', "r.dataset_code IN ($in)"];
        if ($dataset !== null && $dataset !== '') {
            $where[] = 'r.dataset_code = :dataset';
            $params['dataset'] = $dataset;
        }
        $whereSql = implode(' AND ', $where);
        $from = 'FROM attachments a INNER JOIN info_registry r ON r.id = a.info_id';
        $totals = $this->db->selectOne("SELECT COUNT(*) AS c, COALESCE(SUM(a.size), 0) AS s $from WHERE $whereSql", $params) ?? ['c' => 0, 's' => 0];
        $perPage = max(1, min(200, $perPage));
        // Garde-fou : un numéro de page démesuré déborderait l’entier et rendrait la clause OFFSET invalide.
        $offset = max(0, (min($page, 1000000) - 1) * $perPage);
        $rows = $this->db->select(
            "SELECT a.*, u.username AS uploader, r.label AS info_label, r.dataset_code AS info_dataset, r.module_id AS info_module, r.local_key AS info_key
             $from LEFT JOIN users u ON u.id = a.uploaded_by
             WHERE $whereSql ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => (int) $totals['c'], 'size' => (int) $totals['s']];
    }

    // ----- Interne -----

    /** Sous-requêtes de comptage des relations et des pièces jointes d'une ligne r. */
    private function countColumns(): string
    {
        return '(SELECT COUNT(*) FROM relations rel WHERE rel.from_info = r.id OR rel.to_info = r.id) AS relation_count, '
            . '(SELECT COUNT(*) FROM attachments a WHERE a.info_id = r.id AND a.deleted_at IS NULL) AS attachment_count';
    }

    /**
     * Liste de marqueurs nommés pour une clause IN.
     *
     * @param list<string> $values
     * @return array{0: string, 1: array<string, string>}
     */
    private function inList(string $prefix, array $values): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $placeholders[] = ':' . $prefix . $i;
            $params[$prefix . $i] = (string) $value;
        }
        return [implode(', ', $placeholders), $params];
    }
}
