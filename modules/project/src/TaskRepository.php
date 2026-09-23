<?php

declare(strict_types=1);

namespace Atelier\Modules\Project;

use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Tâches d'un projet (table project_task) : liste ordonnée, cocher, réordonner, suppression logique.
 */
final class TaskRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> tâches actives dans l'ordre */
    public function ofProject(int $projectId): array
    {
        return $this->db->select('SELECT * FROM project_task WHERE project_id = :p AND deleted_at IS NULL ORDER BY position ASC, id ASC', ['p' => $projectId]);
    }

    /** @return array<string, mixed>|null tâche active d'un projet */
    public function find(int $projectId, int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM project_task WHERE id = :id AND project_id = :p AND deleted_at IS NULL', ['id' => $id, 'p' => $projectId]);
    }

    public function create(int $projectId, string $title, ?string $dueDate): int
    {
        $max = $this->db->scalar('SELECT MAX(position) FROM project_task WHERE project_id = :p AND deleted_at IS NULL', ['p' => $projectId]);
        return $this->db->insert('project_task', [
            'project_id' => $projectId,
            'title' => $title,
            'done' => 0,
            'due_date' => $dueDate,
            'position' => $max === null ? 1 : (int) $max + 1,
            'created_at' => Clock::utc(),
            'deleted_at' => null,
        ]);
    }

    public function setDone(int $id, bool $done): void
    {
        $this->db->update('project_task', ['done' => $done ? 1 : 0], 'id = :id', ['id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->db->update('project_task', ['deleted_at' => Clock::utc()], 'id = :id', ['id' => $id]);
    }

    /**
     * Déplace une tâche d'un cran vers le haut (-1) ou le bas (+1) en échangeant les positions.
     * Renumérote d'abord les positions (1..n) pour absorber les trous laissés par les suppressions.
     */
    public function move(int $projectId, int $id, int $direction): bool
    {
        $tasks = $this->ofProject($projectId);
        $index = null;
        foreach ($tasks as $i => $task) {
            if ((int) $task['id'] === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return false;
        }
        $target = $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= count($tasks)) {
            return false;
        }
        [$tasks[$index], $tasks[$target]] = [$tasks[$target], $tasks[$index]];
        $this->db->transaction(function () use ($tasks): void {
            foreach ($tasks as $i => $task) {
                $this->db->update('project_task', ['position' => $i + 1], 'id = :id', ['id' => (int) $task['id']]);
            }
        });
        return true;
    }
}
