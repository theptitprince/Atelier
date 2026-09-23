<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Entretien 1.2.0 : suppression logique (corbeille) des tâches et des interventions.
 *
 * Les équipements avaient déjà leur colonne deleted_at ; les tâches (maintenance_job) et les
 * interventions (maintenance_log) la reçoivent à leur tour. Une ligne dont deleted_at n'est pas NULL
 * est en corbeille : exclue de toutes les listes, restaurable, purgée après trash.retention_days.
 */
return static function (Database $db): void {
    $dt = $db->datetime();

    if (!in_array('deleted_at', $db->columns('maintenance_job'), true)) {
        $db->execute("ALTER TABLE maintenance_job ADD COLUMN deleted_at $dt NULL");
        $db->execute('CREATE INDEX idx_maintenance_job_deleted ON maintenance_job (deleted_at)');
    }
    if (!in_array('deleted_at', $db->columns('maintenance_log'), true)) {
        $db->execute("ALTER TABLE maintenance_log ADD COLUMN deleted_at $dt NULL");
        $db->execute('CREATE INDEX idx_maintenance_log_deleted ON maintenance_log (deleted_at)');
    }
};
