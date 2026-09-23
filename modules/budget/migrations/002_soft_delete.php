<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Budget 1.1.0 : suppression logique (corbeille globale) des opérations, comptes, récurrences,
 * objectifs et économies. deleted_at NULL = ligne vivante ; deleted_by = utilisateur ayant supprimé.
 * Les catégories et les budgets (enveloppes) restent en suppression physique, refusée tant que
 * des données s'y rattachent.
 */
return static function (Database $db): void {
    $dt = $db->datetime();
    $int = $db->integer();
    foreach (['budget_transaction', 'budget_account', 'budget_recurring', 'budget_goal', 'budget_saving'] as $table) {
        $db->execute("ALTER TABLE $table ADD COLUMN deleted_at $dt NULL");
        $db->execute("ALTER TABLE $table ADD COLUMN deleted_by $int NULL");
        $db->execute("CREATE INDEX idx_{$table}_deleted ON $table (deleted_at)");
    }
};
