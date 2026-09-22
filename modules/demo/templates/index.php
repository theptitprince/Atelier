<?php
/**
 * Vue d'ensemble : présentation, indicateurs, checklist des vérifications, structure d'un module.
 * Variables : $total, $active, $byCategory (array), $sparkline (string), $checklist (list), $excerpts (array), $module, $e.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$screens = ['index' => 'Vue d’ensemble', 'components' => 'Composants', 'forms' => 'Formulaires', 'tables' => 'Tableaux', 'feedback' => 'Notifications', 'lifecycle' => 'Cycle de vie', 'shared' => 'Partagé', 'errors' => 'Erreurs'];
?>
<div class="module module-demo">
    <div class="demo-hero">
        <div class="prose">
            <h1>Module de démonstration</h1>
            <p>
                Ce module est <strong>fictif</strong> : il ne gère aucune donnée réelle. Il sert de <strong>référence technique et visuelle</strong>
                du noyau Atelier — chaque composant du CSS commun, chaque comportement déclaratif et chaque directive serveur y est exercé au moins une fois —
                et de <strong>modèle</strong> pour créer un nouveau module (voir <code>modules/demo/README.md</code>).
            </p>
            <p class="text-muted">
                Les articles manipulés (outillage, papeterie, mobilier, informatique) sont générés de façon déterministe : « Réinitialiser » dans l’écran
                Formulaires les remet à l’identique.
            </p>
        </div>
        <div class="demo-hero__aside">
            <div class="card card--compact mb-0">
                <div class="card__body">
                    <div class="demo-kpis">
                        <div class="kpi"><span class="kpi__value"><?= (int) $total ?></span><span class="kpi__label">articles</span></div>
                        <div class="kpi"><span class="kpi__value"><?= (int) $active ?></span><span class="kpi__label">actifs</span></div>
                        <div class="kpi"><span class="kpi__value"><?= count($byCategory) ?></span><span class="kpi__label">catégories</span></div>
                    </div>
                    <hr>
                    <p class="text-small text-muted mb-2">Quantités par catégorie — mini-courbe tracée par la bibliothèque tierce <code>mini-sparkline</code> (déclarée dans <code>assets.vendor</code>) :</p>
                    <div class="flex">
                        <span class="sparkline" data-sparkline="<?= $e($sparkline) ?>" title="<?= $e($sparkline) ?>" aria-label="Quantités par catégorie : <?= $e($sparkline) ?>"></span>
                        <dl class="dl text-small">
                            <?php foreach ($byCategory as $category => $quantity): ?>
                                <dt><?= $e($category) ?></dt><dd><?= (int) $quantity ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <h2 class="mt-5">Vérifications couvertes (cahier des charges §6.7.3)</h2>
    <p class="text-muted">Chaque ligne renvoie vers l’écran qui l’exerce. Les liens utilisent <code>data-route</code> : le noyau charge la route dans cet onglet et met l’URL à jour.</p>
    <div class="card">
        <ul class="list demo-checklist">
            <?php foreach ($checklist as $i => [$label, $route]): ?>
                <li class="list__item">
                    <span class="badge badge--muted mono"><?= sprintf('%02d', $i + 1) ?></span>
                    <span class="grow"><?= $e($label) ?></span>
                    <a class="btn btn--sm btn--ghost" href="#" data-route="<?= $e($route) ?>"><?= $icon('external', 'icon--sm') ?> <?= $e($screens[$route] ?? $route) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <h2 class="mt-5">Structure d’un module</h2>
    <p class="text-muted">Les extraits ci-dessous sont lus dans les fichiers réels de ce module au moment du rendu : ils ne peuvent pas être obsolètes.</p>
    <div class="split split--wide">
        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('file') ?> <code>manifest.json</code></h3><span class="badge">source de vérité</span></div>
                <div class="card__body card__body--flush"><pre class="demo-code"><code><?= $e($excerpts['manifest']) ?></code></pre></div>
                <div class="card__footer text-small text-muted">Identité, navigation (3 niveaux max), ressources ACL, assets, jeux de données. Validé à chaque découverte.</div>
            </div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('layers') ?> <code>src/DemoModule.php</code> — routes</h3></div>
                <div class="card__body card__body--flush"><pre class="demo-code"><code><?= $e($excerpts['routes']) ?></code></pre></div>
                <div class="card__footer text-small text-muted">Trois natures : <code>view</code> (GET → ModuleView), <code>action</code> (POST → ActionResult), <code>raw</code> (GET → Response). Chaque route porte sa permission.</div>
            </div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('eye') ?> Une vue</h3></div>
                <div class="card__body card__body--flush"><pre class="demo-code"><code><?= $e($excerpts['view']) ?></code></pre></div>
            </div>
        </div>
        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('send') ?> Une action</h3></div>
                <div class="card__body card__body--flush"><pre class="demo-code"><code><?= $e($excerpts['action']) ?></code></pre></div>
                <div class="card__footer text-small text-muted">Aucun SQL ici : le dépôt <code>ItemRepository</code> est seul à toucher la base. Les exceptions du noyau deviennent des réponses normalisées.</div>
            </div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('file') ?> <code>templates/feedback.php</code> — en-tête</h3></div>
                <div class="card__body card__body--flush"><pre class="demo-code"><code><?= $e($excerpts['template']) ?></code></pre></div>
                <div class="card__footer text-small text-muted">Chaque gabarit reçoit <code>$e</code> (échappement obligatoire), <code>$module</code>, <code>$baseUrl</code>, <code>$datetime</code>… et enveloppe son HTML dans <code>.module.module-demo</code>.</div>
            </div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('puzzle') ?> <code>assets/demo.js</code> — enregistrement</h3></div>
                <div class="card__body card__body--flush"><pre class="demo-code"><code><?= $e($excerpts['js']) ?></code></pre></div>
                <div class="card__footer text-small text-muted">Écouteurs via <code>ctx.on</code>, minuteries via <code>ctx.interval</code>/<code>ctx.timeout</code> : tout est libéré par le noyau à la fermeture de l’onglet.</div>
            </div>
        </div>
    </div>
</div>
