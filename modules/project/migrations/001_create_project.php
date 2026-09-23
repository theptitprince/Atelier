<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Projets : projets (suppression logique), tâches ordonnées et journal de bord.
 * Les méthodes de dialecte assurent la compatibilité SQLite / MariaDB.
 */
return static function (Database $db): void {
    $pk = $db->primaryKey();
    $int = $db->integer();
    $bigint = $db->bigint();
    $bool = $db->boolean();
    $vc = $db->varchar();
    $vc20 = $db->varchar(20);
    $vc120 = $db->varchar(120);
    $vc500 = $db->varchar(500);
    $text = $db->text();
    $dt = $db->datetime();
    $opt = $db->tableOptions();

    $db->execute("CREATE TABLE project_project (
        id $pk,
        title $vc NOT NULL,
        slug $vc120 NOT NULL,
        status $vc20 NOT NULL DEFAULT 'idea',
        summary $vc500 NULL,
        description $text NULL,
        start_date $vc20 NULL,
        due_date $vc20 NULL,
        budget_estimate $bigint NULL,
        priority $int NOT NULL DEFAULT 2,
        owner_id $int NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        deleted_at $dt NULL,
        UNIQUE (slug)
    )$opt");
    $db->execute('CREATE INDEX idx_project_project_status ON project_project (status)');
    $db->execute('CREATE INDEX idx_project_project_deleted ON project_project (deleted_at)');
    $db->execute('CREATE INDEX idx_project_project_due ON project_project (due_date)');

    $db->execute("CREATE TABLE project_task (
        id $pk,
        project_id $int NOT NULL,
        title $vc NOT NULL,
        done $bool NOT NULL DEFAULT 0,
        due_date $vc20 NULL,
        position $int NOT NULL DEFAULT 0,
        created_at $dt NOT NULL,
        deleted_at $dt NULL,
        FOREIGN KEY (project_id) REFERENCES project_project(id) ON DELETE CASCADE
    )$opt");
    $db->execute('CREATE INDEX idx_project_task_project ON project_task (project_id, position)');

    $db->execute("CREATE TABLE project_note (
        id $pk,
        project_id $int NOT NULL,
        content $text NOT NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        FOREIGN KEY (project_id) REFERENCES project_project(id) ON DELETE CASCADE
    )$opt");
    $db->execute('CREATE INDEX idx_project_note_project ON project_note (project_id, created_at)');
};
