<?php

declare(strict_types=1);

namespace Atelier\Modules\Attachments;

use Atelier\Modules\ModuleContext;

/**
 * Règles d'accès à un fichier joint, appliquées côté serveur à chaque téléchargement et action :
 *   - l'auteur du fichier peut le consulter et le gérer ;
 *   - un utilisateur disposant de la permission « assist » du module peut tout consulter et gérer ;
 *   - un fichier rattaché à une information est consultable par quiconque peut lire le jeu de données
 *     partagé de cette information (catalogue) ; il n'est pas gérable pour autant.
 * Les permissions génériques du module (open, read, create, update, delete) restent contrôlées
 * par le noyau sur chaque route ; ces règles s'y ajoutent.
 */
final class AttachmentAccess
{
    public function __construct(private readonly ModuleContext $ctx, private readonly bool $assist)
    {
    }

    public function isAssist(): bool
    {
        return $this->assist;
    }

    /** @param array<string, mixed> $attachment */
    public function isOwner(array $attachment): bool
    {
        return (int) ($attachment['uploaded_by'] ?? 0) === $this->ctx->userId();
    }

    /** @param array<string, mixed> $attachment */
    public function canRead(array $attachment): bool
    {
        if ($this->assist || $this->isOwner($attachment)) {
            return true;
        }
        $dataset = $attachment['info_dataset'] ?? null;
        if ($attachment['info_id'] !== null && is_string($dataset) && $dataset !== '') {
            return $this->ctx->shared->catalog->canAccess($this->ctx->userId(), $dataset, 'read');
        }
        return false;
    }

    /** @param array<string, mixed> $attachment */
    public function canManage(array $attachment): bool
    {
        return $this->assist || $this->isOwner($attachment);
    }

    /**
     * Une information du registre peut-elle recevoir un fichier de cet utilisateur ?
     * Oui si son jeu partagé est lisible, ou si l'utilisateur en est l'auteur.
     *
     * @param array<string, mixed> $info ligne du registre
     */
    public function canLinkTo(array $info): bool
    {
        if ((int) ($info['created_by'] ?? 0) === $this->ctx->userId()) {
            return true;
        }
        return $this->ctx->shared->catalog->canAccess($this->ctx->userId(), (string) $info['dataset_code'], 'read');
    }
}
