<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Table des notes personnelles. La suppression est logique (deleted_at = corbeille) ;
 * la purge physique est réalisée par le hook purge() du module (rétention configurable).
 */
return static function (Database $db): void {
    $pk = $db->primaryKey();
    $int = $db->integer();
    $vc = $db->varchar(200);
    $text = $db->text();
    $dt = $db->datetime();
    $opt = $db->tableOptions();

    $db->execute("CREATE TABLE notes_note (
        id $pk,
        owner_id $int NOT NULL,
        title $vc NOT NULL,
        content $text NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        deleted_at $dt NULL
    )$opt");
    $db->execute('CREATE INDEX idx_notes_note_owner ON notes_note (owner_id)');
    $db->execute('CREATE INDEX idx_notes_note_deleted ON notes_note (deleted_at)');
};
