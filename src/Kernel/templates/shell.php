<?php
/**
 * Interface générale d'Atelier (chargée une seule fois par session de navigation).
 * Variables : $appName, $version, $baseUrl, $user, $tree, $clientConfig (JSON), $csrfToken, $minWidth, $e.
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=<?= (int) $minWidth ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= $e($csrfToken) ?>">
    <title><?= $e($appName) ?></title>
    <link rel="icon" href="<?= $e($baseUrl) ?>/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/atelier.css?v=<?= $e($version) ?>">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/print.css?v=<?= $e($version) ?>" media="print">
</head>
<body class="app-body">
<?php require __DIR__ . '/icons.php'; ?>

<div id="app" class="app" data-min-width="<?= (int) $minWidth ?>">

    <aside id="sidebar" class="sidebar" aria-label="Modules">
        <div class="sidebar__brand">
            <svg class="icon icon--lg" aria-hidden="true"><use href="#i-module"></use></svg>
            <span class="sidebar__title"><?= $e($appName) ?></span>
            <button type="button" class="btn btn--icon btn--ghost sidebar__toggle" id="sidebar-toggle" title="Replier le menu" aria-label="Replier le menu" aria-expanded="true" aria-controls="nav-tree">
                <svg class="icon" aria-hidden="true"><use href="#i-chevrons-left"></use></svg>
            </button>
        </div>

        <nav id="nav-tree" class="nav" aria-label="Arborescence des modules"></nav>

        <div class="sidebar__footer">
            <div class="sidebar__user" title="<?= $e($user['username']) ?>">
                <svg class="icon" aria-hidden="true"><use href="#i-user"></use></svg>
                <span class="sidebar__username"><?= $e($user['display_name']) ?></span>
            </div>
            <div class="sidebar__actions">
                <button type="button" class="btn btn--icon btn--ghost" data-core-action="profile" title="Profil et préférences" aria-label="Profil et préférences">
                    <svg class="icon" aria-hidden="true"><use href="#i-sliders"></use></svg>
                </button>
                <button type="button" class="btn btn--icon btn--ghost" data-core-action="password" title="Changer le mot de passe" aria-label="Changer le mot de passe">
                    <svg class="icon" aria-hidden="true"><use href="#i-key"></use></svg>
                </button>
                <form method="post" action="<?= $e($baseUrl) ?>/logout" class="inline" id="logout-form">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <button type="submit" class="btn btn--icon btn--ghost" title="Se déconnecter" aria-label="Se déconnecter">
                        <svg class="icon" aria-hidden="true"><use href="#i-logout"></use></svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="main">
        <header id="banner" class="banner" aria-label="Bandeau du module actif">
            <div class="banner__placeholder">
                <span class="banner__title"><?= $e($appName) ?></span>
            </div>
        </header>

        <div id="tabs" class="tabs" role="tablist" aria-label="Modules ouverts"></div>

        <main id="workspace" class="workspace">
            <section class="workspace__empty" id="workspace-empty">
                <svg class="icon icon--xl" aria-hidden="true"><use href="#i-module"></use></svg>
                <p>Sélectionnez un module dans la colonne de gauche.</p>
            </section>
        </main>

        <footer id="statusbar" class="statusbar" aria-label="Barre d’état">
            <div class="statusbar__left">
                <span class="statusbar__item statusbar__connection" id="status-connection" title="État de la connexion au serveur">
                    <span class="dot dot--ok" aria-hidden="true"></span><span class="statusbar__label">Connecté</span>
                </span>
                <span class="statusbar__item" id="status-user">
                    <svg class="icon icon--sm" aria-hidden="true"><use href="#i-user"></use></svg><?= $e($user['username']) ?>
                </span>
                <span class="statusbar__item" id="status-refresh" title="Dernière actualisation">
                    <svg class="icon icon--sm" aria-hidden="true"><use href="#i-clock"></use></svg><span id="status-refresh-time">—</span>
                </span>
            </div>
            <div class="statusbar__center">
                <span class="statusbar__message" id="status-message" role="status" aria-live="polite"></span>
                <span class="statusbar__progress" id="status-progress" hidden>
                    <progress id="status-progress-bar" max="100"></progress>
                </span>
            </div>
            <div class="statusbar__right">
                <span class="statusbar__item statusbar__module" id="status-module"></span>
                <span class="statusbar__item statusbar__version" title="Version d’Atelier">v<?= $e($version) ?></span>
            </div>
        </footer>
    </div>
</div>

<div id="toaster" class="toaster" aria-live="polite" aria-relevant="additions"></div>
<div id="sr-announcer" class="sr-only" aria-live="assertive" aria-atomic="true"></div>

<dialog id="dialog" class="dialog">
    <form method="dialog" class="dialog__form" id="dialog-form">
        <div class="dialog__header">
            <h2 class="dialog__title" id="dialog-title"></h2>
            <button type="button" class="btn btn--icon btn--ghost dialog__close" value="cancel" data-dialog-close title="Fermer" aria-label="Fermer">
                <svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg>
            </button>
        </div>
        <div class="dialog__body" id="dialog-body"></div>
        <div class="dialog__footer" id="dialog-footer"></div>
    </form>
</dialog>

<script id="atelier-config" type="application/json"><?= str_replace('</', '<\/', $clientConfig) ?></script>
<script src="<?= $e($baseUrl) ?>/assets/js/atelier.js?v=<?= $e($version) ?>"></script>
</body>
</html>
