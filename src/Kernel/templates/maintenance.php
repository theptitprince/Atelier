<?php
/**
 * État de maintenance : stockage indisponible. Les modules découverts sont listés verrouillés,
 * aucun contenu protégé n'est chargé.
 * Variables : $appName, $baseUrl, $errorId, $tree, $debug, $e.
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <title><?= $e($appName) ?> — Maintenance</title>
    <link rel="icon" href="<?= $e($baseUrl) ?>/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/atelier.css">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/login.css">
</head>
<body class="login-body">
<?php require __DIR__ . '/icons.php'; ?>
<main class="login">
    <div class="login__card error-card">
        <div class="login__brand">
            <svg class="icon icon--xl" aria-hidden="true"><use href="#i-tool"></use></svg>
            <h1 class="login__title">Maintenance</h1>
        </div>
        <div class="alert alert--warning" role="alert">
            <svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg>
            <div>Le stockage applicatif est indisponible. Les utilisateurs et les droits ne peuvent pas être vérifiés : l’accès est refusé par défaut. La page se rafraîchira automatiquement.</div>
        </div>
        <?php if ($errorId): ?>
            <p class="error-card__reference">Référence d’incident : <code><?= $e($errorId) ?></code></p>
        <?php endif; ?>
        <?php if ($tree): ?>
            <p class="text-muted">Modules installés (verrouillés) :</p>
            <ul class="maintenance-modules">
                <?php foreach ($tree as $group): ?>
                    <?php foreach ($group['modules'] as $module): ?>
                        <li>
                            <svg class="icon icon--sm" aria-hidden="true"><use href="#i-lock"></use></svg>
                            <?= $e($module['name']) ?>
                            <span class="text-muted">(<?= $e($group['label']) ?>)</span>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($debug): ?>
            <details class="error-card__debug">
                <summary>Détails techniques (mode développement)</summary>
                <pre><?= $e($debug) ?></pre>
            </details>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
