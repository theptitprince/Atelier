<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Pages 1.2.4 : rattrapage de l'état « en corbeille » du registre commun.
 *
 * Avant cette version, la mise à la corbeille d'une page ne prévenait pas le registre : les pages
 * déjà en corbeille restaient listées sous leurs tags et dans la fiche des projets auxquels elles
 * étaient liées, avec un lien en 404. Les révisions et liens internes ne sont pas inscrits au
 * registre : rien à marquer par cascade. Rejouable : seules les entrées non marquées sont touchées.
 */
return static function (Database $db): void {
    $cast = $db->isSqlite() ? 'TEXT' : 'CHAR';
    $db->execute(
        "UPDATE info_registry
         SET trashed_at = (SELECT p.deleted_at FROM wiki_page p WHERE CAST(p.id AS $cast) = info_registry.local_key)
         WHERE dataset_code = 'wiki.page' AND trashed_at IS NULL
           AND EXISTS (SELECT 1 FROM wiki_page p WHERE CAST(p.id AS $cast) = info_registry.local_key AND p.deleted_at IS NOT NULL)"
    );
};
