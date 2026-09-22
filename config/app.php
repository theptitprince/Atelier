<?php

declare(strict_types=1);

/**
 * Configuration par défaut d'Atelier.
 *
 * Ce fichier est versionné et ne doit contenir aucune valeur sensible.
 * Les valeurs dépendant de l'environnement sont surchargées par
 * config/env.local.php (non versionné) puis par les variables
 * d'environnement ATELIER_* (voir Atelier\Kernel\Config).
 */
return [
    'app' => [
        'name' => 'Atelier',
        'id' => 'atelier',
        'version' => '0.1.0',
        'env' => 'dev',            // dev | test | prod
        'debug' => true,
        'timezone' => 'Europe/Paris',
        'locale' => 'fr_FR',
        'base_url' => '',          // préfixe d'URL si l'application n'est pas à la racine du site (ex. "/atelier")
        'min_width' => 1280,
    ],

    'database' => [
        'driver' => 'sqlite',      // sqlite | mysql (MariaDB)
        'sqlite' => [
            'path' => '%var%/data/atelier.sqlite',
            'wal' => true,
        ],
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'atelier',
            'user' => 'atelier',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
    ],

    'session' => [
        'name' => 'ATELIER_SESSION',
        'idle_timeout' => 3600,        // 60 minutes d'inactivité
        'absolute_timeout' => 43200,   // 12 heures après connexion
        'cookie_secure' => null,       // null = automatique selon HTTPS ; forcer true en production
        'cookie_samesite' => 'Lax',
        'save_path' => '%var%/sessions',
    ],

    'security' => [
        'password_min_length' => 12,
        'lockout_attempts' => 5,
        'lockout_duration' => 900,     // 15 minutes
        'progressive_delay_base' => 1, // secondes ajoutées par échec supplémentaire
        'csrf_token_name' => '_token',
        'csrf_header' => 'X-CSRF-Token',
        'headers' => [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ],
        'csp' => "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
    ],

    'paths' => [
        'var' => '%root%/var',
        'modules' => '%root%/modules',
        'attachments' => '%var%/attachments',
        'logs' => '%var%/logs',
        'cache' => '%var%/cache',
        'backups' => '%var%/backups',
        'tmp' => '%var%/tmp',
        'modules_config' => '%var%/config/modules.json',
    ],

    'logging' => [
        'technical_retention_days' => 30,
        'activity_retention_months' => 12,
        'level' => 'debug',            // debug | info | warning | error
    ],

    'attachments' => [
        'max_file_size' => 20 * 1024 * 1024,        // 20 Mo par fichier
        'max_per_user' => 500 * 1024 * 1024,        // 500 Mo par utilisateur
        'max_total' => 5 * 1024 * 1024 * 1024,      // 5 Go au total
        'allowed_mime' => [
            'application/pdf',
            'image/jpeg', 'image/png', 'image/webp',
            'text/plain', 'text/csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
        ],
        'forbidden_extensions' => ['exe', 'bat', 'cmd', 'com', 'msi', 'ps1', 'sh', 'php', 'phar', 'js', 'vbs', 'jar', 'zip', 'rar', '7z', 'tar', 'gz'],
    ],

    'navigation' => [
        // Groupes d'affichage de la colonne de gauche : libellé et ordre par défaut.
        // Un manifeste peut référencer un groupe inconnu : il sera créé avec son identifiant comme libellé.
        'groups' => [
            'general' => ['label' => 'Général', 'order' => 10],
            'tools' => ['label' => 'Outils', 'order' => 20],
            'administration' => ['label' => 'Administration', 'order' => 90],
        ],
    ],


    'trash' => [
        'retention_days' => 30,
    ],
];
