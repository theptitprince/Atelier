<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Rattrapage : les fichiers joints déjà en corbeille reçoivent l'état « en corbeille » dans le
 * registre commun (entrée « attachments.file »), posé désormais à chaque mise en corbeille.
 * Rejouable sans effet : seules les entrées encore vivantes d'un fichier supprimé sont touchées.
 */
return static function (Database $db): void {
    $db->execute(
        "UPDATE info_registry
         SET trashed_at = (SELECT a.deleted_at FROM attachments a WHERE a.id = info_registry.local_key)
         WHERE dataset_code = 'attachments.file'
           AND trashed_at IS NULL
           AND EXISTS (SELECT 1 FROM attachments a WHERE a.id = info_registry.local_key AND a.deleted_at IS NOT NULL)"
    );
};
