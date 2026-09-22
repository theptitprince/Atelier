<?php

declare(strict_types=1);

namespace Atelier\Modules;

/**
 * Contrat optionnel : un module qui gère une suppression logique (corbeille) l'expose au
 * module Corbeille globale en implémentant cette interface sur sa classe d'entrée.
 *
 * Chaque élément retourné est un tableau :
 *   id          identifiant local (chaîne), stable
 *   label       libellé affichable
 *   dataset     code du jeu de données (ex. notes.note)
 *   deleted_at  date UTC "Y-m-d H:i:s"
 *   deleted_by  identifiant de l'utilisateur ayant supprimé (ou null)
 *   purge_at    date UTC de purge automatique prévue (ou null)
 *   can_restore bool — l'utilisateur courant peut restaurer
 *   can_purge   bool — l'utilisateur courant peut supprimer définitivement
 *
 * Le module reste seul juge des droits : il ne retourne que ce que l'utilisateur courant
 * (via $this->ctx->userId()) a le droit de voir, et revérifie les droits dans restore/purge.
 */
interface TrashProviderInterface
{
    /**
     * Éléments en corbeille visibles par l'utilisateur courant.
     *
     * @return list<array{id: string, label: string, dataset: string, deleted_at: string, deleted_by: ?int, purge_at: ?string, can_restore: bool, can_purge: bool}>
     */
    public function trashItems(): array;

    /** Restaure un élément ; lève NotFoundException/ForbiddenException si impossible. */
    public function restoreTrashItem(string $id): void;

    /** Supprime définitivement un élément ; lève NotFoundException/ForbiddenException si impossible. */
    public function purgeTrashItem(string $id): void;
}
