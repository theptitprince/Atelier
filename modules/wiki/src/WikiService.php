<?php

declare(strict_types=1);

namespace Atelier\Modules\Wiki;

use Atelier\Error\ForbiddenException;
use Atelier\Modules\ModuleContext;
use Atelier\View\BbCode;

/**
 * Service intermodule du jeu partagé « wiki.page » : lecture des pages (titre, slug, extrait,
 * contenu) pour les autres modules, après contrôle du droit de lecture sur le jeu.
 */
final class WikiService
{
    public const DATASET = 'wiki.page';

    private ?WikiRepository $repository = null;

    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $this->assertReadable();
        $page = $this->repository()->find($id);
        return $page === null ? null : $this->publicRow($page);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $this->assertReadable();
        $page = $this->repository()->findBySlug($slug);
        return $page === null ? null : $this->publicRow($page);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $term, int $limit = 20): array
    {
        $this->assertReadable();
        return array_map(fn (array $row): array => $this->publicRow($row), $this->repository()->search($term, $limit));
    }

    /** Identifiant du registre commun d'une page (relations, tags, pièces jointes). */
    public function infoId(int $id): ?string
    {
        if ($this->find($id) === null) {
            return null;
        }
        $info = $this->ctx->shared->registry->find(self::DATASET, (string) $id);
        return $info === null ? null : (string) $info['id'];
    }

    private function assertReadable(): void
    {
        if (!$this->ctx->shared->catalog->canAccess($this->ctx->userId(), self::DATASET, 'read')) {
            throw new ForbiddenException('Accès au jeu de données « ' . self::DATASET . ' » refusé.', 'atelier/wiki/data/page', 'read');
        }
    }

    private function repository(): WikiRepository
    {
        return $this->repository ??= new WikiRepository($this->ctx->db);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicRow(array $row): array
    {
        $content = (string) ($row['content'] ?? ($row['excerpt'] ?? ''));
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', BbCode::toText($content)) ?? ''), 0, 240, 'UTF-8'),
            'content' => $row['content'] ?? null,
            'updated_at' => (string) $row['updated_at'],
            'route' => 'show/' . $row['slug'],
        ];
    }
}
