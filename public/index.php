<?php

/*
 * Atelier, par ETDEL — https://github.com/theptitprince/Atelier
 * Copyright (C) 2026 ETDEL — licence GPL-3.0-or-later
 * Conditions additionnelles (article 7) : voir le fichier NOTICE.
 */

declare(strict_types=1);

/**
 * Point d'entrée public unique d'Atelier.
 *
 * Toute requête (hors fichiers statiques de ce répertoire) passe par ici :
 * authentification, routage, contrôles d'accès, protection CSRF et gestion des erreurs.
 */

require dirname(__DIR__) . '/src/Kernel/Autoloader.php';
require dirname(__DIR__) . '/src/Kernel/Application.php';

\Atelier\Kernel\Application::boot(dirname(__DIR__))->run();
