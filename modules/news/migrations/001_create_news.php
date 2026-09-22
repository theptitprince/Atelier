<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Actualités : catégories de flux, centres d'intérêt (mots-clés), flux suivis, entrées récupérées,
 * correspondances entrée/centre d'intérêt et marques de lecture par utilisateur.
 * Les entrées archivées (is_archived = 1) échappent à la purge de rétention.
 */
return static function (Database $db): void {
    $pk = $db->primaryKey();
    $int = $db->integer();
    $bool = $db->boolean();
    $vc = $db->varchar();
    $vc64 = $db->varchar(64);
    $vc500 = $db->varchar(500);
    $text = $db->text();
    $dt = $db->datetime();
    $opt = $db->tableOptions();

    $db->execute("CREATE TABLE news_category (
        id $pk,
        name $vc NOT NULL,
        slug $vc64 NOT NULL,
        color $vc64 NULL,
        position $int NOT NULL DEFAULT 100,
        created_at $dt NOT NULL,
        UNIQUE (slug)
    )$opt");

    $db->execute("CREATE TABLE news_interest (
        id $pk,
        name $vc NOT NULL,
        keywords $text NOT NULL,
        color $vc64 NULL,
        position $int NOT NULL DEFAULT 100,
        created_at $dt NOT NULL
    )$opt");

    $db->execute("CREATE TABLE news_feed (
        id $pk,
        title $vc NOT NULL,
        url $vc500 NOT NULL,
        site_url $vc500 NULL,
        description $text NULL,
        category_id $int NULL,
        format $vc64 NULL,
        refresh_minutes $int NOT NULL DEFAULT 60,
        retention_days $int NOT NULL DEFAULT 30,
        is_active $bool NOT NULL DEFAULT 1,
        last_fetched_at $dt NULL,
        last_success_at $dt NULL,
        last_status $vc64 NOT NULL DEFAULT 'never',
        last_error $text NULL,
        etag $vc500 NULL,
        last_modified $vc NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        UNIQUE (url),
        FOREIGN KEY (category_id) REFERENCES news_category(id) ON DELETE SET NULL
    )$opt");

    $db->execute("CREATE TABLE news_item (
        id $pk,
        feed_id $int NOT NULL,
        guid $vc500 NOT NULL,
        url $vc500 NULL,
        title $vc500 NOT NULL,
        summary $text NULL,
        author $vc NULL,
        image_url $vc500 NULL,
        published_at $dt NULL,
        fetched_at $dt NOT NULL,
        is_archived $bool NOT NULL DEFAULT 0,
        archived_at $dt NULL,
        archived_by $int NULL,
        archive_note $text NULL,
        UNIQUE (feed_id, guid),
        FOREIGN KEY (feed_id) REFERENCES news_feed(id) ON DELETE CASCADE
    )$opt");
    $db->execute('CREATE INDEX idx_news_item_published ON news_item (published_at)');
    $db->execute('CREATE INDEX idx_news_item_archived ON news_item (is_archived)');

    $db->execute("CREATE TABLE news_item_interest (
        item_id $int NOT NULL,
        interest_id $int NOT NULL,
        PRIMARY KEY (item_id, interest_id),
        FOREIGN KEY (item_id) REFERENCES news_item(id) ON DELETE CASCADE,
        FOREIGN KEY (interest_id) REFERENCES news_interest(id) ON DELETE CASCADE
    )$opt");

    $db->execute("CREATE TABLE news_read (
        user_id $int NOT NULL,
        item_id $int NOT NULL,
        read_at $dt NOT NULL,
        PRIMARY KEY (user_id, item_id),
        FOREIGN KEY (item_id) REFERENCES news_item(id) ON DELETE CASCADE
    )$opt");
};
