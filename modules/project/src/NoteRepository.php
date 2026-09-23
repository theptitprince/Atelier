<?php

declare(strict_types=1);

namespace Atelier\Modules\Project;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Journal de bord d'un projet (table project_note) : notes datées, auteur.
 */
final class NoteRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> notes du plus récent au plus ancien, avec le nom de l'auteur */
    public function ofProject(int $projectId): array
    {
        return $this->db->select(
            'SELECT n.*, u.display_name AS author_name FROM project_note n LEFT JOIN users u ON u.id = n.created_by WHERE n.project_id = :p ORDER BY n.created_at DESC, n.id DESC',
            ['p' => $projectId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $projectId, int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM project_note WHERE id = :id AND project_id = :p', ['id' => $id, 'p' => $projectId]);
    }

    public function create(int $projectId, string $content, ?int $userId): int
    {
        return $this->db->insert('project_note', [
            'project_id' => $projectId,
            'content' => $content,
            'created_by' => $userId,
            'created_at' => Clock::utc(),
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('project_note', 'id = :id', ['id' => $id]);
    }

    public function countOfProject(int $projectId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM project_note WHERE project_id = :p', ['p' => $projectId]);
    }
}
