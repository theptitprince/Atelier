<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Entretien 1.3.0 : rattrapage de la corbeille dans le registre commun (colonne info_registry.trashed_at,
 * migration core 006).
 *
 * Avant cette version, le module ne signalait pas ses mises en corbeille au registre : les équipements,
 * tâches et interventions déjà en corbeille — et les tâches et interventions masquées par un équipement
 * en corbeille — restaient listés sous leurs tags et parmi les éléments liés, avec un lien menant à une
 * erreur 404. La date posée est celle du retrait (celle de l'élément, sinon celle de son équipement).
 *
 * Rejouable : seules les entrées encore sans trashed_at sont touchées.
 */
return static function (Database $db): void {
    // local_key est une chaîne : l'identifiant entier est converti pour la comparaison.
    $cast = $db->isSqlite() ? 'TEXT' : 'CHAR';
    $mark = static function (string $dataset, string $from, string $key, string $trashedAt) use ($db, $cast): void {
        $db->execute(
            "UPDATE info_registry SET trashed_at = (SELECT $trashedAt $from WHERE CAST($key AS $cast) = info_registry.local_key)
             WHERE dataset_code = :d AND trashed_at IS NULL
               AND local_key IN (SELECT CAST($key AS $cast) $from WHERE $trashedAt IS NOT NULL)",
            ['d' => $dataset]
        );
    };

    $mark('maintenance.asset', 'FROM maintenance_asset a', 'a.id', 'a.deleted_at');
    $mark('maintenance.job', 'FROM maintenance_job j INNER JOIN maintenance_asset a ON a.id = j.asset_id', 'j.id', 'COALESCE(j.deleted_at, a.deleted_at)');
    $mark('maintenance.log', 'FROM maintenance_log l INNER JOIN maintenance_asset a ON a.id = l.asset_id', 'l.id', 'COALESCE(l.deleted_at, a.deleted_at)');
};
