<?php

declare(strict_types=1);

namespace Atelier\Modules\News;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Accès aux tables du module Actualités : catégories, centres d'intérêt, flux, entrées,
 * correspondances et marques de lecture. Les contrôles de droits sont réalisés par le module.
 */
final class NewsRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    // ----- Catégories -----

    /** @return list<array<string, mixed>> avec feed_count */
    public function categories(): array
    {
        return $this->db->select('SELECT c.*, (SELECT COUNT(*) FROM news_feed f WHERE f.category_id = c.id) AS feed_count FROM news_category c ORDER BY c.position, c.name');
    }

    /** @return array<string, mixed>|null */
    public function findCategory(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM news_category WHERE id = :id', ['id' => $id]);
    }

    public function saveCategory(?int $id, string $name, ?string $color, int $position): int
    {
        $slug = self::slugify($name);
        $conflict = $this->db->selectOne('SELECT id FROM news_category WHERE slug = :s' . ($id !== null ? ' AND id <> :id' : ''), $id !== null ? ['s' => $slug, 'id' => $id] : ['s' => $slug]);
        if ($conflict !== null) {
            throw new \InvalidArgumentException('Une catégorie portant ce nom existe déjà.');
        }
        $data = ['name' => $name, 'slug' => $slug, 'color' => $color, 'position' => $position];
        if ($id === null) {
            return $this->db->insert('news_category', $data + ['created_at' => Clock::utc()]);
        }
        $this->db->update('news_category', $data, 'id = :id', ['id' => $id]);
        return $id;
    }

    public function deleteCategory(int $id): bool
    {
        return $this->db->delete('news_category', 'id = :id', ['id' => $id]) > 0;
    }

    // ----- Centres d'intérêt -----

    /** @return list<array<string, mixed>> avec item_count */
    public function interests(): array
    {
        return $this->db->select('SELECT i.*, (SELECT COUNT(*) FROM news_item_interest ii WHERE ii.interest_id = i.id) AS item_count FROM news_interest i ORDER BY i.position, i.name');
    }

    /** @return array<string, mixed>|null */
    public function findInterest(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM news_interest WHERE id = :id', ['id' => $id]);
    }

    public function saveInterest(?int $id, string $name, string $keywords, ?string $color, int $position): int
    {
        $data = ['name' => $name, 'keywords' => $keywords, 'color' => $color, 'position' => $position];
        if ($id === null) {
            return $this->db->insert('news_interest', $data + ['created_at' => Clock::utc()]);
        }
        $this->db->update('news_interest', $data, 'id = :id', ['id' => $id]);
        return $id;
    }

    public function deleteInterest(int $id): bool
    {
        return $this->db->delete('news_interest', 'id = :id', ['id' => $id]) > 0;
    }

    /** Recalcule les correspondances d'un centre d'intérêt sur toutes les entrées présentes. */
    public function rematchInterest(array $interest): int
    {
        $this->db->delete('news_item_interest', 'interest_id = :i', ['i' => (int) $interest['id']]);
        $count = 0;
        foreach ($this->db->select('SELECT id, title, summary FROM news_item') as $item) {
            if (InterestMatcher::matches((string) $item['title'] . ' ' . (string) $item['summary'], (string) $interest['keywords'])) {
                $this->db->insert('news_item_interest', ['item_id' => (int) $item['id'], 'interest_id' => (int) $interest['id']]);
                $count++;
            }
        }
        return $count;
    }

    // ----- Flux -----

    /** @return list<array<string, mixed>> avec category_name et item_count */
    public function feeds(bool $activeOnly = false): array
    {
        return $this->db->select(
            'SELECT f.*, c.name AS category_name, c.color AS category_color,
                    (SELECT COUNT(*) FROM news_item i WHERE i.feed_id = f.id) AS item_count
             FROM news_feed f LEFT JOIN news_category c ON c.id = f.category_id'
            . ($activeOnly ? ' WHERE f.is_active = 1' : '') . ' ORDER BY f.title'
        );
    }

    /** @return array<string, mixed>|null */
    public function findFeed(int $id): ?array
    {
        return $this->db->selectOne('SELECT f.*, c.name AS category_name FROM news_feed f LEFT JOIN news_category c ON c.id = f.category_id WHERE f.id = :id', ['id' => $id]);
    }

    public function feedUrlExists(string $url, ?int $exceptId = null): bool
    {
        $params = ['u' => $url];
        $sql = 'SELECT id FROM news_feed WHERE url = :u';
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return $this->db->scalar($sql, $params) !== null;
    }

    /** @param array<string, mixed> $data */
    public function createFeed(array $data, ?int $userId): int
    {
        $now = Clock::utc();
        return $this->db->insert('news_feed', $this->feedColumns($data) + ['created_by' => $userId, 'created_at' => $now, 'updated_at' => $now, 'last_status' => 'never']);
    }

    /** @param array<string, mixed> $data */
    public function updateFeed(int $id, array $data): void
    {
        $this->db->update('news_feed', $this->feedColumns($data) + ['updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $data */
    public function updateFeedStatus(int $id, array $data): void
    {
        $this->db->update('news_feed', $data, 'id = :id', ['id' => $id]);
    }

    public function setFeedActive(int $id, bool $active): void
    {
        $this->db->update('news_feed', ['is_active' => $active ? 1 : 0, 'updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    public function deleteFeed(int $id): bool
    {
        return $this->db->delete('news_feed', 'id = :id', ['id' => $id]) > 0;
    }

    /**
     * Flux actifs dont la dernière récupération date de plus de refresh_minutes (ou jamais récupérés).
     *
     * @return list<array<string, mixed>>
     */
    public function staleFeeds(int $limit): array
    {
        $rows = [];
        $now = Clock::now();
        foreach ($this->feeds(true) as $feed) {
            $last = Clock::parseUtc($feed['last_fetched_at'] ?? null);
            if ($last === null || $last->modify('+' . max(5, (int) $feed['refresh_minutes']) . ' minutes') <= $now) {
                $rows[] = $feed;
                if (count($rows) >= $limit) {
                    break;
                }
            }
        }
        return $rows;
    }

    // ----- Entrées -----

    /**
     * Enregistre les entrées d'un flux (ignore celles déjà connues par guid) et calcule leurs centres d'intérêt.
     *
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $interests
     * @return int nombre de nouvelles entrées
     */
    public function storeItems(int $feedId, array $items, array $interests): int
    {
        $count = 0;
        $now = Clock::utc();
        $this->db->transaction(function (Database $db) use ($feedId, $items, $interests, $now, &$count): void {
            foreach ($items as $item) {
                $exists = $db->scalar('SELECT id FROM news_item WHERE feed_id = :f AND guid = :g', ['f' => $feedId, 'g' => $item['guid']]);
                if ($exists !== null) {
                    continue;
                }
                $id = $db->insert('news_item', [
                    'feed_id' => $feedId,
                    'guid' => $item['guid'],
                    'url' => $item['url'],
                    'title' => $item['title'],
                    'summary' => $item['summary'],
                    'author' => $item['author'],
                    'image_url' => $item['image_url'],
                    'published_at' => $item['published_at'] ?? $now,
                    'fetched_at' => $now,
                    'is_archived' => 0,
                ]);
                $text = $item['title'] . ' ' . ($item['summary'] ?? '');
                foreach ($interests as $interest) {
                    if (InterestMatcher::matches($text, (string) $interest['keywords'])) {
                        $db->insert('news_item_interest', ['item_id' => $id, 'interest_id' => (int) $interest['id']]);
                    }
                }
                $count++;
            }
        });
        return $count;
    }

    /**
     * Entrées paginées avec filtres : q, category_id, interest_id, feed_id, unread (bool), archived (bool).
     *
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $userId, int $page, int $perPage): array
    {
        [$where, $params] = $this->where($filters, $userId);
        $from = 'FROM news_item i INNER JOIN news_feed f ON f.id = i.feed_id LEFT JOIN news_category c ON c.id = f.category_id LEFT JOIN news_read r ON r.item_id = i.id AND r.user_id = :user';
        $params['user'] = $userId;
        $total = $this->db->count("SELECT COUNT(*) $from WHERE $where", $params);
        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $order = !empty($filters['archived']) ? 'i.archived_at DESC, i.id DESC' : 'i.published_at DESC, i.id DESC';
        $rows = $this->db->select(
            "SELECT i.*, f.title AS feed_title, f.site_url AS feed_site_url, c.id AS category_id, c.name AS category_name, c.color AS category_color,
                    CASE WHEN r.item_id IS NULL THEN 0 ELSE 1 END AS is_read
             $from WHERE $where ORDER BY $order LIMIT $perPage OFFSET $offset",
            $params
        );
        $this->attachInterests($rows);
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null entrée avec flux, catégorie et centres d'intérêt */
    public function findItem(int $id, ?int $userId = null): ?array
    {
        $row = $this->db->selectOne(
            'SELECT i.*, f.title AS feed_title, f.site_url AS feed_site_url, c.name AS category_name, c.color AS category_color,
                    CASE WHEN r.item_id IS NULL THEN 0 ELSE 1 END AS is_read
             FROM news_item i INNER JOIN news_feed f ON f.id = i.feed_id LEFT JOIN news_category c ON c.id = f.category_id
             LEFT JOIN news_read r ON r.item_id = i.id AND r.user_id = :user WHERE i.id = :id',
            ['id' => $id, 'user' => $userId ?? 0]
        );
        if ($row === null) {
            return null;
        }
        $rows = [$row];
        $this->attachInterests($rows);
        return $rows[0];
    }

    public function unreadCount(int $userId, ?int $categoryId = null): int
    {
        $params = ['user' => $userId];
        $sql = 'SELECT COUNT(*) FROM news_item i INNER JOIN news_feed f ON f.id = i.feed_id WHERE i.is_archived = 0 AND f.is_active = 1 AND NOT EXISTS (SELECT 1 FROM news_read r WHERE r.item_id = i.id AND r.user_id = :user)';
        if ($categoryId !== null) {
            $sql .= ' AND f.category_id = :c';
            $params['c'] = $categoryId;
        }
        return $this->db->count($sql, $params);
    }

    /** Nombre d'entrées non archivées par catégorie (clé 0 = sans catégorie). @return array<int, int> */
    public function countsByCategory(): array
    {
        $counts = [];
        foreach ($this->db->select('SELECT COALESCE(f.category_id, 0) AS cid, COUNT(*) AS c FROM news_item i INNER JOIN news_feed f ON f.id = i.feed_id WHERE i.is_archived = 0 GROUP BY COALESCE(f.category_id, 0)') as $row) {
            $counts[(int) $row['cid']] = (int) $row['c'];
        }
        return $counts;
    }

    public function markRead(int $userId, int $itemId, bool $read): void
    {
        if ($read) {
            $exists = $this->db->scalar('SELECT 1 FROM news_read WHERE user_id = :u AND item_id = :i', ['u' => $userId, 'i' => $itemId]);
            if ($exists === null) {
                $this->db->insert('news_read', ['user_id' => $userId, 'item_id' => $itemId, 'read_at' => Clock::utc()]);
            }
        } else {
            $this->db->delete('news_read', 'user_id = :u AND item_id = :i', ['u' => $userId, 'i' => $itemId]);
        }
    }

    /** Marque comme lues toutes les entrées correspondant aux filtres (hors archives). @param array<string, mixed> $filters */
    public function markAllRead(int $userId, array $filters): int
    {
        $filters['unread'] = true;
        $filters['archived'] = false;
        [$where, $params] = $this->where($filters, $userId);
        $params['user'] = $userId;
        $ids = $this->db->select("SELECT i.id FROM news_item i INNER JOIN news_feed f ON f.id = i.feed_id LEFT JOIN news_category c ON c.id = f.category_id LEFT JOIN news_read r ON r.item_id = i.id AND r.user_id = :user WHERE $where", $params);
        $now = Clock::utc();
        $this->db->transaction(function (Database $db) use ($ids, $userId, $now): void {
            foreach ($ids as $row) {
                $db->insert('news_read', ['user_id' => $userId, 'item_id' => (int) $row['id'], 'read_at' => $now]);
            }
        });
        return count($ids);
    }

    public function archive(int $itemId, int $userId, ?string $note): void
    {
        $this->db->update('news_item', ['is_archived' => 1, 'archived_at' => Clock::utc(), 'archived_by' => $userId, 'archive_note' => $note], 'id = :id', ['id' => $itemId]);
    }

    /** Entrées dont la copie locale n'a pas encore été tentée, flux avec copie activée, les plus récentes d'abord. @return list<array<string, mixed>> */
    public function pendingContent(int $limit): array
    {
        return $this->db->select(
            'SELECT i.* FROM news_item i INNER JOIN news_feed f ON f.id = i.feed_id WHERE i.content_status IS NULL AND i.url IS NOT NULL AND f.fetch_content = 1 AND f.is_active = 1 ORDER BY i.published_at DESC, i.id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    public function saveContent(int $itemId, ?string $text, string $status, ?string $error, ?string $image): void
    {
        $data = ['content' => $text, 'content_status' => $status, 'content_error' => $error, 'content_fetched_at' => Clock::utc()];
        if ($image !== null) {
            $data['image_url'] = $image;
        }
        $this->db->update('news_item', $data, 'id = :id', ['id' => $itemId]);
    }

    /** Nombre d'entrées avec copie locale, par flux (identifiant => nombre). @return array<int, int> */
    public function localCopyCounts(): array
    {
        $counts = [];
        foreach ($this->db->select("SELECT feed_id, COUNT(*) AS c FROM news_item WHERE content_status = 'ok' GROUP BY feed_id") as $row) {
            $counts[(int) $row['feed_id']] = (int) $row['c'];
        }
        return $counts;
    }

    public function updateArchiveNote(int $itemId, ?string $note): void
    {
        $this->db->update('news_item', ['archive_note' => $note], 'id = :id AND is_archived = 1', ['id' => $itemId]);
    }

    public function unarchive(int $itemId): void
    {
        $this->db->update('news_item', ['is_archived' => 0, 'archived_at' => null, 'archived_by' => null, 'archive_note' => null], 'id = :id', ['id' => $itemId]);
    }

    /** Supprime les entrées non archivées plus anciennes que la rétention de leur flux. */
    public function purgeExpired(): int
    {
        $deleted = 0;
        foreach ($this->feeds() as $feed) {
            $limit = Clock::utc(Clock::now()->modify('-' . max(1, (int) $feed['retention_days']) . ' days'));
            $deleted += $this->db->delete('news_item', 'feed_id = :f AND is_archived = 0 AND COALESCE(published_at, fetched_at) < :l', ['f' => (int) $feed['id'], 'l' => $limit]);
        }
        return $deleted;
    }

    public function countItems(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM news_item');
    }

    // ----- Interne -----

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $filters, int $userId): array
    {
        $where = [!empty($filters['archived']) ? 'i.is_archived = 1' : 'i.is_archived = 0'];
        $params = [];
        if (!empty($filters['category_id'])) {
            $where[] = 'f.category_id = :category';
            $params['category'] = (int) $filters['category_id'];
        }
        if (!empty($filters['feed_id'])) {
            $where[] = 'i.feed_id = :feed';
            $params['feed'] = (int) $filters['feed_id'];
        }
        if (!empty($filters['interest_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM news_item_interest ii WHERE ii.item_id = i.id AND ii.interest_id = :interest)';
            $params['interest'] = (int) $filters['interest_id'];
        }
        if (!empty($filters['unread'])) {
            $where[] = 'r.item_id IS NULL';
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(' . $this->db->lower('i.title') . ' LIKE :search OR ' . $this->db->lower("COALESCE(i.summary, '')") . ' LIKE :search OR ' . $this->db->lower("COALESCE(i.archive_note, '')") . ' LIKE :search)';
            $params['search'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param list<array<string, mixed>> $rows */
    private function attachInterests(array &$rows): void
    {
        if ($rows === []) {
            return;
        }
        $ids = [];
        $params = [];
        foreach ($rows as $i => $row) {
            $ids[] = ':i' . $i;
            $params['i' . $i] = (int) $row['id'];
        }
        $map = [];
        foreach ($this->db->select('SELECT ii.item_id, n.id, n.name, n.color FROM news_item_interest ii INNER JOIN news_interest n ON n.id = ii.interest_id WHERE ii.item_id IN (' . implode(', ', $ids) . ') ORDER BY n.position, n.name', $params) as $link) {
            $map[(int) $link['item_id']][] = ['id' => (int) $link['id'], 'name' => (string) $link['name'], 'color' => $link['color']];
        }
        foreach ($rows as &$row) {
            $row['interests'] = $map[(int) $row['id']] ?? [];
        }
        unset($row);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function feedColumns(array $data): array
    {
        return [
            'title' => (string) $data['title'],
            'url' => (string) $data['url'],
            'site_url' => $data['site_url'] ?? null,
            'description' => $data['description'] ?? null,
            'category_id' => isset($data['category_id']) && (int) $data['category_id'] > 0 ? (int) $data['category_id'] : null,
            'refresh_minutes' => (int) ($data['refresh_minutes'] ?? 60),
            'retention_days' => (int) ($data['retention_days'] ?? 30),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'fetch_content' => !array_key_exists('fetch_content', $data) || !empty($data['fetch_content']) ? 1 : 0,
        ];
    }

    public static function slugify(string $name): string
    {
        $slug = mb_strtolower(trim($name), 'UTF-8');
        $slug = strtr($slug, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae']);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug, '-');
        return $slug !== '' ? mb_substr($slug, 0, 64) : 'categorie';
    }
}
