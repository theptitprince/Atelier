<?php
/**
 * Notifications et états : toasts (client et serveur), barre d'état, progression, indicateur
 * d'occupation, dialogues, blocs d'état, annonce aux lecteurs d'écran.
 * Variables : $stateTypes (list<string>), $module, $e.
 * Les boutons [data-demo="…"] sont traités par assets/demo.js ; les [data-action] par le noyau.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$stateLabels = ['empty' => 'Aucune donnée', 'denied' => 'Accès refusé', 'error' => 'Erreur technique', 'unavailable' => 'Module indisponible', 'loading' => 'Chargement'];
?>
<div class="module module-demo">
    <div class="split split--wide">
        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('bell') ?> Toasts côté client</h3><code>ctx.toast.*</code></div>
                <div class="card__body">
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn" data-demo="toast" data-level="info"><?= $icon('info') ?> Info</button>
                        <button type="button" class="btn" data-demo="toast" data-level="success"><?= $icon('success') ?> Succès</button>
                        <button type="button" class="btn" data-demo="toast" data-level="warning"><?= $icon('warning') ?> Avertissement</button>
                        <button type="button" class="btn" data-demo="toast" data-level="error"><?= $icon('error') ?> Erreur</button>
                    </div>
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn" data-demo="toast-grouped" title="Même message répété : le toast affiche un compteur">Toast groupé (cliquez 3 fois)</button>
                        <button type="button" class="btn" data-demo="toast-sticky">Info persistante</button>
                        <button type="button" class="btn" data-demo="toast-temporary">Avertissement temporaire (3 s)</button>
                        <button type="button" class="btn" data-demo="toast-ref">Erreur avec référence</button>
                        <button type="button" class="btn btn--ghost" data-demo="toast-clear"><?= $icon('close') ?> Tout fermer</button>
                    </div>
                    <p class="text-small text-muted mb-0">Par défaut : info et succès disparaissent seuls (5 s / 4 s), avertissement et erreur restent jusqu’à fermeture. <code>{ sticky: true|false }</code> inverse ce comportement. Chaque toast est annoncé aux lecteurs d’écran.</p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('send') ?> Messages côté serveur</h3><code>ActionResult::ok / info / warning</code></div>
                <div class="card__body">
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn btn--primary" data-action="notify" data-params='{"level": "ok"}'>ok(null, 'msg')</button>
                        <button type="button" class="btn" data-action="notify" data-params='{"level": "info"}'>info(null, 'msg')</button>
                        <button type="button" class="btn" data-action="notify" data-params='{"level": "warning"}'>warning(null, 'msg')</button>
                    </div>
                    <p class="text-small text-muted mb-0">Le <code>message</code> de l’<code>ActionResult</code> est affiché par le noyau au niveau correspondant. Les erreurs (exceptions) sont dans l’écran <a href="#" data-route="errors">Cas d’erreur</a>.</p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('activity') ?> Barre d’état et progression</h3></div>
                <div class="card__body">
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn" data-demo="status-ctx">ctx.status('…')</button>
                        <button type="button" class="btn" data-demo="status-message" data-level="success">Atelier.status.message (succès)</button>
                        <button type="button" class="btn" data-demo="status-message" data-level="warning">… (avertissement)</button>
                        <button type="button" class="btn" data-demo="status-message" data-level="error">… (erreur)</button>
                        <button type="button" class="btn" data-action="status-update">Serveur : -&gt;status('…')</button>
                    </div>
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn btn--accent" data-demo="progress"><?= $icon('loader') ?> Progression 0 → 100 %</button>
                        <button type="button" class="btn" data-demo="progress-indeterminate">Progression indéterminée (3 s)</button>
                        <progress class="grow" max="100" value="0" data-demo-progress-local style="max-width: 240px"></progress>
                    </div>
                    <p class="text-small text-muted mb-0"><code>ctx.status</code> est le texte contextuel de l’onglet (restauré quand l’onglet redevient actif) ; <code>Atelier.status.message</code> est un message global temporaire (8 s) ; <code>Atelier.status.progress(n|true|null)</code> pilote la barre de la barre d’état.</p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('clock') ?> Action longue</h3><code>ctx.busy(true)</code></div>
                <div class="card__body">
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn btn--primary" data-demo="busy"><?= $icon('loader') ?> Appel manuel (ctx.api.post + ctx.busy)</button>
                        <button type="button" class="btn" data-action="slow" data-params='{"seconds": 2}'>Déclaratif : data-action="slow"</button>
                    </div>
                    <p class="text-small text-muted mb-0">L’indicateur <code>[data-banner-busy]</code> du bandeau s’affiche, le bouton passe en <code>.is-busy</code>. Le serveur attend 2 s puis répond.</p>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('layers') ?> Boîtes de dialogue</h3><code>ctx.dialog.*</code></div>
                <div class="card__body">
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn" data-demo="dialog-confirm">confirm()</button>
                        <button type="button" class="btn btn--outline-danger" data-demo="dialog-confirm-danger">confirm({ danger })</button>
                        <button type="button" class="btn" data-demo="dialog-alert">alert()</button>
                        <button type="button" class="btn" data-demo="dialog-prompt">prompt()</button>
                        <button type="button" class="btn btn--primary" data-demo="dialog-open">open() — formulaire libre</button>
                    </div>
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn" data-action="notify" data-params='{"level": "ok"}' data-confirm="Confirmer avant d’appeler le serveur ?" data-confirm-title="Confirmation déclarative" data-confirm-label="Oui, appeler">Déclaratif : data-confirm</button>
                        <button type="button" class="btn btn--danger" data-action="notify" data-params='{"level": "warning"}' data-confirm="Cette action est présentée comme dangereuse." data-danger>data-confirm + data-danger</button>
                    </div>
                    <p class="text-small text-muted mb-0">Toutes les boîtes reposent sur l’élément <code>&lt;dialog&gt;</code> natif du noyau : Échap ferme (sauf <code>closable: false</code>), Entrée valide le bouton principal, le focus est placé automatiquement. Résultat affiché ci-dessous :</p>
                    <p class="mt-2 mb-0"><span class="badge badge--muted">résultat</span> <span class="mono" data-demo-dialog-result>—</span></p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('eye') ?> Accessibilité</h3><code>Atelier.announce</code></div>
                <div class="card__body">
                    <div class="flex flex--wrap mb-2">
                        <button type="button" class="btn" data-demo="announce">Annoncer un texte aux lecteurs d’écran</button>
                    </div>
                    <p class="text-small text-muted mb-0">Écrit dans la zone <code>aria-live</code> globale (<code>#sr-announcer</code>) sans rien afficher : utile après une mise à jour silencieuse du contenu.</p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('folder') ?> Blocs d’état (5 types)</h3><code>renderCore('state')</code></div>
                <div class="card__body">
                    <p class="text-small text-muted">Rendus par le serveur avec <code>$this-&gt;renderCore('state', [...])</code>. Le noyau utilise les mêmes blocs quand une vue échoue (accès refusé, introuvable, indisponible, erreur).</p>
                    <div class="demo-states">
                        <?php foreach ($stateTypes as $type): ?>
                            <div class="card card--compact mb-0">
                                <div class="card__header"><code>state--<?= $e($type) ?></code></div>
                                <?= $module->renderCore('state', [
                                    'type' => $type,
                                    'title' => $stateLabels[$type] ?? $type,
                                    'message' => match ($type) {
                                        'empty' => 'Aucun élément ne correspond. Ajoutez-en un ou modifiez les filtres.',
                                        'denied' => 'Vous n’avez pas le droit d’afficher cette ressource.',
                                        'error' => 'Une erreur technique est survenue. Référence : ERR-DEMO-0002',
                                        'unavailable' => 'Le module est en maintenance : réessayez plus tard.',
                                        default => 'Les données arrivent…',
                                    },
                                    'actions' => $type === 'empty' ? '<a class="btn btn--sm btn--primary" href="#" data-route="tables">' . $icon('plus') . ' Voir les tableaux</a>' : ($type === 'error' ? '<button type="button" class="btn btn--sm" data-demo="toast" data-level="info">' . $icon('refresh') . ' Réessayer</button>' : ''),
                                ]) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
