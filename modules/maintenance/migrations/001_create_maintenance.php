<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Tables du module Entretien (GMAO domestique).
 *
 *  - maintenance_asset : équipements (véhicule, chaudière…), compteur éventuel, corbeille logique
 *  - maintenance_job   : tâches d'entretien planifiées (périodicité, échéance, rappel) et pannes ;
 *                        fiche d'intervention (descriptif, pièces, contacts, outillage)
 *  - maintenance_log   : interventions réalisées (historique, coûts)
 *
 * Les dates calendaires (échéances, réalisations, acquisition) sont stockées en texte AAAA-MM-JJ :
 * ce sont des jours, pas des instants, et elles ne dépendent d'aucun fuseau horaire.
 */
return static function (Database $db): void {
    $pk = $db->primaryKey();
    $int = $db->integer();
    $text = $db->text();
    $dt = $db->datetime();
    $day = $db->varchar(10);
    $opt = $db->tableOptions();

    $db->execute(sprintf(
        'CREATE TABLE maintenance_asset (
            id %s,
            name %s NOT NULL,
            category %s NOT NULL,
            brand %s NULL,
            model %s NULL,
            identifier %s NULL,
            acquired_at %s NULL,
            meter_unit %s NULL,
            meter_value %s NULL,
            meter_updated_at %s NULL,
            location %s NULL,
            notes %s NULL,
            created_by %s NULL,
            created_at %s NOT NULL,
            updated_at %s NOT NULL,
            deleted_at %s NULL
        )%s',
        $pk,
        $db->varchar(150),
        $db->varchar(30),
        $db->varchar(100),
        $db->varchar(100),
        $db->varchar(100),
        $day,
        $db->varchar(10),
        $int,
        $dt,
        $db->varchar(150),
        $text,
        $int,
        $dt,
        $dt,
        $dt,
        $opt
    ));
    $db->execute('CREATE INDEX idx_maintenance_asset_name ON maintenance_asset (name)');
    $db->execute('CREATE INDEX idx_maintenance_asset_category ON maintenance_asset (category)');
    $db->execute('CREATE INDEX idx_maintenance_asset_deleted ON maintenance_asset (deleted_at)');

    $db->execute(sprintf(
        'CREATE TABLE maintenance_job (
            id %s,
            asset_id %s NOT NULL,
            title %s NOT NULL,
            kind %s NOT NULL,
            priority %s NOT NULL,
            status %s NOT NULL,
            description %s NULL,
            parts %s NULL,
            contacts %s NULL,
            tools %s NULL,
            estimated_minutes %s NULL,
            estimated_cost %s NULL,
            interval_days %s NULL,
            interval_meter %s NULL,
            next_due_at %s NULL,
            next_due_meter %s NULL,
            lead_days %s NOT NULL DEFAULT 14,
            lead_meter %s NULL,
            last_done_at %s NULL,
            last_done_meter %s NULL,
            closed_at %s NULL,
            created_by %s NULL,
            created_at %s NOT NULL,
            updated_at %s NOT NULL
        )%s',
        $pk,
        $int,
        $db->varchar(150),
        $db->varchar(20),
        $db->varchar(10),
        $db->varchar(10),
        $text,
        $text,
        $text,
        $text,
        $int,
        $int,
        $int,
        $int,
        $day,
        $int,
        $int,
        $int,
        $day,
        $int,
        $dt,
        $int,
        $dt,
        $dt,
        $opt
    ));
    $db->execute('CREATE INDEX idx_maintenance_job_asset ON maintenance_job (asset_id)');
    $db->execute('CREATE INDEX idx_maintenance_job_status ON maintenance_job (status, kind)');
    $db->execute('CREATE INDEX idx_maintenance_job_due ON maintenance_job (next_due_at)');

    $db->execute(sprintf(
        'CREATE TABLE maintenance_log (
            id %s,
            asset_id %s NOT NULL,
            job_id %s NULL,
            done_at %s NOT NULL,
            meter_value %s NULL,
            title %s NOT NULL,
            notes %s NULL,
            cost %s NULL,
            performed_by %s NULL,
            created_by %s NULL,
            created_at %s NOT NULL,
            updated_at %s NOT NULL
        )%s',
        $pk,
        $int,
        $int,
        $day,
        $int,
        $db->varchar(150),
        $text,
        $int,
        $db->varchar(150),
        $int,
        $dt,
        $dt,
        $opt
    ));
    $db->execute('CREATE INDEX idx_maintenance_log_asset ON maintenance_log (asset_id, done_at)');
    $db->execute('CREATE INDEX idx_maintenance_log_job ON maintenance_log (job_id)');
};
