<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Pièces jointes : chiffrement au repos (algorithme et identifiant de clé), description,
 * compteur et date de dernier téléchargement.
 */
return static function (Database $db): void {
    $vc64 = $db->varchar(64);
    $text = $db->text();
    $int = $db->integer();
    $dt = $db->datetime();

    $db->execute("ALTER TABLE attachments ADD COLUMN cipher $vc64 NULL");
    $db->execute("ALTER TABLE attachments ADD COLUMN key_id $vc64 NULL");
    $db->execute("ALTER TABLE attachments ADD COLUMN description $text NULL");
    $db->execute("ALTER TABLE attachments ADD COLUMN downloads $int NOT NULL DEFAULT 0");
    $db->execute("ALTER TABLE attachments ADD COLUMN last_downloaded_at $dt NULL");
};
