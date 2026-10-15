<?php

/*
 * Atelier, par ETDEL — https://github.com/theptitprince/Atelier
 * Copyright (C) 2026 ETDEL — licence GPL-3.0-or-later
 * Conditions additionnelles (article 7) : voir le fichier NOTICE.
 */

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Pièces jointes : nom d'affichage libre (label), distinct du nom de fichier d'origine
 * conservé pour le téléchargement.
 */
return static function (Database $db): void {
    $db->execute('ALTER TABLE attachments ADD COLUMN label ' . $db->varchar(200) . ' NULL');
};
