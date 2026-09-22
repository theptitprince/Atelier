<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Journal d'activité enrichi : catégorie (security, data, admin, technical, debug),
 * identifiant de requête pour corréler les entrées d'un même traitement, durée en millisecondes.
 */
return static function (Database $db): void {
    $vc = $db->varchar(32);
    $vc64 = $db->varchar(64);
    $int = $db->integer();
    $db->execute("ALTER TABLE activity_log ADD COLUMN category $vc NOT NULL DEFAULT 'data'");
    $db->execute("ALTER TABLE activity_log ADD COLUMN request_id $vc64 NULL");
    $db->execute("ALTER TABLE activity_log ADD COLUMN duration_ms $int NULL");
    $db->execute('CREATE INDEX idx_activity_category ON activity_log (category)');
    $db->execute('CREATE INDEX idx_activity_request ON activity_log (request_id)');
};
