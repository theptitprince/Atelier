<?php

declare(strict_types=1);

namespace Atelier\Shared;

use Atelier\Error\ConflictException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Persistence\Database;
use Atelier\Support\Clock;

/**
 * Dossiers virtuels des pièces jointes (table attachment_folders) : arborescence en base,
 * chemin matérialisé ("/Factures/2026") pour les fils d'Ariane et l'unicité, déplacement de
 * fichiers et de dossiers. Les fichiers eux-mêmes ne bougent jamais sur le disque.
 */
final class AttachmentFolderService
{
    public const NAME_MAX = 120;
    public const DEPTH_MAX = 8;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> tous les dossiers, triés par chemin, avec le nombre de fichiers */
    public function all(): array
    {
        return $this->db->select(
            'SELECT f.*, (SELECT COUNT(*) FROM attachments a WHERE a.folder_id = f.id AND a.deleted_at IS NULL) AS file_count
             FROM attachment_folders f ORDER BY f.path'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM attachment_folders WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    public function require(int $id): array
    {
        $folder = $this->find($id);
        if ($folder === null) {
            throw new NotFoundException('Dossier introuvable.');
        }
        return $folder;
    }

    /** @return list<array<string, mixed>> sous-dossiers directs */
    public function children(?int $parentId): array
    {
        return $this->db->select(
            'SELECT f.*, (SELECT COUNT(*) FROM attachments a WHERE a.folder_id = f.id AND a.deleted_at IS NULL) AS file_count,
                    (SELECT COUNT(*) FROM attachment_folders c WHERE c.parent_id = f.id) AS folder_count
             FROM attachment_folders f WHERE ' . ($parentId === null ? 'f.parent_id IS NULL' : 'f.parent_id = :p') . ' ORDER BY f.name',
            $parentId === null ? [] : ['p' => $parentId]
        );
    }

    /** Fil d'Ariane : de la racine au dossier. @return list<array<string, mixed>> */
    public function ancestors(int $id): array
    {
        $chain = [];
        $current = $this->find($id);
        $guard = 0;
        while ($current !== null && $guard++ < self::DEPTH_MAX + 1) {
            array_unshift($chain, $current);
            $current = $current['parent_id'] === null ? null : $this->find((int) $current['parent_id']);
        }
        return $chain;
    }

    public function create(string $name, ?int $parentId, ?int $userId): int
    {
        $name = $this->cleanName($name);
        $parent = $parentId === null ? null : $this->require($parentId);
        $path = ($parent === null ? '' : (string) $parent['path']) . '/' . $name;
        if ($parent !== null && substr_count((string) $parent['path'], '/') >= self::DEPTH_MAX) {
            throw ValidationException::single('name', 'Profondeur maximale de dossiers atteinte (' . self::DEPTH_MAX . ').');
        }
        if ($this->pathExists($path)) {
            throw ValidationException::single('name', 'Un dossier portant ce nom existe déjà à cet endroit.');
        }
        $now = Clock::utc();
        return $this->db->insert('attachment_folders', [
            'parent_id' => $parentId,
            'name' => $name,
            'path' => $path,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function rename(int $id, string $name): void
    {
        $folder = $this->require($id);
        $name = $this->cleanName($name);
        $parentPath = $folder['parent_id'] === null ? '' : (string) $this->require((int) $folder['parent_id'])['path'];
        $newPath = $parentPath . '/' . $name;
        if ($newPath !== $folder['path'] && $this->pathExists($newPath)) {
            throw ValidationException::single('name', 'Un dossier portant ce nom existe déjà à cet endroit.');
        }
        $this->db->transaction(function (Database $db) use ($id, $folder, $name, $newPath): void {
            $this->rewritePaths($db, (string) $folder['path'], $newPath);
            $db->update('attachment_folders', ['name' => $name, 'path' => $newPath, 'updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
        });
    }

    /** Déplace un dossier (et sa descendance) sous un autre parent (null = racine). */
    public function move(int $id, ?int $newParentId): void
    {
        $folder = $this->require($id);
        if ($newParentId === $id) {
            throw ValidationException::single('parent_id', 'Un dossier ne peut pas être déplacé dans lui-même.');
        }
        $parent = $newParentId === null ? null : $this->require($newParentId);
        if ($parent !== null && str_starts_with((string) $parent['path'] . '/', (string) $folder['path'] . '/')) {
            throw ValidationException::single('parent_id', 'Un dossier ne peut pas être déplacé dans l’un de ses sous-dossiers.');
        }
        $newPath = ($parent === null ? '' : (string) $parent['path']) . '/' . $folder['name'];
        if ($newPath !== $folder['path'] && $this->pathExists($newPath)) {
            throw ValidationException::single('parent_id', 'Un dossier portant ce nom existe déjà dans la destination.');
        }
        $this->db->transaction(function (Database $db) use ($id, $folder, $newParentId, $newPath): void {
            $this->rewritePaths($db, (string) $folder['path'], $newPath);
            $db->update('attachment_folders', ['parent_id' => $newParentId, 'path' => $newPath, 'updated_at' => Clock::utc()], 'id = :id', ['id' => $id]);
        });
    }

    /**
     * Supprime un dossier : ses fichiers et sous-dossiers remontent dans le dossier parent
     * (aucun fichier n'est supprimé).
     */
    public function delete(int $id): void
    {
        $folder = $this->require($id);
        $parentId = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
        $this->db->transaction(function (Database $db) use ($id, $folder, $parentId): void {
            $db->update('attachments', ['folder_id' => $parentId], 'folder_id = :id', ['id' => $id]);
            foreach ($this->children($id) as $child) {
                $childNewPath = ($parentId === null ? '' : (string) $this->require($parentId)['path']) . '/' . $child['name'];
                if ($this->pathExists($childNewPath)) {
                    throw new ConflictException('Impossible de supprimer : le sous-dossier « ' . $child['name'] . ' » existe déjà dans le dossier parent.');
                }
                $this->rewritePaths($db, (string) $child['path'], $childNewPath);
                $db->update('attachment_folders', ['parent_id' => $parentId, 'path' => $childNewPath, 'updated_at' => Clock::utc()], 'id = :id', ['id' => (int) $child['id']]);
            }
            $db->delete('attachment_folders', 'id = :id', ['id' => $id]);
        });
    }

    /** Range un fichier dans un dossier (null = racine). */
    public function moveAttachment(string $attachmentId, ?int $folderId): void
    {
        if ($folderId !== null) {
            $this->require($folderId);
        }
        $this->db->update('attachments', ['folder_id' => $folderId], 'id = :id', ['id' => $attachmentId]);
    }

    /** @param list<string> $attachmentIds */
    public function moveAttachments(array $attachmentIds, ?int $folderId): int
    {
        $count = 0;
        foreach ($attachmentIds as $attachmentId) {
            $this->moveAttachment((string) $attachmentId, $folderId);
            $count++;
        }
        return $count;
    }

    /** Identifiants du dossier et de toute sa descendance (pour une recherche « dans ce dossier et ses sous-dossiers »). @return list<int> */
    public function subtreeIds(int $id): array
    {
        $folder = $this->require($id);
        $rows = $this->db->select('SELECT id FROM attachment_folders WHERE path = :p OR path LIKE :like', ['p' => $folder['path'], 'like' => $folder['path'] . '/%']);
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $name = str_replace(['/', '\\'], '-', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw ValidationException::single('name', 'Le nom du dossier est obligatoire.');
        }
        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw ValidationException::single('name', 'Le nom du dossier ne doit pas dépasser ' . self::NAME_MAX . ' caractères.');
        }
        return $name;
    }

    private function pathExists(string $path): bool
    {
        return $this->db->selectOne('SELECT id FROM attachment_folders WHERE path = :p', ['p' => $path]) !== null;
    }

    /** Réécrit les chemins matérialisés de la descendance après renommage ou déplacement. */
    private function rewritePaths(Database $db, string $oldPath, string $newPath): void
    {
        $rows = $db->select('SELECT id, path FROM attachment_folders WHERE path LIKE :like', ['like' => $oldPath . '/%']);
        foreach ($rows as $row) {
            $db->update('attachment_folders', ['path' => $newPath . substr((string) $row['path'], strlen($oldPath))], 'id = :id', ['id' => (int) $row['id']]);
        }
    }
}
