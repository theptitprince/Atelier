<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Pages wiki : pages (contenu courant), révisions (historique) et liens internes (rétroliens).
 * La suppression est logique (corbeille) ; la purge physique retire la page, ses révisions et ses liens.
 */
return static function (Database $db): void {
    $pk = $db->primaryKey();
    $int = $db->integer();
    $vc = $db->varchar();
    $vc120 = $db->varchar(120);
    $text = $db->text();
    $dt = $db->datetime();
    $opt = $db->tableOptions();

    $db->execute("CREATE TABLE wiki_page (
        id $pk,
        slug $vc120 NOT NULL,
        title $vc NOT NULL,
        content $text NULL,
        revision $int NOT NULL DEFAULT 1,
        created_by $int NULL,
        updated_by $int NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        deleted_at $dt NULL,
        UNIQUE (slug)
    )$opt");
    $db->execute('CREATE INDEX idx_wiki_page_title ON wiki_page (title)');
    $db->execute('CREATE INDEX idx_wiki_page_deleted ON wiki_page (deleted_at)');

    $db->execute("CREATE TABLE wiki_revision (
        id $pk,
        page_id $int NOT NULL,
        revision $int NOT NULL,
        title $vc NOT NULL,
        content $text NULL,
        saved_by $int NULL,
        saved_at $dt NOT NULL,
        UNIQUE (page_id, revision),
        FOREIGN KEY (page_id) REFERENCES wiki_page(id) ON DELETE CASCADE
    )$opt");

    $db->execute("CREATE TABLE wiki_link (
        from_page_id $int NOT NULL,
        to_slug $vc120 NOT NULL,
        PRIMARY KEY (from_page_id, to_slug),
        FOREIGN KEY (from_page_id) REFERENCES wiki_page(id) ON DELETE CASCADE
    )$opt");
    $db->execute('CREATE INDEX idx_wiki_link_to ON wiki_link (to_slug)');
};
