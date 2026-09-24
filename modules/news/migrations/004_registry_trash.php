<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Actualités 1.2.4 : rattrapage de l'état « en corbeille » du registre commun.
 *
 * Avant cette version, la mise à la corbeille d'un fait archivé ne prévenait pas le registre : les
 * faits déjà en corbeille restaient listés sous leurs tags et dans les éléments liés, avec un lien
 * en 404. Les flux (jeu news.feed) sont traités de même s'ils sont inscrits. Pas de cascade : les
 * faits archivés d'un flux en corbeille restent consultables. Rejouable : seules les entrées encore
 * non marquées sont touchées (local_key est une chaîne, d'où la conversion de l'identifiant).
 */
return static function (Database $db): void {
    $cast = $db->isSqlite() ? 'TEXT' : 'CHAR';
    foreach (['news.archive' => 'news_item', 'news.feed' => 'news_feed'] as $dataset => $table) {
        $db->execute(
            "UPDATE info_registry
             SET trashed_at = (SELECT t.deleted_at FROM $table t WHERE CAST(t.id AS $cast) = info_registry.local_key)
             WHERE dataset_code = :d AND trashed_at IS NULL
               AND EXISTS (SELECT 1 FROM $table t WHERE CAST(t.id AS $cast) = info_registry.local_key AND t.deleted_at IS NOT NULL)",
            ['d' => $dataset]
        );
    }
};
