<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Points GPS : coordonnées WGS 84 en degrés décimaux. La suppression est logique (corbeille) ;
 * la purge physique est réalisée par le hook purge() du module (rétention configurable).
 */
return static function (Database $db): void {
    $pk = $db->primaryKey();
    $int = $db->integer();
    $double = $db->double();
    $vc32 = $db->varchar(32);
    $vc = $db->varchar(200);
    $vc300 = $db->varchar(300);
    $text = $db->text();
    $dt = $db->datetime();
    $opt = $db->tableOptions();

    $db->execute("CREATE TABLE geo_point (
        id $pk,
        code $vc32 NULL,
        name $vc NOT NULL,
        latitude $double NOT NULL,
        longitude $double NOT NULL,
        altitude $double NULL,
        address $vc300 NULL,
        description $text NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        deleted_at $dt NULL,
        UNIQUE (code)
    )$opt");
    $db->execute('CREATE INDEX idx_geo_point_name ON geo_point (name)');
    $db->execute('CREATE INDEX idx_geo_point_latlon ON geo_point (latitude, longitude)');
    $db->execute('CREATE INDEX idx_geo_point_deleted ON geo_point (deleted_at)');
};
