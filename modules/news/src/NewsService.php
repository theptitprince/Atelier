<?php

declare(strict_types=1);

namespace Atelier\Modules\News;

use Atelier\Error\ForbiddenException;
use Atelier\Modules\ModuleContext;

/**
 * Service intermodule du jeu partagé « news.archive » : les faits archivés (titre, source, date,
 * note) sont consultables par les autres modules après contrôle du droit de lecture sur le jeu.
 * Les entrées non archivées ne sont jamais exposées.
 */
final class NewsService
{
    public const DATASET = 'news.archive';

    private const PUBLIC_FIELDS = ['id', 'title', 'url', 'summary', 'author', 'published_at', 'archived_at', 'archive_note', 'feed_title', 'category_name'];

    private ?NewsRepository $repository = null;

    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @return array<string, mixed>|null fait archivé (champs publics + interests) */
    public function find(int $id): ?array
    {
        $this->assertReadable();
        $row = $this->repository()->findItem($id);
        return $row === null || (int) $row['is_archived'] !== 1 ? null : $this->publicRow($row);
    }

    /**
     * Faits archivés les plus récents, avec recherche facultative (titre, résumé, note).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term = '', int $limit = 20): array
    {
        $this->assertReadable();
        $result = $this->repository()->paginate(['archived' => true, 'q' => $term], $this->ctx->userId(), 1, $limit);
        return array_map([$this, 'publicRow'], $result['rows']);
    }

    /** Identifiant global (registre) d'un fait archivé. */
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
            throw new ForbiddenException('Accès au jeu de données « ' . self::DATASET . ' » refusé.', 'atelier/news/data/archive', 'read');
        }
    }

    private function repository(): NewsRepository
    {
        return $this->repository ??= new NewsRepository($this->ctx->db);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicRow(array $row): array
    {
        $public = array_intersect_key($row, array_flip(self::PUBLIC_FIELDS));
        $public['id'] = (int) $public['id'];
        $public['interests'] = array_map(static fn (array $i): string => $i['name'], $row['interests'] ?? []);
        return $public;
    }
}
