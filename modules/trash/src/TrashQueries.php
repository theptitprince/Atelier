<?php

declare(strict_types=1);

namespace Atelier\Modules\Trash;

use Atelier\Persistence\Database;

/**
 * Requêtes propres à la corbeille globale sur les tables du noyau (pièces jointes supprimées).
 * AttachmentService ne fournit pas de liste complète des pièces jointes en corbeille avec le
 * jeu de données de l'information rattachée : cette classe regroupe le SQL nécessaire.
 * Les écritures (restauration, purge) passent toujours par AttachmentService.
 */
final class TrashQueries
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Pièces jointes supprimées logiquement, avec l'auteur et l'information rattachée.
     *
     * @return list<array<string, mixed>>
     */
    public function deletedAttachments(): array
    {
        return $this->db->select(
            'SELECT a.id, a.original_name, a.label, a.size, a.mime, a.deleted_at, a.uploaded_by, a.info_id,
                    u.username AS uploader, u.display_name AS uploader_name,
                    r.label AS info_label, r.dataset_code, r.module_id AS info_module
             FROM attachments a
             LEFT JOIN users u ON u.id = a.uploaded_by
             LEFT JOIN info_registry r ON r.id = a.info_id
             WHERE a.deleted_at IS NOT NULL
             ORDER BY a.deleted_at DESC'
        );
    }

    /** Nombre de pièces jointes supprimées avant la date limite UTC donnée (rétention dépassée). */
    public function countExpiredAttachments(string $limitUtc): int
    {
        return $this->db->count(
            'SELECT COUNT(*) FROM attachments WHERE deleted_at IS NOT NULL AND deleted_at < :l',
            ['l' => $limitUtc]
        );
    }
}
