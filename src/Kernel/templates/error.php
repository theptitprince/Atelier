<?php
/**
 * Page d'erreur pour une navigation directe (hors requête dynamique).
 * Variables : $kind, $status, $message, $errorId, $debug (array|null), $baseUrl, $e.
 */
$titles = [
    'validation' => 'Requête invalide',
    'auth' => 'Connexion requise',
    'forbidden' => 'Accès refusé',
    'not_found' => 'Page introuvable',
    'conflict' => 'Conflit',
    'unavailable' => 'Indisponible',
    'csrf' => 'Requête expirée',
    'server' => 'Erreur technique',
];
$title = $titles[$kind] ?? 'Erreur';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> — Atelier</title>
    <link rel="icon" href="<?= $e($baseUrl) ?>/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/atelier.css">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/login.css">
</head>
<body class="login-body">
<?php require __DIR__ . '/icons.php'; ?>
<main class="login">
    <div class="login__card error-card">
        <div class="login__brand">
            <svg class="icon icon--xl" aria-hidden="true"><use href="#i-<?= $kind === 'forbidden' || $kind === 'auth' ? 'lock' : ($kind === 'not_found' ? 'search' : 'warning') ?>"></use></svg>
            <h1 class="login__title"><?= $e($title) ?></h1>
        </div>
        <p class="error-card__message"><?= $e($message) ?></p>
        <?php if ($errorId): ?>
            <p class="error-card__reference">Référence d’incident : <code><?= $e($errorId) ?></code></p>
        <?php endif; ?>
        <p class="error-card__actions">
            <a class="btn btn--primary" href="<?= $e($baseUrl) ?>/">Retour à l’application</a>
        </p>
        <?php if ($debug): ?>
            <details class="error-card__debug">
                <summary>Détails techniques (mode développement)</summary>
                <pre><?= $e($debug['class'] ?? '') ?>: <?= $e($debug['message'] ?? '') ?>
<?= $e($debug['file'] ?? '') ?>

<?php if (!empty($debug['sql'])): ?>SQL : <?= $e($debug['sql']) ?>

<?php endif; ?><?= $e(implode("\n", $debug['trace'] ?? [])) ?></pre>
            </details>
        <?php endif; ?>
    </div>
    <p class="login__version">HTTP <?= (int) $status ?></p>
</main>
</body>
</html>
