<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Actualités 1.2.0 (migration 003) : suppression logique (corbeille globale) des flux suivis et des faits archivés.
 * deleted_at NULL = ligne vivante ; deleted_by = utilisateur ayant supprimé. Les entrées non archivées
 * (cache des flux) restent régies par la rétention de leur flux, pas par la corbeille.
 */
return static function (Database $db): void {
    $dt = $db->datetime();
    $int = $db->integer();
    foreach (['news_feed', 'news_item'] as $table) {
        $db->execute("ALTER TABLE $table ADD COLUMN deleted_at $dt NULL");
        $db->execute("ALTER TABLE $table ADD COLUMN deleted_by $int NULL");
        $db->execute("CREATE INDEX idx_{$table}_deleted ON $table (deleted_at)");
    }
};
