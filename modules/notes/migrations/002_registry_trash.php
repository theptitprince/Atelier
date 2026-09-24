<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Bloc-notes 1.2.2 : rattrapage de l'état « en corbeille » du registre commun.
 *
 * Avant cette version, la mise à la corbeille d'une note ne prévenait pas le registre : les notes
 * déjà en corbeille restaient listées sous leurs tags avec un lien en 404. La date reprend
 * deleted_at. Rejouable : seules les entrées encore non marquées sont touchées.
 */
return static function (Database $db): void {
    $cast = $db->isSqlite() ? 'TEXT' : 'CHAR';
    $db->execute(
        "UPDATE info_registry
         SET trashed_at = (SELECT n.deleted_at FROM notes_note n WHERE CAST(n.id AS $cast) = info_registry.local_key)
         WHERE dataset_code = 'notes.note' AND trashed_at IS NULL
           AND EXISTS (SELECT 1 FROM notes_note n WHERE CAST(n.id AS $cast) = info_registry.local_key AND n.deleted_at IS NOT NULL)"
    );
};
