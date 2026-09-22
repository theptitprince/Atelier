<?php
/**
 * Cycle de vie : hooks JS, minuteries suspendues/reprises, onglet modifié, navigation, directives
 * serveur (close, navigate, banner), état initial (ctx.state), ressource manquante.
 * Variables : $step (1..3), $now (string), $module, $e.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
?>
<div class="module module-demo">
    <div class="split split--wide">
        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('clock') ?> Minuterie de l’onglet</h3><code>ctx.interval</code></div>
                <div class="card__body">
                    <div class="flex mb-3">
                        <div class="kpi"><span class="kpi__value mono" data-demo-counter>0</span><span class="kpi__label">secondes écoulées dans cette vue</span></div>
                        <span class="toolbar__spacer"></span>
                        <span class="badge badge--success badge--dot" data-demo-counter-state>en cours</span>
                        <button type="button" class="btn btn--sm" data-demo="counter-reset">Remettre à zéro</button>
                    </div>
                    <p class="text-small text-muted mb-0">
                        Changez d’onglet (ou d’application) : le compteur <strong>s’arrête</strong> — le noyau suspend les <code>ctx.interval</code> d’un onglet masqué — puis <strong>reprend</strong> au retour.
                        Il est arrêté explicitement quand on quitte cette vue et libéré avec l’onglet.
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('activity') ?> Journal des hooks</h3><span class="badge badge--info">console.info('[demo] hook …')</span></div>
                <div class="card__body">
                    <p class="text-small text-muted">Chaque hook du module (<code>mount</code>, <code>render</code>, <code>suspend</code>, <code>resume</code>, <code>beforeClose</code>, <code>unmount</code>) est journalisé ici et dans la console du navigateur.</p>
                    <div class="table-wrap mb-2" style="max-height: 260px">
                        <table class="table table--compact">
                            <thead><tr><th>Heure</th><th>Hook</th><th>Détail</th></tr></thead>
                            <tbody data-demo-hook-log><tr><td colspan="3" class="table__empty">En attente…</td></tr></tbody>
                        </table>
                    </div>
                    <div class="flex"><button type="button" class="btn btn--sm btn--ghost" data-demo="hooks-clear"><?= $icon('trash') ?> Vider le journal</button><span class="text-small text-muted">Ouvrez la console (F12) puis changez d’onglet, revenez, fermez l’onglet.</span></div>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('database') ?> État initial transmis au JS</h3><code>ctx.state</code></div>
                <div class="card__body">
                    <p class="text-small text-muted">Le serveur renseigne <code>ModuleView::state([...])</code> ; le client le lit dans <code>ctx.state</code> à chaque rendu. Rendu serveur à <?= $e($now) ?>.</p>
                    <pre class="demo-code mb-0"><code data-demo-state>{ … }</code></pre>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('layers') ?> Onglet et navigation</h3></div>
                <div class="card__body">
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn" data-demo="dirty"><?= $icon('edit') ?> Marquer modifié (ctx.setDirty)</button>
                        <button type="button" class="btn btn--ghost" data-demo="clean">Retirer la marque</button>
                        <a class="btn" href="#" data-open-module="home"><?= $icon('home') ?> Ouvrir Accueil (data-open-module)</a>
                        <a class="btn" href="#" data-open-module="notes" data-open-route="list"><?= $icon('note') ?> Ouvrir Bloc-notes › liste</a>
                    </div>
                    <div class="flex flex--wrap mb-3">
                        <button type="button" class="btn" data-action="go-components"><?= $icon('grid') ?> Aller à Composants (-&gt;navigate)</button>
                        <button type="button" class="btn" data-action="banner-update" data-params='{"n": 1}' data-demo-banner-counter><?= $icon('refresh') ?> Mettre à jour le bandeau (-&gt;banner)</button>
                        <button type="button" class="btn btn--outline-danger" data-action="close-tab" data-confirm="Fermer l’onglet Démonstration ? (directive serveur ->close())"><?= $icon('close') ?> Fermer cet onglet (-&gt;close)</button>
                    </div>
                    <p class="text-small text-muted mb-0">Un onglet marqué « modifié » demande confirmation à la fermeture, au changement de vue et à la déconnexion. La fermeture par directive serveur est forcée (aucune confirmation du noyau) : c’est pourquoi le bouton porte lui-même un <code>data-confirm</code>.</p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('navigation') ?> Historique précédent / suivant</h3></div>
                <div class="card__body">
                    <p class="text-small text-muted">Chaque lien interne pousse une entrée d’historique (<code>/m/demo/lifecycle?step=N</code>). Utilisez les boutons Précédent / Suivant du navigateur : la vue correspondante est rechargée dans cet onglet.</p>
                    <div class="btn-group mb-3" role="group" aria-label="Étapes">
                        <?php for ($i = 1; $i <= 3; $i++): ?>
                            <a class="btn<?= $i === $step ? ' is-active' : '' ?>" href="#" data-route="lifecycle?step=<?= $i ?>"<?= $i === $step ? ' aria-current="page"' : '' ?>>Étape <?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                    <div class="alert alert--info mb-0"><?= $icon('info') ?><div>Vous êtes à l’<strong>étape <?= (int) $step ?></strong>. La route canonique de la vue est <code>lifecycle?step=<?= (int) $step ?></code> (visible dans l’URL et surlignée dans la colonne de gauche).</div></div>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('warning') ?> Ressource manquante</h3><code>Atelier.resources.acquire</code></div>
                <div class="card__body">
                    <div class="flex flex--wrap mb-2">
                        <button type="button" class="btn" data-demo="missing-css"><?= $icon('file') ?> Charger une feuille inexistante</button>
                        <button type="button" class="btn btn--ghost" data-demo="resource-count">Compteur de références de demo.css</button>
                    </div>
                    <p class="text-small text-muted mb-0">Le noyau tente <code>/module-assets/demo/assets/inexistant.css</code> et rejette la promesse avec une <code>AtelierError</code> de type <code>unavailable</code> : le module la transmet à <code>ctx.toast.fromError</code>. Le compteur montre le comptage de références : la feuille est retirée quand le dernier onglet du module se ferme.</p>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('check') ?> Libération des écouteurs</h3></div>
                <div class="card__body">
                    <p class="text-small text-muted mb-0">Tous les écouteurs de ce module passent par <code>ctx.on(...)</code> et toutes les minuteries par <code>ctx.interval</code>/<code>ctx.timeout</code>. À la fermeture de l’onglet, le noyau appelle <code>unmount</code> puis les libère : rouvrez l’onglet et vérifiez dans la console qu’aucun « [demo] tick » ne survit à la fermeture.</p>
                </div>
            </div>
        </div>
    </div>
</div>
