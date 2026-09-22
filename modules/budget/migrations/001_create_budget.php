<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Tables du module Budget (comptabilité domestique).
 *
 *  - budget_account     : comptes (courant, épargne, espèces) et solde initial
 *  - budget_category    : catégories hiérarchiques de dépenses et de recettes
 *  - budget_envelope    : budgets (enveloppes) par catégorie, mensuels ou annuels, datés
 *  - budget_transaction : opérations (montant signé en centimes), pointage, origine
 *  - budget_recurring   : opérations récurrentes (prévisionnel)
 *  - budget_goal        : objectifs d'épargne
 *  - budget_saving      : registre des économies réalisées
 *
 * Les montants sont des entiers en centimes ; les dates calendaires sont stockées en texte AAAA-MM-JJ.
 */
return static function (Database $db): void {
    $pk = $db->primaryKey();
    $int = $db->integer();
    $bool = $db->boolean();
    $text = $db->text();
    $dt = $db->datetime();
    $day = $db->varchar(10);
    $opt = $db->tableOptions();

    $db->execute("CREATE TABLE budget_account (
        id $pk,
        name {$db->varchar(100)} NOT NULL,
        kind {$db->varchar(20)} NOT NULL,
        initial_balance $int NOT NULL DEFAULT 0,
        opened_at $day NULL,
        notes $text NULL,
        archived $bool NOT NULL DEFAULT 0,
        sort_order $int NOT NULL DEFAULT 0,
        created_by $int NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL
    )$opt");

    $db->execute("CREATE TABLE budget_category (
        id $pk,
        parent_id $int NULL,
        name {$db->varchar(100)} NOT NULL,
        kind {$db->varchar(10)} NOT NULL,
        archived $bool NOT NULL DEFAULT 0,
        sort_order $int NOT NULL DEFAULT 0,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL
    )$opt");
    $db->execute('CREATE INDEX idx_budget_category_parent ON budget_category (parent_id)');

    $db->execute("CREATE TABLE budget_envelope (
        id $pk,
        category_id $int NOT NULL,
        period {$db->varchar(10)} NOT NULL,
        amount $int NOT NULL,
        valid_from $day NOT NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        UNIQUE (category_id, valid_from)
    )$opt");

    $db->execute("CREATE TABLE budget_transaction (
        id $pk,
        account_id $int NOT NULL,
        category_id $int NULL,
        done_at $day NOT NULL,
        amount $int NOT NULL,
        label {$db->varchar(200)} NOT NULL,
        payee {$db->varchar(150)} NULL,
        notes $text NULL,
        cleared $bool NOT NULL DEFAULT 0,
        source {$db->varchar(20)} NOT NULL DEFAULT 'manual',
        source_ref {$db->varchar(100)} NULL,
        recurring_id $int NULL,
        import_hash {$db->varchar(40)} NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL
    )$opt");
    $db->execute('CREATE INDEX idx_budget_transaction_account ON budget_transaction (account_id, done_at)');
    $db->execute('CREATE INDEX idx_budget_transaction_category ON budget_transaction (category_id, done_at)');
    $db->execute('CREATE INDEX idx_budget_transaction_source ON budget_transaction (source_ref)');
    $db->execute('CREATE INDEX idx_budget_transaction_hash ON budget_transaction (import_hash)');

    $db->execute("CREATE TABLE budget_recurring (
        id $pk,
        account_id $int NOT NULL,
        category_id $int NULL,
        label {$db->varchar(200)} NOT NULL,
        payee {$db->varchar(150)} NULL,
        amount $int NOT NULL,
        interval_unit {$db->varchar(10)} NOT NULL,
        interval_count $int NOT NULL DEFAULT 1,
        next_at $day NOT NULL,
        ends_at $day NULL,
        active $bool NOT NULL DEFAULT 1,
        notes $text NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL
    )$opt");
    $db->execute('CREATE INDEX idx_budget_recurring_next ON budget_recurring (active, next_at)');

    $db->execute("CREATE TABLE budget_goal (
        id $pk,
        name {$db->varchar(150)} NOT NULL,
        target $int NOT NULL,
        current $int NOT NULL DEFAULT 0,
        account_id $int NULL,
        due_at $day NULL,
        notes $text NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL
    )$opt");

    $db->execute("CREATE TABLE budget_saving (
        id $pk,
        label {$db->varchar(200)} NOT NULL,
        kind {$db->varchar(10)} NOT NULL,
        amount $int NOT NULL,
        effective_from $day NOT NULL,
        effective_to $day NULL,
        category_id $int NULL,
        notes $text NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL
    )$opt");
};
