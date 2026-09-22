<?php

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
