<?php

declare(strict_types=1);

namespace Atelier\Shared;

use Atelier\Error\ValidationException;
use Atelier\Persistence\Database;
use Atelier\Support\Clock;
use Atelier\Support\Str;

/**
 * Relations typées entre deux informations du registre, éventuellement issues de modules différents.
 */
final class RelationService
{
    /** Types de relation proposés par défaut (un module peut en déclarer d'autres). */
    public const DEFAULT_TYPES = [
        'related' => 'En rapport avec',
        'parent' => 'Parent de',
        'child' => 'Enfant de',
        'references' => 'Fait référence à',
        'duplicates' => 'Doublon de',
        'depends_on' => 'Dépend de',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function relate(string $type, string $fromInfo, string $toInfo, ?int $userId = null, ?string $comment = null): int
    {
        if (!Str::isSlug($type, 32)) {
            throw ValidationException::single('type', 'Type de relation invalide.');
        }
        if ($fromInfo === $toInfo) {
            throw ValidationException::single('to', 'Une information ne peut pas être reliée à elle-même.');
        }
        $existing = $this->db->selectOne('SELECT id FROM relations WHERE type = :t AND from_info = :f AND to_info = :o', ['t' => $type, 'f' => $fromInfo, 'o' => $toInfo]);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        return $this->db->insert('relations', [
            'type' => $type,
            'from_info' => $fromInfo,
            'to_info' => $toInfo,
            'comment' => $comment,
            'created_by' => $userId,
            'created_at' => Clock::utc(),
        ]);
    }

    public function remove(int $relationId): void
    {
        $this->db->delete('relations', 'id = :id', ['id' => $relationId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $relationId): ?array
    {
        return $this->db->selectOne('SELECT * FROM relations WHERE id = :id', ['id' => $relationId]);
    }

    /**
     * Relations dans les deux sens, avec les informations liées (libellé, jeu de données, module).
     *
     * Les informations liées qui sont en corbeille sont écartées par défaut : leur lien menait à
     * une erreur 404. La relation elle-même est conservée et réapparaît à la restauration.
     *
     * @return list<array<string, mixed>>
     */
    public function relationsOf(string $infoId, bool $includeTrashed = false): array
    {
        $rows = $this->db->select(
            'SELECT rel.*,
                    CASE WHEN rel.from_info = :i THEN \'out\' ELSE \'in\' END AS direction,
                    other.id AS other_id, other.label AS other_label, other.dataset_code AS other_dataset, other.module_id AS other_module, other.local_key AS other_key
             FROM relations rel
             INNER JOIN info_registry other ON other.id = CASE WHEN rel.from_info = :i THEN rel.to_info ELSE rel.from_info END
             WHERE (rel.from_info = :i OR rel.to_info = :i)' . ($includeTrashed ? '' : ' AND other.trashed_at IS NULL') . '
             ORDER BY rel.type, other.label',
            ['i' => $infoId]
        );
        foreach ($rows as &$row) {
            $row['type_label'] = self::DEFAULT_TYPES[$row['type']] ?? $row['type'];
        }
        unset($row);
        return $rows;
    }

    public function countFor(string $infoId): int
    {
        return $this->db->count(
            'SELECT COUNT(*) FROM relations rel
             INNER JOIN info_registry other ON other.id = CASE WHEN rel.from_info = :i THEN rel.to_info ELSE rel.from_info END
             WHERE (rel.from_info = :i OR rel.to_info = :i) AND other.trashed_at IS NULL',
            ['i' => $infoId]
        );
    }
}
