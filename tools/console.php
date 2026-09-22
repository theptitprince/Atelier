<?php

declare(strict_types=1);

/**
 * Console d'administration locale d'Atelier (jamais exposée par le serveur Web).
 *   php tools/console.php help
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Console réservée à la ligne de commande.');
}

$root = dirname(__DIR__);
require $root . '/src/Kernel/Autoloader.php';
require $root . '/src/Kernel/Application.php';

$app = \Atelier\Kernel\Application::boot($root);
exit((new \Atelier\Kernel\Console($app, $root))->run($argv));
