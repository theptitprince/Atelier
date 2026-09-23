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

    /**
     * L'utilisateur peut-il voir cette pièce jointe (métadonnées comprises) ?
     *
     * Règle unique du noyau : le droit de lecture du jeu de données de l'information porteuse,
     * ou le fait d'avoir déposé le fichier. Le téléchargement et tout affichage de nom, de taille
     * ou d'aperçu s'appuient dessus, pour qu'un contenu rédigé par un tiers (une page qui cite un
     * identifiant de fichier) ne révèle rien à un lecteur non autorisé.
     *
     * @param array<string, mixed>|null $attachment
     */
    public function canReadAttachment(int $userId, ?array $attachment): bool
    {
        if ($attachment === null) {
            return false;
        }
        if ((int) ($attachment['uploaded_by'] ?? 0) === $userId) {
            return true;
        }
        if (($attachment['info_id'] ?? null) === null) {
            return false;
        }
        $info = $this->registry->get((string) $attachment['info_id']);
        return $info !== null && $this->catalog->canReadData($userId, (string) $info['dataset_code']);
    }
}
