<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Registre commun : état « en corbeille ».
 *
 * Les modules gèrent leur suppression logique dans leurs propres tables, mais le registre n'en
 * savait rien : un élément en corbeille restait listé sous ses tags, dans l'Explorateur et dans
 * les éléments liés, avec un lien menant à une erreur 404. Les modules signalent désormais la
 * mise en corbeille et la restauration ; les vues transversales écartent ces informations.
 */
return static function (Database $db): void {
    $db->execute('ALTER TABLE info_registry ADD COLUMN trashed_at ' . $db->datetime() . ' NULL');
    $db->execute('CREATE INDEX idx_info_registry_trashed ON info_registry (trashed_at)');
};
