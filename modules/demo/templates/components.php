<?php
/**
 * Composants du CSS commun : typographie, boutons, badges, alertes, cartes, listes, onglets internes,
 * menus, arbre, indicateurs. Une seule règle propre au module (.demo-gauge) pour illustrer la frontière.
 * Variables : $gauge (int 0..100), $module, $e.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
?>
<div class="module module-demo">
    <div class="subtabs" role="tablist" data-subtabs="demo-components-panels">
        <button type="button" class="subtabs__tab" role="tab" data-subtab="text" aria-selected="true">Textes et boutons</button>
        <button type="button" class="subtabs__tab" role="tab" data-subtab="blocks" aria-selected="false">Badges, alertes, cartes</button>
        <button type="button" class="subtabs__tab" role="tab" data-subtab="layout" aria-selected="false">Dispositions et listes</button>
        <button type="button" class="subtabs__tab" role="tab" data-subtab="misc" aria-selected="false">Menus, arbre, indicateurs</button>
        <button type="button" class="subtabs__tab" role="tab" data-subtab="css" aria-selected="false">CSS commun vs module</button>
    </div>
    <p class="text-muted text-small">Ces onglets internes sont gérés par le noyau : <code>[data-subtabs="id"] &gt; [data-subtab="x"]</code> affiche le panneau <code>[data-subtab-panel="x"]</code> du conteneur <code>#id</code>.</p>

    <div id="demo-components-panels">
        <!-- ===================== Textes et boutons ===================== -->
        <section data-subtab-panel="text">
            <div class="split split--wide">
                <div class="card">
                    <div class="card__header"><h3 class="card__title">Titres et textes</h3></div>
                    <div class="card__body">
                        <h1>Titre de niveau 1</h1>
                        <h2>Titre de niveau 2</h2>
                        <h3>Titre de niveau 3</h3>
                        <h4>Titre de niveau 4 (capitales)</h4>
                        <p>Paragraphe courant avec un <a href="#" data-route="index">lien interne</a>, un <a href="https://www.php.net/" target="_blank" rel="noopener" data-external>lien externe <?= $icon('external', 'icon--sm') ?></a>, du <code>code inline</code>, du <strong>gras</strong> et de l’<em>italique</em>.</p>
                        <p class="text-muted">Texte atténué (<code>.text-muted</code>). <span class="text-small">Petit texte (<code>.text-small</code>).</span> <span class="text-danger">Danger</span>, <span class="text-success">succès</span>, <span class="text-warning">avertissement</span>.</p>
                        <p>Raccourci : <kbd>Ctrl</kbd> + <kbd>S</kbd>. Chiffre tabulaire : <span class="mono">1 234,56</span>.</p>
<pre><code>// Bloc de code (&lt;pre&gt;&lt;code&gt;)
return ActionResult::ok(null, 'Enregistré.')-&gt;refresh();</code></pre>
                        <ul><li>Liste à puces</li><li>Deuxième élément<ul><li>Sous-élément</li></ul></li></ul>
                        <ol><li>Liste numérotée</li><li>Deuxième étape</li></ol>
                        <hr>
                        <p class="mb-0">Texte après un séparateur (<code>&lt;hr&gt;</code>).</p>
                    </div>
                </div>
                <div>
                    <div class="card">
                        <div class="card__header"><h3 class="card__title">Boutons : variantes</h3></div>
                        <div class="card__body">
                            <div class="flex flex--wrap mb-3">
                                <button type="button" class="btn">Standard</button>
                                <button type="button" class="btn btn--primary">Principal</button>
                                <button type="button" class="btn btn--accent">Accent</button>
                                <button type="button" class="btn btn--danger">Danger</button>
                                <button type="button" class="btn btn--outline-danger">Danger contour</button>
                                <button type="button" class="btn btn--ghost">Fantôme</button>
                                <button type="button" class="btn btn--link">Lien</button>
                            </div>
                            <div class="flex flex--wrap mb-3">
                                <button type="button" class="btn btn--sm">Petit</button>
                                <button type="button" class="btn btn--sm btn--primary"><?= $icon('plus') ?> Avec icône</button>
                                <button type="button" class="btn btn--icon" title="Icône seule" aria-label="Icône seule"><?= $icon('settings') ?></button>
                                <button type="button" class="btn btn--sm btn--icon" title="Petite icône" aria-label="Petite icône"><?= $icon('edit') ?></button>
                                <div class="btn-group" role="group" aria-label="Groupe">
                                    <button type="button" class="btn">Jour</button>
                                    <button type="button" class="btn is-active">Semaine</button>
                                    <button type="button" class="btn">Mois</button>
                                </div>
                            </div>
                            <button type="button" class="btn btn--block mb-3">Bouton pleine largeur (<code>.btn--block</code>)</button>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card__header"><h3 class="card__title">Boutons : états</h3></div>
                        <div class="card__body">
                            <table class="table table--compact">
                                <thead><tr><th>État</th><th>Rendu</th><th>Comment</th></tr></thead>
                                <tbody>
                                <tr><td>Normal</td><td><button type="button" class="btn btn--primary">Enregistrer</button></td><td><code>.btn.btn--primary</code></td></tr>
                                <tr><td>Survolé</td><td><button type="button" class="btn btn--primary demo-hover">Enregistrer</button></td><td>vrai <code>:hover</code> à la souris ; ici simulé par <code>.demo-hover</code> (style du module)</td></tr>
                                <tr><td>Actif / sélectionné</td><td><button type="button" class="btn is-active">Filtre actif</button></td><td><code>.btn.is-active</code></td></tr>
                                <tr><td>Désactivé</td><td><button type="button" class="btn btn--primary" disabled>Enregistrer</button> <a class="btn" href="#" aria-disabled="true">Lien désactivé</a></td><td><code>disabled</code> ou <code>aria-disabled="true"</code></td></tr>
                                <tr><td>Occupé</td><td><button type="button" class="btn btn--primary is-busy">Enregistrer</button> <button type="button" class="btn is-busy">Chargement</button></td><td><code>.btn.is-busy</code> — posé automatiquement pendant une action</td></tr>
                                <tr><td>Focus clavier</td><td><button type="button" class="btn">Tabulez jusqu’ici</button></td><td><code>:focus-visible</code> : anneau <code>--c-focus</code></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===================== Badges, alertes, cartes ===================== -->
        <section data-subtab-panel="blocks" hidden>
            <div class="split split--wide">
                <div>
                    <div class="card">
                        <div class="card__header"><h3 class="card__title">Badges et chips</h3></div>
                        <div class="card__body">
                            <div class="flex flex--wrap mb-3">
                                <span class="badge">Défaut</span>
                                <span class="badge badge--success">Succès</span>
                                <span class="badge badge--warning">Avertissement</span>
                                <span class="badge badge--danger">Danger</span>
                                <span class="badge badge--info">Info</span>
                                <span class="badge badge--muted">Atténué</span>
                                <span class="badge badge--dot badge--success">Avec point</span>
                                <span class="badge mono">v1.0.0</span>
                            </div>
                            <div class="chips">
                                <span class="chip"><?= $icon('tag', 'icon--sm') ?> outillage</span>
                                <span class="chip"><?= $icon('tag', 'icon--sm') ?> urgent <button type="button" class="chip__remove" aria-label="Retirer le tag urgent"><?= $icon('close', 'icon--sm') ?></button></span>
                                <span class="chip">sans icône</span>
                            </div>
                            <p class="text-small text-muted mt-3 mb-0">Points d’état : <span class="dot dot--ok"></span> ok <span class="dot dot--warn"></span> attention <span class="dot dot--error"></span> erreur <span class="dot"></span> neutre.</p>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card__header"><h3 class="card__title">Alertes (4 niveaux)</h3></div>
                        <div class="card__body">
                            <div class="alert alert--info" role="status"><?= $icon('info') ?><div><p class="alert__title">Information</p><p class="mb-0">Les alertes sont des blocs statiques dans le contenu ; les toasts (écran Notifications) sont éphémères et globaux.</p></div></div>
                            <div class="alert alert--success" role="status"><?= $icon('success') ?><div><p class="alert__title">Succès</p><p class="mb-0">L’opération s’est terminée correctement.</p></div></div>
                            <div class="alert alert--warning" role="alert"><?= $icon('warning') ?><div><p class="alert__title">Avertissement</p><p class="mb-0">Vérifiez les données avant de poursuivre.</p></div></div>
                            <div class="alert alert--error mb-0" role="alert"><?= $icon('error') ?><div><p class="alert__title">Erreur</p><p class="mb-0">Le traitement a échoué. Référence : <span class="mono">ERR-DEMO-0001</span></p></div></div>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="card">
                        <div class="card__header">
                            <h3 class="card__title"><?= $icon('folder') ?> Carte complète</h3>
                            <span class="badge badge--info">en-tête</span>
                            <button type="button" class="btn btn--sm btn--ghost btn--icon" title="Action d’en-tête" aria-label="Action d’en-tête"><?= $icon('more') ?></button>
                        </div>
                        <div class="card__body">
                            <p>Corps de carte (<code>.card__body</code>). Une carte regroupe un sujet ; elle ne doit pas être imbriquée à plus d’un niveau.</p>
                            <dl class="dl mb-0">
                                <dt>Créée le</dt><dd><?= $e($datetime(\Atelier\Support\Clock::utc())) ?></dd>
                                <dt>Auteur</dt><dd>Atelier</dd>
                                <dt>État</dt><dd><span class="badge badge--success badge--dot">Actif</span></dd>
                            </dl>
                        </div>
                        <div class="card__footer flex flex--between"><span class="text-small text-muted">Pied de carte</span><button type="button" class="btn btn--sm">Action</button></div>
                    </div>
                    <div class="cards">
                        <div class="card card--compact mb-0"><div class="card__body kpi"><span class="kpi__value">1 284</span><span class="kpi__label">indicateur (<code>.kpi</code>)</span></div></div>
                        <div class="card card--compact mb-0"><div class="card__body kpi"><span class="kpi__value text-success">+12 %</span><span class="kpi__label">progression</span></div></div>
                        <div class="card card--compact mb-0"><div class="card__body kpi"><span class="kpi__value text-danger">3</span><span class="kpi__label">alertes <span class="badge badge--danger">nouveau</span></span></div></div>
                    </div>
                    <p class="text-small text-muted mt-3">Grille <code>.cards</code> : colonnes automatiques à partir de 280 px — vérifie le comportement aux largeurs bureau (1280 px minimum).</p>
                </div>
            </div>
        </section>

        <!-- ===================== Dispositions et listes ===================== -->
        <section data-subtab-panel="layout" hidden>
            <h3>Deux colonnes <code>.split</code> (320 px + reste)</h3>
            <div class="split mb-4">
                <div class="card mb-0">
                    <div class="card__header"><h3 class="card__title">Liste <code>.list</code></h3></div>
                    <ul class="list">
                        <li class="list__item"><?= $icon('file') ?><span class="grow">Élément normal</span><span class="badge badge--muted">12</span></li>
                        <li class="list__item is-selected"><?= $icon('file') ?><span class="grow">Élément sélectionné (<code>.is-selected</code>)</span><?= $icon('check', 'text-success') ?></li>
                        <li class="list__item demo-hover"><?= $icon('file') ?><span class="grow">Élément survolé (simulé)</span></li>
                        <li class="list__item text-muted"><?= $icon('lock') ?><span class="grow">Élément désactivé (texte atténué)</span></li>
                        <a class="list__item list__item--link" href="#" data-route="tables"><?= $icon('list') ?><span class="grow">Élément-lien vers les tableaux</span><?= $icon('chevron-right') ?></a>
                    </ul>
                </div>
                <div class="card mb-0">
                    <div class="card__header"><h3 class="card__title">Liste de définitions <code>.dl</code></h3></div>
                    <div class="card__body">
                        <dl class="dl">
                            <dt>Identifiant</dt><dd><span class="mono">demo</span></dd>
                            <dt>Espace de noms</dt><dd><code>Atelier\Modules\Demo</code></dd>
                            <dt>Jeux de données</dt><dd>demo.item (partagé), demo.secret (privé)</dd>
                            <dt>Description longue</dt><dd>La grille <code>max-content 1fr</code> aligne les libellés sur la plus longue clé et laisse la valeur occuper le reste, quelle que soit sa longueur : ce texte peut donc s’étendre sur plusieurs lignes sans casser l’alignement.</dd>
                        </dl>
                    </div>
                </div>
            </div>

            <h3>Barre d’outils <code>.toolbar</code></h3>
            <div class="toolbar">
                <button type="button" class="btn btn--primary"><?= $icon('plus') ?> Nouveau</button>
                <button type="button" class="btn"><?= $icon('refresh') ?> Actualiser</button>
                <div class="field input-icon" style="width: 260px"><?= $icon('search') ?><label class="sr-only" for="demo-toolbar-search">Rechercher</label><input class="input" id="demo-toolbar-search" type="search" placeholder="Rechercher…"></div>
                <span class="toolbar__spacer"></span>
                <span class="text-muted text-small">120 éléments</span>
                <button type="button" class="btn btn--ghost btn--icon" title="Exporter" aria-label="Exporter"><?= $icon('download') ?></button>
            </div>

            <h3 class="mt-4">Fieldset et légende</h3>
            <fieldset>
                <legend>Groupe de champs</legend>
                <div class="form-grid">
                    <div class="field"><label class="field__label" for="demo-fs-1">Champ A</label><input class="input" id="demo-fs-1" type="text" value="valeur"></div>
                    <div class="field"><label class="field__label" for="demo-fs-2">Champ B</label><input class="input" id="demo-fs-2" type="text" placeholder="vide"></div>
                    <div class="field"><label class="field__label" for="demo-fs-3">Champ C (lecture seule)</label><input class="input" id="demo-fs-3" type="text" value="lecture seule" readonly></div>
                </div>
            </fieldset>
            <p class="text-small text-muted">Le formulaire complet, avec validation serveur, est dans l’écran <a href="#" data-route="forms">Formulaires</a>.</p>
        </section>

        <!-- ===================== Menus, arbre, indicateurs ===================== -->
        <section data-subtab-panel="misc" hidden>
            <div class="split split--wide">
                <div>
                    <div class="card">
                        <div class="card__header"><h3 class="card__title">Menu déroulant <code>.dropdown</code> (details natif)</h3></div>
                        <div class="card__body flex flex--wrap">
                            <details class="dropdown">
                                <summary class="btn">Actions <?= $icon('chevron-down', 'icon--sm') ?></summary>
                                <div class="dropdown__menu dropdown__menu--left">
                                    <button type="button" class="dropdown__item"><?= $icon('edit') ?> Modifier</button>
                                    <button type="button" class="dropdown__item"><?= $icon('copy') ?> Dupliquer</button>
                                    <button type="button" class="dropdown__item" disabled><?= $icon('print') ?> Imprimer (indisponible)</button>
                                    <div class="dropdown__separator"></div>
                                    <button type="button" class="dropdown__item dropdown__item--danger"><?= $icon('trash') ?> Supprimer</button>
                                </div>
                            </details>
                            <details class="dropdown">
                                <summary class="btn btn--ghost btn--icon" title="Plus" aria-label="Plus"><?= $icon('more') ?></summary>
                                <div class="dropdown__menu">
                                    <a class="dropdown__item" href="#" data-route="index"><?= $icon('home') ?> Vue d’ensemble</a>
                                    <a class="dropdown__item" href="#" data-route="errors"><?= $icon('error') ?> Cas d’erreur</a>
                                </div>
                            </details>
                            <p class="text-small text-muted mb-0 w-100 mt-2">Aucun JavaScript : <code>&lt;details&gt;</code> ouvre et ferme le menu, <code>.dropdown__menu--left</code> l’aligne à gauche.</p>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card__header"><h3 class="card__title">Arbre <code>.tree</code></h3></div>
                        <div class="card__body">
                            <?php // Référence pour les modules : le rôle et l'état vont sur l'élément focalisable, jamais sur le <li>, sans quoi un lecteur d'écran n'annonce ni la nature ni le repli. ?>
                            <ul class="tree" role="tree" aria-label="Ressources de démonstration">
                                <li class="is-open" role="none">
                                    <div class="tree__row" role="none"><button type="button" class="tree__toggle" aria-expanded="true" aria-label="Replier"><?= $icon('chevron-right', 'icon--sm') ?></button><?= $icon('folder', 'icon--sm') ?> <span role="treeitem" aria-expanded="true" tabindex="0">atelier/demo</span></div>
                                    <ul role="group">
                                        <li class="is-open" role="none">
                                            <div class="tree__row is-selected" role="none"><button type="button" class="tree__toggle" aria-expanded="true" aria-label="Replier"><?= $icon('chevron-right', 'icon--sm') ?></button><?= $icon('folder', 'icon--sm') ?> <span role="treeitem" aria-expanded="true" aria-selected="true" tabindex="-1">screen (sélectionné)</span></div>
                                            <ul role="group">
                                                <li role="none"><div class="tree__row" role="none"><span class="tree__toggle tree__toggle--spacer"></span><?= $icon('file', 'icon--sm') ?> <span role="treeitem" tabindex="-1">index</span></div></li>
                                                <li role="none"><div class="tree__row" role="none"><span class="tree__toggle tree__toggle--spacer"></span><?= $icon('file', 'icon--sm') ?> <span role="treeitem" tabindex="-1">components</span></div></li>
                                            </ul>
                                        </li>
                                        <li role="none">
                                            <div class="tree__row" role="none"><button type="button" class="tree__toggle" aria-expanded="false" aria-label="Déplier"><?= $icon('chevron-right', 'icon--sm') ?></button><?= $icon('database', 'icon--sm') ?> <span role="treeitem" aria-expanded="false" tabindex="-1">data (replié)</span></div>
                                            <ul role="group"><li role="none"><div class="tree__row" role="none"><span role="treeitem" tabindex="-1">item</span></div></li></ul>
                                        </li>
                                        <li role="none"><div class="tree__row" role="none"><span class="tree__toggle tree__toggle--spacer"></span><?= $icon('key', 'icon--sm') ?> <span role="treeitem" tabindex="-1">action/secret</span></div></li>
                                    </ul>
                                </li>
                            </ul>
                            <p class="text-small text-muted mt-2 mb-0">Le repli est purement CSS (<code>li.is-open &gt; ul</code>) ; l’écran Cycle de vie montre comment le module branche ses écouteurs. Le rôle <code>treeitem</code> et l’état <code>aria-expanded</code> sont portés par l’élément focalisable, et un seul élément est atteignable au clavier (<code>tabindex="0"</code>).</p>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="card">
                        <div class="card__header"><h3 class="card__title">Indicateurs de chargement</h3></div>
                        <div class="card__body">
                            <div class="flex mb-3"><span class="spinner"></span> <span>Spinner (<code>.spinner</code>)</span> <span class="spinner spinner--lg"></span> <span>grand</span> <?= $icon('loader', 'icon--spin') ?> <span>icône <code>.icon--spin</code></span></div>
                            <p class="text-small text-muted mb-1">Progression déterminée (<code>&lt;progress value&gt;</code>) :</p>
                            <progress value="<?= (int) $gauge ?>" max="100" class="mb-3"><?= (int) $gauge ?> %</progress>
                            <p class="text-small text-muted mb-1">Progression indéterminée (sans <code>value</code>) :</p>
                            <progress class="mb-3"></progress>
                            <p class="text-small text-muted mb-1">Squelettes (<code>.skeleton</code>) pendant un chargement :</p>
                            <div class="demo-skeleton-lines">
                                <div class="skeleton" style="width: 60%"></div>
                                <div class="skeleton" style="width: 90%"></div>
                                <div class="skeleton" style="width: 75%"></div>
                                <div class="skeleton demo-skeleton-block"></div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card__header"><h3 class="card__title">Zone de dépôt <code>.dropzone</code></h3></div>
                        <div class="card__body">
                            <div class="dropzone"><?= $icon('upload', 'icon--lg') ?><p class="mb-0 mt-2">Déposez un fichier ici (statique : voir Données partagées pour un vrai téléversement)</p></div>
                            <div class="dropzone is-over mt-3"><p class="mb-0">État <code>.is-over</code> pendant le survol d’un fichier</p></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===================== CSS commun vs module ===================== -->
        <section data-subtab-panel="css" hidden>
            <div class="split split--wide">
                <div class="card">
                    <div class="card__header"><h3 class="card__title">Règles</h3></div>
                    <div class="card__body">
                        <ol>
                            <li>Tout ce qui précède vient de <code>public/assets/css/atelier.css</code> : le module <strong>n’a rien redéfini</strong>.</li>
                            <li>Un style propre est <strong>préfixé par la racine du module</strong> : <code>.module-demo .demo-gauge { … }</code>. Le HTML de chaque vue est enveloppé dans <code>&lt;div class="module module-demo"&gt;</code>.</li>
                            <li>Interdit : redéfinir <code>body</code>, <code>.btn</code>, <code>.table</code>, <code>.input</code>… Les variables <code>--c-*</code>, <code>--sp-*</code>, <code>--radius</code> sont réutilisées pour rester cohérent.</li>
                            <li>La feuille <code>assets/demo.css</code> est déclarée dans <code>assets.css</code> du manifeste ; le noyau la charge avant l’affichage et la retire quand le dernier onglet du module se ferme.</li>
                            <li>Une bibliothèque tierce (<code>mini-sparkline</code>) est déclarée dans <code>assets.vendor</code> avec nom, version et licence.</li>
                        </ol>
<pre><code>/* assets/demo.css — extrait */
.module-demo .demo-gauge { display: grid; grid-template-columns: 1fr auto; … }
.module-demo .demo-gauge__bar { height: 10px; background: var(--c-border); border-radius: 5px; }
.module-demo .demo-gauge__fill { background: var(--c-accent); }</code></pre>
                    </div>
                </div>
                <div class="card">
                    <div class="card__header"><h3 class="card__title">Composant propre : <code>.demo-gauge</code></h3><span class="badge badge--info">CSS du module</span></div>
                    <div class="card__body">
                        <div class="demo-gauge" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $gauge ?>" aria-label="Taux de couverture">
                            <span class="demo-gauge__label">Couverture des vérifications</span>
                            <span class="demo-gauge__value"><?= (int) $gauge ?> %</span>
                            <div class="demo-gauge__bar"><div class="demo-gauge__fill" style="width: <?= (int) $gauge ?>%"></div></div>
                        </div>
                        <div class="demo-gauge demo-gauge--warn mt-3" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="18" aria-label="Stock faible">
                            <span class="demo-gauge__label">Variante <code>--warn</code></span>
                            <span class="demo-gauge__value">18 %</span>
                            <div class="demo-gauge__bar"><div class="demo-gauge__fill" style="width: 18%"></div></div>
                        </div>
                        <p class="text-small text-muted mt-3 mb-0">Ce composant n’existe pas dans le CSS commun : il est entièrement défini dans <code>assets/demo.css</code>, sous le préfixe <code>.module-demo</code>, à partir des variables du thème.</p>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
