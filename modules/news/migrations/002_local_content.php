<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Copie locale des articles : texte extrait de la page source (conservé même si le site disparaît),
 * état et date du téléchargement ; option par flux.
 */
return static function (Database $db): void {
    $text = $db->text();
    $vc = $db->varchar(32);
    $dt = $db->datetime();
    $bool = $db->boolean();

    $db->execute("ALTER TABLE news_item ADD COLUMN content $text NULL");
    $db->execute("ALTER TABLE news_item ADD COLUMN content_status $vc NULL");
    $db->execute("ALTER TABLE news_item ADD COLUMN content_fetched_at $dt NULL");
    $db->execute("ALTER TABLE news_item ADD COLUMN content_error $text NULL");
    $db->execute("ALTER TABLE news_feed ADD COLUMN fetch_content $bool NOT NULL DEFAULT 1");
};
