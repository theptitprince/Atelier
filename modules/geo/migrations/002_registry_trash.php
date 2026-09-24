<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Coordonnées GPS 1.2.0 : rattrapage de l'état « en corbeille » du registre commun.
 *
 * Avant cette version, la mise à la corbeille d'un point ne prévenait pas le registre : les points
 * déjà en corbeille restaient listés sous leurs tags et dans les éléments liés, avec un lien en 404.
 * La date de mise en corbeille reprend deleted_at. Rejouable : seules les entrées encore non
 * marquées sont touchées (local_key est une chaîne, d'où la conversion de l'identifiant).
 */
return static function (Database $db): void {
    $cast = $db->isSqlite() ? 'TEXT' : 'CHAR';
    $db->execute(
        "UPDATE info_registry
         SET trashed_at = (SELECT p.deleted_at FROM geo_point p WHERE CAST(p.id AS $cast) = info_registry.local_key)
         WHERE dataset_code = 'geo.point' AND trashed_at IS NULL
           AND EXISTS (SELECT 1 FROM geo_point p WHERE CAST(p.id AS $cast) = info_registry.local_key AND p.deleted_at IS NOT NULL)"
    );
};
