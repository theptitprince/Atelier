<?php
/**
 * Page de connexion (seule page publique).
 * Variables : $appName, $version, $baseUrl, $csrfField, $error, $username, $next, $expired, $loggedOut, $storageOk, $e.
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($appName) ?> — Connexion</title>
    <link rel="icon" href="<?= $e($baseUrl) ?>/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/atelier.css?v=<?= $e($version) ?>">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/login.css?v=<?= $e($version) ?>">
</head>
<body class="login-body">
<?php require __DIR__ . '/icons.php'; ?>
<main class="login">
    <form method="post" action="<?= $e($baseUrl) ?>/login" class="login__card" novalidate>
        <div class="login__brand">
            <svg class="icon icon--xl" aria-hidden="true"><use href="#i-module"></use></svg>
            <h1 class="login__title"><?= $e($appName) ?></h1>
        </div>

        <?php if (!$storageOk): ?>
            <div class="alert alert--error" role="alert">
                <svg class="icon" aria-hidden="true"><use href="#i-error"></use></svg>
                <div>Le stockage applicatif est indisponible : la connexion est impossible pour le moment.</div>
            </div>
        <?php endif; ?>
        <?php if ($expired): ?>
            <div class="alert alert--warning" role="status">
                <svg class="icon" aria-hidden="true"><use href="#i-clock"></use></svg>
                <div>Votre session a expiré. Veuillez vous reconnecter.</div>
            </div>
        <?php elseif ($loggedOut): ?>
            <div class="alert alert--info" role="status">
                <svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg>
                <div>Vous êtes déconnecté.</div>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert--error" role="alert" id="login-error">
                <svg class="icon" aria-hidden="true"><use href="#i-error"></use></svg>
                <div><?= $e($error) ?></div>
            </div>
        <?php endif; ?>

        <?= $csrfField ?>
        <?php if ($next !== ''): ?>
            <input type="hidden" name="next" value="<?= $e($next) ?>">
        <?php endif; ?>

        <div class="field">
            <label class="field__label" for="login-username">
                <svg class="icon icon--sm" aria-hidden="true"><use href="#i-user"></use></svg> Identifiant
            </label>
            <input class="input" type="text" id="login-username" name="username" value="<?= $e($username) ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus<?= $error ? ' aria-describedby="login-error"' : '' ?>>
        </div>

        <div class="field">
            <label class="field__label" for="login-password">
                <svg class="icon icon--sm" aria-hidden="true"><use href="#i-lock"></use></svg> Mot de passe
            </label>
            <input class="input" type="password" id="login-password" name="password" autocomplete="current-password" required>
        </div>

        <button type="submit" class="btn btn--primary btn--block login__submit"<?= $storageOk ? '' : ' disabled' ?>>
            <svg class="icon" aria-hidden="true"><use href="#i-logout"></use></svg> Se connecter
        </button>

        <p class="login__help">Mot de passe oublié ? Adressez-vous à un administrateur.</p>
    </form>
    <p class="login__version">Atelier v<?= $e($version) ?></p>
</main>
</body>
</html>
