<?php

declare(strict_types=1);

/**
 * Point d'entrée de secours à la racine du projet.
 *
 * En fonctionnement normal, cette page n'est jamais atteinte : soit le DocumentRoot pointe sur
 * public/, soit le .htaccess racine réécrit toutes les requêtes vers public/. Si elle s'affiche,
 * la réécriture n'est pas active : on explique quoi faire plutôt que de servir l'application
 * sans ses ressources statiques.
 */

http_response_code(503);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$rewrite = function_exists('apache_get_modules') ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'chargé' : 'absent') : 'inconnu (PHP-FPM ou serveur non Apache)';
$publicUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/public/';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atelier — configuration du serveur requise</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f6f8; color: #1f2933; margin: 0; padding: 3rem 1rem; }
        main { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #d9dee5; border-radius: 6px; padding: 2rem; }
        h1 { color: #2c3e50; margin-top: 0; }
        code { background: #f8fafc; padding: 0 .3em; border-radius: 3px; }
        pre { background: #f8fafc; border: 1px solid #d9dee5; padding: .75rem; overflow: auto; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
<main>
    <h1>Atelier n’est pas encore servi correctement</h1>
    <p>L’application s’exécute depuis le répertoire <code>public/</code>. Cette page apparaît parce que les requêtes n’y sont pas dirigées.</p>
    <h2>Solution 1 — DocumentRoot sur <code>public/</code> (recommandé)</h2>
    <p>Dans la configuration du serveur (VirtualHost), pointez le <code>DocumentRoot</code> sur le sous-répertoire <code>public</code> de ce projet.</p>
    <h2>Solution 2 — hébergement mutualisé (racine web imposée)</h2>
    <p>Le fichier <code>.htaccess</code> à la racine du projet réécrit tout vers <code>public/</code>. Il exige le module <code>mod_rewrite</code> et l’autorisation <code>AllowOverride All</code> (ou au moins <code>FileInfo</code>). Vérifiez que le fichier <code>.htaccess</code> a bien été transféré (fichier caché) et que l’hébergeur autorise la réécriture.</p>
    <p>Si le projet est dans un sous-répertoire du site, décommentez <code>RewriteBase /sous-repertoire/</code> dans <code>.htaccess</code> et définissez <code>app.base_url</code> dans <code>config/env.local.php</code>.</p>
    <p class="muted">Diagnostic : mod_rewrite <?= htmlspecialchars($rewrite, ENT_QUOTES, 'UTF-8') ?> · PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?> · <a href="<?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?>">essayer public/ directement</a> (les ressources statiques ne se chargeront pas sans réécriture).</p>
    <p class="muted">Documentation : <code>docs/installation.md</code>.</p>
</main>
</body>
</html>
