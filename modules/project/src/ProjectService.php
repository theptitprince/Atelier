<?php

declare(strict_types=1);

namespace Atelier\Modules\Project;

use Atelier\Error\ForbiddenException;
use Atelier\Modules\ModuleContext;
use Atelier\View\BbCode;

/**
 * Service intermodule du jeu partagé « project.project » : lecture des projets (titre, statut,
 * résumé, dates, indicateurs) et des informations reliées, pour les autres modules, après
 * contrôle du droit de lecture sur le jeu (ressource atelier/project/data/project).
 */
final class ProjectService
{
    public const DATASET = 'project.project';

    /** Type de relation par défaut : information (source) → projet (cible), « Fait partie du projet ». */
    public const RELATION = 'part_of';

    private ?ProjectRepository $repository = null;

    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * Projets actifs visibles par l'utilisateur, du plus récemment modifié au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $viewerUserId, ?string $status = null): array
    {
        $this->assertReadable($viewerUserId);
        $rows = $this->repository()->listActive('', null, 'updated');
        if ($status !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === $status));
        }
        return array_map(fn (array $row): array => $this->publicRow($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $viewerUserId, int $id): ?array
    {
        $this->assertReadable($viewerUserId);
        $row = $this->repository()->find($id);
        return $row === null ? null : $this->publicRow($row);
    }

    /** Identifiant du registre commun d'un projet (relations, tags, pièces jointes), ou null. */
    public function infoId(int $viewerUserId, int $id): ?string
    {
        if ($this->find($viewerUserId, $id) === null) {
            return null;
        }
        $info = $this->ctx->shared->registry->find(self::DATASET, (string) $id);
        return $info === null ? null : (string) $info['id'];
    }

    /**
     * Identifiants (registre commun) des informations reliées au projet, limités aux jeux partagés
     * que l'utilisateur peut lire — un jeu privé ou non autorisé n'est jamais révélé.
     *
     * @return list<array{info_id: string, label: string, dataset: string, module: string, key: string, type: string, relation_id: int}>
     */
    public function linkedInfoIds(int $viewerUserId, int $id): array
    {
        $infoId = $this->infoId($viewerUserId, $id);
        if ($infoId === null) {
            return [];
        }
        $readable = array_flip($this->ctx->shared->catalog->readableCodes($viewerUserId));
        $result = [];
        foreach ($this->ctx->shared->relations->relationsOf($infoId) as $relation) {
            if (!isset($readable[(string) $relation['other_dataset']])) {
                continue;
            }
            $result[] = [
                'info_id' => (string) $relation['other_id'],
                'label' => (string) ($relation['other_label'] ?? $relation['other_key']),
                'dataset' => (string) $relation['other_dataset'],
                'module' => (string) $relation['other_module'],
                'key' => (string) $relation['other_key'],
                'type' => (string) $relation['type'],
                'relation_id' => (int) $relation['id'],
            ];
        }
        return $result;
    }

    private function assertReadable(int $viewerUserId): void
    {
        if (!$this->ctx->shared->catalog->canAccess($viewerUserId, self::DATASET, 'read')) {
            throw new ForbiddenException('Accès au jeu de données « ' . self::DATASET . ' » refusé.', 'atelier/project/data/project', 'read');
        }
    }

    private function repository(): ProjectRepository
    {
        return $this->repository ??= new ProjectRepository($this->ctx->db);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'status' => (string) $row['status'],
            'status_label' => ProjectModule::STATUSES[$row['status']] ?? (string) $row['status'],
            'summary' => $row['summary'] !== null ? (string) $row['summary'] : null,
            'excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', BbCode::toText((string) ($row['description'] ?? ''))) ?? ''), 0, 240, 'UTF-8'),
            'start_date' => $row['start_date'] !== null ? (string) $row['start_date'] : null,
            'due_date' => $row['due_date'] !== null ? (string) $row['due_date'] : null,
            'budget_estimate' => $row['budget_estimate'] === null ? null : (int) $row['budget_estimate'],
            'priority' => (int) $row['priority'],
            'task_total' => (int) ($row['task_total'] ?? 0),
            'task_done' => (int) ($row['task_done'] ?? 0),
            'updated_at' => (string) $row['updated_at'],
            'route' => 'show/' . $row['id'],
        ];
    }
}
