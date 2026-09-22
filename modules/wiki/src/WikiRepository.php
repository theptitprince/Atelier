<?php

declare(strict_types=1);

namespace Atelier\Modules\Wiki;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès aux tables wiki_page, wiki_revision et wiki_link. Les pages sont communes à tous les
 * utilisateurs autorisés ; les contrôles de droits sont réalisés par le module.
 */
final class WikiRepository
{
    public const REVISIONS_KEPT = 50;

    /** @var array<string, string> */
    private const SORTS = ['title' => 'p.title', 'updated_at' => 'p.updated_at', 'created_at' => 'p.created_at'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function isSortable(string $column): bool
    {
        return isset(self::SORTS[$column]);
    }

    /**
     * Liste paginée ; $tag (forme normalisée d'un tag partagé) restreint aux pages qui le portent :
     * les tags servent de catégories.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(string $search, int $page, int $perPage, string $sort = 'title', string $direction = 'asc', ?string $tag = null): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(' . $this->db->lower('p.title') . ' LIKE :s OR ' . $this->db->lower("COALESCE(p.content, '')") . ' LIKE :s)';
            $params['s'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
        }
        if ($tag !== null && $tag !== '') {
            $where[] = "EXISTS (SELECT 1 FROM info_registry r INNER JOIN info_tags it ON it.info_id = r.id INNER JOIN tags t ON t.id = it.tag_id
                        WHERE r.dataset_code = 'wiki.page' AND r.local_key = CAST(p.id AS " . ($this->db->isSqlite() ? 'TEXT' : 'CHAR') . ") AND t.scope = 'shared' AND t.normalized = :tag)";
            $params['tag'] = $tag;
        }
        $whereSql = implode(' AND ', $where);
        $total = $this->db->count('SELECT COUNT(*) FROM wiki_page p WHERE ' . $whereSql, $params);
        $order = (self::SORTS[$sort] ?? 'p.title') . (strtolower($direction) === 'desc' ? ' DESC' : ' ASC');
        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select(
            "SELECT p.id, p.slug, p.title, p.revision, p.created_at, p.updated_at, p.updated_by, u.display_name AS updated_by_name, SUBSTR(COALESCE(p.content, ''), 1, 400) AS excerpt,
                    (SELECT COUNT(*) FROM wiki_link l WHERE l.to_slug = p.slug) AS backlinks
             FROM wiki_page p LEFT JOIN users u ON u.id = p.updated_by WHERE $whereSql ORDER BY $order, p.id ASC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Tags partagés portés par les pages actives, avec le nombre de pages : ce sont les « catégories ».
     *
     * @return list<array{name: string, normalized: string, count: int}>
     */
    public function tagCounts(): array
    {
        $cast = $this->db->isSqlite() ? 'TEXT' : 'CHAR';
        $rows = $this->db->select(
            "SELECT t.name, t.normalized, COUNT(*) AS n
             FROM tags t INNER JOIN info_tags it ON it.tag_id = t.id INNER JOIN info_registry r ON r.id = it.info_id
             INNER JOIN wiki_page p ON r.dataset_code = 'wiki.page' AND r.local_key = CAST(p.id AS $cast)
             WHERE t.scope = 'shared' AND p.deleted_at IS NULL
             GROUP BY t.id, t.name, t.normalized ORDER BY t.normalized"
        );
        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'normalized' => (string) $r['normalized'], 'count' => (int) $r['n']], $rows);
    }

    /** @return list<array<string, mixed>> pages actives (id, slug, title) pour un sélecteur */
    public function allActive(): array
    {
        return $this->db->select('SELECT id, slug, title FROM wiki_page WHERE deleted_at IS NULL ORDER BY title');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $includeDeleted = false): ?array
    {
        $row = $this->db->selectOne('SELECT p.*, u.display_name AS updated_by_name, c.display_name AS created_by_name FROM wiki_page p LEFT JOIN users u ON u.id = p.updated_by LEFT JOIN users c ON c.id = p.created_by WHERE p.id = :id', ['id' => $id]);
        return $row === null || (!$includeDeleted && $row['deleted_at'] !== null) ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->selectOne('SELECT p.*, u.display_name AS updated_by_name, c.display_name AS created_by_name FROM wiki_page p LEFT JOIN users u ON u.id = p.updated_by LEFT JOIN users c ON c.id = p.created_by WHERE p.slug = :s', ['s' => $slug]);
        return $row === null || $row['deleted_at'] !== null ? null : $row;
    }

    /** Page active dont le titre correspond (insensible à la casse). @return array<string, mixed>|null */
    public function findByTitle(string $title): ?array
    {
        return $this->db->selectOne('SELECT * FROM wiki_page WHERE ' . $this->db->lower('title') . ' = :t AND deleted_at IS NULL', ['t' => mb_strtolower(trim($title), 'UTF-8')]);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $term, int $limit = 20): array
    {
        return $this->paginate($term, 1, $limit, 'title', 'asc')['rows'];
    }

    /** Slugs existants parmi une liste (pages actives). @param list<string> $slugs @return array<string, string> slug => titre */
    public function existingSlugs(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter($slugs)));
        if ($slugs === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($slugs as $i => $slug) {
            $placeholders[] = ':s' . $i;
            $params['s' . $i] = $slug;
        }
        $result = [];
        foreach ($this->db->select('SELECT slug, title FROM wiki_page WHERE deleted_at IS NULL AND slug IN (' . implode(', ', $placeholders) . ')', $params) as $row) {
            $result[(string) $row['slug']] = (string) $row['title'];
        }
        return $result;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $params = ['s' => $slug];
        $sql = 'SELECT id FROM wiki_page WHERE slug = :s';
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return $this->db->scalar($sql, $params) !== null;
    }

    /** Slug unique dérivé du titre (suffixe numérique en cas de conflit). */
    public function uniqueSlug(string $title, ?int $exceptId = null): string
    {
        $base = self::slugify($title);
        $slug = $base;
        $n = 2;
        while ($this->slugExists($slug, $exceptId)) {
            $slug = mb_substr($base, 0, 110, 'UTF-8') . '-' . $n;
            $n++;
        }
        return $slug;
    }

    /** @param list<string> $links slugs cibles */
    public function create(string $slug, string $title, string $content, array $links, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->transaction(function (Database $db) use ($slug, $title, $content, $links, $userId, $now): int {
            $id = $db->insert('wiki_page', ['slug' => $slug, 'title' => $title, 'content' => $content, 'revision' => 1, 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null]);
            $db->insert('wiki_revision', ['page_id' => $id, 'revision' => 1, 'title' => $title, 'content' => $content, 'saved_by' => $userId, 'saved_at' => $now]);
            $this->replaceLinks($db, $id, $links);
            return $id;
        });
    }

    /** Nouvelle révision. @param list<string> $links @return int numéro de révision */
    public function update(int $id, string $slug, string $title, string $content, array $links, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->transaction(function (Database $db) use ($id, $slug, $title, $content, $links, $userId, $now): int {
            $revision = (int) $db->scalar('SELECT revision FROM wiki_page WHERE id = :id', ['id' => $id]) + 1;
            $db->update('wiki_page', ['slug' => $slug, 'title' => $title, 'content' => $content, 'revision' => $revision, 'updated_by' => $userId, 'updated_at' => $now], 'id = :id', ['id' => $id]);
            $db->insert('wiki_revision', ['page_id' => $id, 'revision' => $revision, 'title' => $title, 'content' => $content, 'saved_by' => $userId, 'saved_at' => $now]);
            $db->execute('DELETE FROM wiki_revision WHERE page_id = :p AND revision <= :r', ['p' => $id, 'r' => $revision - self::REVISIONS_KEPT]);
            $this->replaceLinks($db, $id, $links);
            return $revision;
        });
    }

    /** @return list<array<string, mixed>> */
    public function revisions(int $pageId): array
    {
        return $this->db->select('SELECT r.id, r.revision, r.title, r.saved_by, r.saved_at, u.display_name AS saved_by_name, LENGTH(COALESCE(r.content, \'\')) AS length FROM wiki_revision r LEFT JOIN users u ON u.id = r.saved_by WHERE r.page_id = :p ORDER BY r.revision DESC', ['p' => $pageId]);
    }

    /** @return array<string, mixed>|null */
    public function revision(int $pageId, int $revision): ?array
    {
        return $this->db->selectOne('SELECT r.*, u.display_name AS saved_by_name FROM wiki_revision r LEFT JOIN users u ON u.id = r.saved_by WHERE r.page_id = :p AND r.revision = :r', ['p' => $pageId, 'r' => $revision]);
    }

    /** Pages actives pointant vers un slug. @return list<array<string, mixed>> */
    public function backlinks(string $slug): array
    {
        return $this->db->select('SELECT p.id, p.slug, p.title FROM wiki_link l INNER JOIN wiki_page p ON p.id = l.from_page_id WHERE l.to_slug = :s AND p.deleted_at IS NULL ORDER BY p.title', ['s' => $slug]);
    }

    /** Liens sortants d'une page vers des pages inexistantes. @return list<string> */
    public function missingLinks(int $pageId): array
    {
        $rows = $this->db->select('SELECT l.to_slug FROM wiki_link l WHERE l.from_page_id = :p AND NOT EXISTS (SELECT 1 FROM wiki_page p WHERE p.slug = l.to_slug AND p.deleted_at IS NULL) ORDER BY l.to_slug', ['p' => $pageId]);
        return array_map(static fn (array $r): string => (string) $r['to_slug'], $rows);
    }

    public function softDelete(int $id): bool
    {
        return $this->db->update('wiki_page', ['deleted_at' => Clock::utc()], 'id = :id AND deleted_at IS NULL', ['id' => $id]) > 0;
    }

    public function restore(int $id): bool
    {
        return $this->db->update('wiki_page', ['deleted_at' => null, 'updated_at' => Clock::utc()], 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    public function purge(int $id): bool
    {
        return $this->db->delete('wiki_page', 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]) > 0;
    }

    /** @return list<array<string, mixed>> */
    public function trashed(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return $this->db->select('SELECT p.*, u.display_name AS updated_by_name FROM wiki_page p LEFT JOIN users u ON u.id = p.updated_by WHERE p.deleted_at IS NOT NULL AND p.deleted_at >= :l ORDER BY p.deleted_at DESC', ['l' => $limit]);
    }

    /** @return list<int> */
    public function expiredTrashIds(int $retentionDays): array
    {
        $limit = Clock::utc(Clock::now()->modify('-' . $retentionDays . ' days'));
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM wiki_page WHERE deleted_at IS NOT NULL AND deleted_at < :l', ['l' => $limit]));
    }

    public function deleteById(int $id): void
    {
        $this->db->delete('wiki_page', 'id = :id', ['id' => $id]);
    }

    public function countAll(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM wiki_page');
    }

    public function countActive(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM wiki_page WHERE deleted_at IS NULL');
    }

    /** @param list<string> $links */
    private function replaceLinks(Database $db, int $pageId, array $links): void
    {
        $db->delete('wiki_link', 'from_page_id = :p', ['p' => $pageId]);
        foreach (array_values(array_unique($links)) as $slug) {
            if ($slug !== '') {
                $db->insert('wiki_link', ['from_page_id' => $pageId, 'to_slug' => mb_substr($slug, 0, 120, 'UTF-8')]);
            }
        }
    }

    /** Identifiant lisible : minuscules sans accent, tirets. */
    public static function slugify(string $title): string
    {
        $slug = mb_strtolower(trim($title), 'UTF-8');
        $slug = strtr($slug, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n', '’' => '-', "'" => '-']);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug, '-');
        return $slug !== '' ? mb_substr($slug, 0, 120, 'UTF-8') : 'page';
    }
}
