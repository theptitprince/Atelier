<?php

declare(strict_types=1);

namespace Atelier\Shared;

use Atelier\Error\ValidationException;
use Atelier\Persistence\Database;
use Atelier\Support\Clock;
use Atelier\Support\Str;

/**
 * Tags partagés (portée "shared") et tags privés d'un module (portée = identifiant du module).
 * Unicité insensible à la casse, espaces superflus supprimés, caractère # non stocké.
 */
final class TagService
{
    public const SHARED = 'shared';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Trouve ou crée un tag dans la portée donnée.
     *
     * @return array<string, mixed>
     */
    public function findOrCreate(string $name, string $scope = self::SHARED, ?int $userId = null): array
    {
        $normalized = Str::normalizeTag($name);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 60) {
            throw ValidationException::single('tag', 'Le tag doit comporter entre 1 et 60 caractères.');
        }
        $existing = $this->db->selectOne('SELECT * FROM tags WHERE scope = :s AND normalized = :n', ['s' => $scope, 'n' => $normalized]);
        if ($existing !== null) {
            return $existing;
        }
        $display = trim(ltrim(trim($name), '#'));
        $display = preg_replace('/\s+/u', ' ', $display) ?? $display;
        $id = $this->db->insert('tags', ['name' => $display, 'normalized' => $normalized, 'scope' => $scope, 'created_by' => $userId, 'created_at' => Clock::utc()]);
        return ['id' => $id, 'name' => $display, 'normalized' => $normalized, 'scope' => $scope, 'created_by' => $userId];
    }

    public function attach(string $infoId, string $tagName, string $scope = self::SHARED, ?int $userId = null): array
    {
        $tag = $this->findOrCreate($tagName, $scope, $userId);
        $exists = $this->db->selectOne('SELECT 1 AS x FROM info_tags WHERE info_id = :i AND tag_id = :t', ['i' => $infoId, 't' => (int) $tag['id']]);
        if ($exists === null) {
            $this->db->insert('info_tags', ['info_id' => $infoId, 'tag_id' => (int) $tag['id'], 'added_by' => $userId, 'added_at' => Clock::utc()]);
        }
        return $tag;
    }

    public function detach(string $infoId, int $tagId): void
    {
        $this->db->delete('info_tags', 'info_id = :i AND tag_id = :t', ['i' => $infoId, 't' => $tagId]);
    }

    /** Remplace l'ensemble des tags d'une information dans une portée. @param list<string> $names */
    public function replace(string $infoId, array $names, string $scope = self::SHARED, ?int $userId = null): void
    {
        $this->db->transaction(function (Database $db) use ($infoId, $names, $scope, $userId): void {
            $db->execute('DELETE FROM info_tags WHERE info_id = :i AND tag_id IN (SELECT id FROM tags WHERE scope = :s)', ['i' => $infoId, 's' => $scope]);
            $seen = [];
            foreach ($names as $name) {
                $normalized = Str::normalizeTag((string) $name);
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $this->attach($infoId, (string) $name, $scope, $userId);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function tagsOf(string $infoId, ?string $scope = self::SHARED): array
    {
        $sql = 'SELECT t.* FROM tags t INNER JOIN info_tags it ON it.tag_id = t.id WHERE it.info_id = :i';
        $params = ['i' => $infoId];
        if ($scope !== null) {
            $sql .= ' AND t.scope = :s';
            $params['s'] = $scope;
        }
        return $this->db->select($sql . ' ORDER BY t.normalized', $params);
    }

    /** @return list<array<string, mixed>> informations portant le tag */
    public function infosWithTag(int $tagId, int $limit = 200): array
    {
        return $this->db->select(
            'SELECT r.* FROM info_registry r INNER JOIN info_tags it ON it.info_id = r.id WHERE it.tag_id = :t ORDER BY r.label LIMIT ' . $limit,
            ['t' => $tagId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function search(string $term, string $scope = self::SHARED, int $limit = 20): array
    {
        return $this->db->select(
            'SELECT t.*, (SELECT COUNT(*) FROM info_tags it WHERE it.tag_id = t.id) AS usage_count FROM tags t WHERE t.scope = :s AND t.normalized LIKE :term ORDER BY t.normalized LIMIT ' . $limit,
            ['s' => $scope, 'term' => Str::normalizeTag($term) . '%']
        );
    }

    /** @return list<array<string, mixed>> */
    public function all(string $scope = self::SHARED): array
    {
        return $this->db->select(
            'SELECT t.*, (SELECT COUNT(*) FROM info_tags it WHERE it.tag_id = t.id) AS usage_count FROM tags t WHERE t.scope = :s ORDER BY t.normalized',
            ['s' => $scope]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $tagId): ?array
    {
        return $this->db->selectOne('SELECT * FROM tags WHERE id = :id', ['id' => $tagId]);
    }

    /** Renommage (permission tags.manage requise en amont). */
    public function rename(int $tagId, string $newName): void
    {
        $tag = $this->find($tagId);
        if ($tag === null) {
            return;
        }
        $normalized = Str::normalizeTag($newName);
        if ($normalized === '') {
            throw ValidationException::single('name', 'Le nom du tag est obligatoire.');
        }
        $conflict = $this->db->selectOne('SELECT id FROM tags WHERE scope = :s AND normalized = :n AND id <> :id', ['s' => $tag['scope'], 'n' => $normalized, 'id' => $tagId]);
        if ($conflict !== null) {
            throw ValidationException::single('name', 'Un tag portant ce nom existe déjà : utilisez la fusion.');
        }
        $display = preg_replace('/\s+/u', ' ', trim(ltrim(trim($newName), '#'))) ?? $newName;
        $this->db->update('tags', ['name' => $display, 'normalized' => $normalized], 'id = :id', ['id' => $tagId]);
    }

    /** Fusionne $sourceId dans $targetId puis supprime la source. */
    public function merge(int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            return;
        }
        $this->db->transaction(function (Database $db) use ($sourceId, $targetId): void {
            $rows = $db->select('SELECT info_id, added_by, added_at FROM info_tags WHERE tag_id = :s', ['s' => $sourceId]);
            foreach ($rows as $row) {
                $exists = $db->selectOne('SELECT 1 AS x FROM info_tags WHERE info_id = :i AND tag_id = :t', ['i' => $row['info_id'], 't' => $targetId]);
                if ($exists === null) {
                    $db->insert('info_tags', ['info_id' => $row['info_id'], 'tag_id' => $targetId, 'added_by' => $row['added_by'], 'added_at' => $row['added_at']]);
                }
            }
            $db->delete('tags', 'id = :id', ['id' => $sourceId]);
        });
    }

    public function delete(int $tagId): void
    {
        $this->db->delete('tags', 'id = :id', ['id' => $tagId]);
    }
}
