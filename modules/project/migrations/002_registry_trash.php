<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Projets 1.0.2 : rattrapage de l'état « en corbeille » du registre commun.
 *
 * Avant cette version, la mise à la corbeille d'un projet ne prévenait pas le registre : les
 * projets déjà en corbeille restaient listés sous leurs tags et dans les éléments liés des autres
 * modules, avec un lien en 404. Tâches et journal ne sont pas inscrits au registre : rien à marquer
 * par cascade. Rejouable : seules les entrées encore non marquées sont touchées.
 */
return static function (Database $db): void {
    $cast = $db->isSqlite() ? 'TEXT' : 'CHAR';
    $db->execute(
        "UPDATE info_registry
         SET trashed_at = (SELECT p.deleted_at FROM project_project p WHERE CAST(p.id AS $cast) = info_registry.local_key)
         WHERE dataset_code = 'project.project' AND trashed_at IS NULL
           AND EXISTS (SELECT 1 FROM project_project p WHERE CAST(p.id AS $cast) = info_registry.local_key AND p.deleted_at IS NOT NULL)"
    );
};
