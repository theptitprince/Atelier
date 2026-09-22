<?php

declare(strict_types=1);

namespace Atelier\Modules\Tags;

use Atelier\Persistence\Database;
use Atelier\Shared\TagService;

/**
 * Requêtes de lecture complémentaires à TagService (tables du noyau `tags` et `info_tags`).
 * Aucune écriture : les modifications passent exclusivement par TagService.
 */
final class TagQueries
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Nombre d'informations portant le tag. */
    public function usageCount(int $tagId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM info_tags WHERE tag_id = :t', ['t' => $tagId]);
    }

    /**
     * Tags voisins : tags partagés les plus souvent présents sur les mêmes informations.
     *
     * @return list<array<string, mixed>> id, name, shared_count
     */
    public function neighbors(int $tagId, int $limit = 12): array
    {
        return $this->db->select(
            'SELECT t.id, t.name, COUNT(*) AS shared_count
             FROM info_tags a
             INNER JOIN info_tags b ON b.info_id = a.info_id AND b.tag_id <> a.tag_id
             INNER JOIN tags t ON t.id = b.tag_id
             WHERE a.tag_id = :t AND t.scope = :s
             GROUP BY t.id, t.name, t.normalized
             ORDER BY shared_count DESC, t.normalized
             LIMIT ' . max(1, $limit),
            ['t' => $tagId, 's' => TagService::SHARED]
        );
    }
}
