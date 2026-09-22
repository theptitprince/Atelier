<?php

declare(strict_types=1);

namespace Atelier\Shared;

/**
 * Regroupe les mécanismes transversaux : registre d'informations, tags, relations,
 * pièces jointes et catalogue des jeux de données partagés.
 */
final class SharedServices
{
    public function __construct(
        public readonly InfoRegistry $registry,
        public readonly TagService $tags,
        public readonly RelationService $relations,
        public readonly AttachmentService $attachments,
        public readonly DatasetCatalog $catalog,
        public readonly ?AttachmentFolderService $folders = null,
    ) {
    }
}
