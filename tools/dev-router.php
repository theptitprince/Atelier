<?php

declare(strict_types=1);

/**
 * Routeur pour le serveur de développement intégré à PHP :
 *   php -S 127.0.0.1:8000 -t public tools/dev-router.php
 *
 * Les fichiers statiques existants dans public/ sont servis directement (retour false),
 * tout le reste passe par public/index.php. Reproduit le comportement du .htaccess.
 */

$publicDir = realpath(dirname(__DIR__) . '/public');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = $publicDir . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($path));

if ($path !== '/' && is_file($file) && !str_ends_with(strtolower($file), '.php')) {
    $real = realpath($file);
    if ($real !== false && str_starts_with($real, $publicDir)) {
        // Types que le serveur intégré ne connaît pas toujours.
        $types = ['svg' => 'image/svg+xml', 'webp' => 'image/webp', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'mjs' => 'application/javascript', 'json' => 'application/json'];
        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if (isset($types[$extension])) {
            header('Content-Type: ' . $types[$extension]);
            header('Cache-Control: public, max-age=3600');
            readfile($real);
            return true;
        }
        return false;
    }
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicDir . DIRECTORY_SEPARATOR . 'index.php';
require $publicDir . '/index.php';
