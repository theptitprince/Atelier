<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Démonstration 1.1.0 : suppression logique des articles (corbeille).
 *
 * Règle du projet : un utilisateur ne supprime jamais une donnée métier physiquement ; l'élément
 * part en corbeille, reste restaurable pendant trash.retention_days jours, puis la rétention le
 * purge. Le module de référence doit montrer ce motif, d'où ces deux colonnes :
 *  - deleted_at : NULL = article vivant ; sinon date UTC de mise en corbeille (point de départ
 *    du délai de rétention) ;
 *  - deleted_by : auteur de la suppression, affiché dans les corbeilles (NULL si inconnu).
 *
 * L'index sert les deux requêtes fréquentes : « vivants » (toutes les listes) et « expirés »
 * (hook purge()).
 */
return static function (Database $db): void {
    $db->execute('ALTER TABLE demo_item ADD COLUMN deleted_at ' . $db->datetime() . ' NULL');
    $db->execute('ALTER TABLE demo_item ADD COLUMN deleted_by ' . $db->integer() . ' NULL');
    $db->execute('CREATE INDEX idx_demo_item_deleted ON demo_item (deleted_at)');
};
