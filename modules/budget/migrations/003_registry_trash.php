<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Budget 1.2.0 : rattrapage de la corbeille dans le registre commun (colonne info_registry.trashed_at,
 * migration core 006).
 *
 * Avant cette version, le module ne signalait pas ses mises en corbeille au registre : les opérations,
 * comptes et récurrences déjà en corbeille — et les opérations et récurrences masquées par un compte en
 * corbeille — restaient listés sous leurs tags et parmi les éléments liés, avec un lien menant à une
 * erreur 404. La date posée est celle du retrait (celle de l'élément, sinon celle de son compte).
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

    $mark('budget.account', 'FROM budget_account a', 'a.id', 'a.deleted_at');
    $mark('budget.transaction', 'FROM budget_transaction t INNER JOIN budget_account a ON a.id = t.account_id', 't.id', 'COALESCE(t.deleted_at, a.deleted_at)');
    $mark('budget.recurring', 'FROM budget_recurring r INNER JOIN budget_account a ON a.id = r.account_id', 'r.id', 'COALESCE(r.deleted_at, a.deleted_at)');
};
