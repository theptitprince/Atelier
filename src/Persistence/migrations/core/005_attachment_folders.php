<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Dossiers virtuels des pièces jointes : arborescence stockée en base (les fichiers restent
 * physiquement rangés par date sous leur nom interne chiffré). Un fichier appartient à un
 * dossier au plus ; la racine correspond à folder_id NULL.
 */
return static function (Database $db): void {
    $db->execute(sprintf(
        'CREATE TABLE attachment_folders (
            id %s,
            parent_id %s NULL,
            name %s NOT NULL,
            path %s NOT NULL,
            created_by %s NULL,
            created_at %s NOT NULL,
            updated_at %s NOT NULL
        )%s',
        $db->primaryKey(),
        $db->integer(),
        $db->varchar(120),
        $db->varchar(1000),
        $db->integer(),
        $db->datetime(),
        $db->datetime(),
        $db->tableOptions()
    ));
    $db->execute('CREATE INDEX idx_attachment_folders_parent ON attachment_folders (parent_id)');
    $db->execute('CREATE UNIQUE INDEX idx_attachment_folders_path ON attachment_folders (path)');
    $db->execute('ALTER TABLE attachments ADD COLUMN folder_id ' . $db->integer() . ' NULL');
    $db->execute('CREATE INDEX idx_attachments_folder ON attachments (folder_id)');
};
