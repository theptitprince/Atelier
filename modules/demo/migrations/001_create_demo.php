<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Tables du module de démonstration.
 *
 *  - demo_item   : articles fictifs (jeu de données partagé « demo.item »)
 *  - demo_secret : notes privées (jeu « demo.secret », jamais exposé)
 *
 * Les types passent par les méthodes de dialecte de Database afin que la migration
 * fonctionne à l'identique sous SQLite et MariaDB.
 */
return static function (Database $db): void {
    $db->execute(sprintf(
        'CREATE TABLE demo_item (
            id %s,
            name %s NOT NULL,
            category %s NOT NULL,
            quantity %s NOT NULL DEFAULT 0,
            price %s NOT NULL DEFAULT 0,
            active %s NOT NULL DEFAULT 1,
            created_at %s NOT NULL
        )%s',
        $db->primaryKey(),
        $db->varchar(120),
        $db->varchar(40),
        $db->integer(),
        $db->integer(),
        $db->boolean(),
        $db->datetime(),
        $db->tableOptions()
    ));
    $db->execute('CREATE INDEX idx_demo_item_category ON demo_item (category)');
    $db->execute('CREATE INDEX idx_demo_item_active ON demo_item (active)');

    $db->execute(sprintf(
        'CREATE TABLE demo_secret (
            id %s,
            note %s NOT NULL
        )%s',
        $db->primaryKey(),
        $db->text(),
        $db->tableOptions()
    ));
};
