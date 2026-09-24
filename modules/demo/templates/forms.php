<?php
/**
 * Formulaires : formulaire complet validé côté serveur, filtre instantané (data-auto-submit),
 * formulaire avec confirmation (data-confirm), bouton avec saisie préalable (data-prompt).
 * Variables : $categories (list), $today (Y-m-d), $firstItem (array|null), $rights (array), $module, $e.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$canUpdate = (bool) ($rights['update'] ?? false);
?>
<div class="module module-demo">
    <div class="split split--wide">
        <div>
            <div class="card">
                <div class="card__header">
                    <h3 class="card__title"><?= $icon('edit') ?> Formulaire complet</h3>
                    <span class="badge badge--info">data-track-dirty</span>
                    <span class="badge badge--info">data-save-shortcut</span>
                </div>
                <div class="card__body">
                    <p class="text-small text-muted">
                        Soumis par <code>fetch</code> (JSON, ou multipart si un fichier est choisi). Le serveur lève <code>ValidationException</code> :
                        nom vide, e-mail invalide, quantité négative, date passée, case « accepter » non cochée. Les erreurs s’affichent près des champs.
                        Toute saisie marque l’onglet « modifié » (point orange) ; <kbd>Ctrl</kbd>+<kbd>S</kbd> soumet.
                    </p>
                    <form data-action="submit-form" data-track-dirty data-save-shortcut novalidate id="demo-main-form" autocomplete="off">
                        <div class="form-grid">
                            <div class="field">
                                <label class="field__label" for="f-name">Nom <span class="required" aria-hidden="true">*</span></label>
                                <input class="input" id="f-name" name="name" type="text" maxlength="80" placeholder="Ex. Perceuse compacte" required autofocus>
                                <span class="field__help">Obligatoire, 80 caractères au plus.</span>
                                <span class="field__error"></span>
                            </div>
                            <div class="field">
                                <label class="field__label" for="f-email">E-mail <span class="required" aria-hidden="true">*</span></label>
                                <div class="input-icon"><?= $icon('send') ?><input class="input" id="f-email" name="email" type="email" placeholder="prenom.nom@exemple.fr" required></div>
                                <span class="field__help">Champ avec icône (<code>.input-icon</code>).</span>
                                <span class="field__error"></span>
                            </div>
                            <div class="field">
                                <label class="field__label" for="f-quantity">Quantité <span class="required" aria-hidden="true">*</span></label>
                                <div class="input-group">
                                    <input class="input" id="f-quantity" name="quantity" type="number" min="0" step="1" value="1" required>
                                    <button type="button" class="btn" data-demo="quantity-plus" title="Ajouter 10" aria-label="Ajouter 10">+10</button>
                                </div>
                                <span class="field__help">Groupe champ + bouton (<code>.input-group</code>). Essayez une valeur négative.</span>
                                <span class="field__error"></span>
                            </div>
                            <div class="field">
                                <label class="field__label" for="f-date">Date souhaitée <span class="required" aria-hidden="true">*</span></label>
                                <input class="input" id="f-date" name="date" type="date" value="<?= $e($today) ?>" min="<?= $e($today) ?>" required>
                                <span class="field__help">Aujourd’hui ou plus tard (le navigateur limite, le serveur vérifie).</span>
                                <span class="field__error"></span>
                            </div>
                            <div class="field">
                                <label class="field__label" for="f-category">Catégorie <span class="required" aria-hidden="true">*</span></label>
                                <select class="select" id="f-category" name="category" required>
                                    <option value="">— Choisir —</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $e($category) ?>"><?= $e($category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="field__error"></span>
                            </div>
                            <div class="field">
                                <label class="field__label" for="f-tags">Tags (sélection multiple)</label>
                                <select class="select" id="f-tags" name="tags[]" multiple size="4">
                                    <option value="fragile">fragile</option>
                                    <option value="lourd">lourd</option>
                                    <option value="promotion">promotion</option>
                                    <option value="nouveau">nouveau</option>
                                    <option value="fin-de-serie">fin de série</option>
                                </select>
                                <span class="field__help">Ctrl+clic pour plusieurs valeurs ; transmis en <code>tags[]</code>.</span>
                            </div>
                            <div class="field field--full">
                                <label class="field__label" for="f-comment">Commentaire</label>
                                <textarea class="textarea" id="f-comment" name="comment" rows="3" placeholder="Facultatif"></textarea>
                            </div>
                            <div class="field">
                                <span class="field__label">Livraison</span>
                                <label class="radio"><input type="radio" name="shipping" value="standard" checked> Standard (3 jours)</label>
                                <label class="radio"><input type="radio" name="shipping" value="express"> Express (24 h)</label>
                                <span class="field__error"></span>
                            </div>
                            <div class="field">
                                <label class="field__label" for="f-attachment">Pièce jointe (facultatif)</label>
                                <input class="input" id="f-attachment" name="attachment" type="file">
                                <span class="field__help">Sa présence bascule l’envoi en <code>multipart/form-data</code> ; le fichier n’est pas conservé.</span>
                            </div>
                            <div class="field field--full">
                                <label class="checkbox"><input type="checkbox" name="accept" value="1"> J’accepte les conditions de la démonstration <span class="required" aria-hidden="true">*</span></label>
                                <span class="field__error"></span>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary"><?= $icon('save') ?> Valider</button>
                            <button type="reset" class="btn btn--ghost">Réinitialiser les champs</button>
                            <span class="toolbar__spacer"></span>
                            <span class="text-small text-muted">Réponse : <code>ActionResult::ok($data, 'Formulaire valide.')-&gt;dirty(false)</code></span>
                        </div>
                    </form>
                    <div class="mt-3" data-demo-form-result hidden>
                        <h4>Données reçues par le serveur</h4>
                        <pre class="demo-code mb-0"><code></code></pre>
                    </div>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('filter') ?> Filtre instantané</h3><span class="badge badge--info">data-auto-submit</span></div>
                <div class="card__body">
                    <p class="text-small text-muted">Le formulaire est soumis à chaque changement (Entrée dans le champ texte). L’action renvoie des données ; le JavaScript du module écoute <code>atelier:submitted</code> et affiche les résultats.</p>
                    <form class="toolbar" data-action="quick-filter" data-auto-submit data-demo-results novalidate>
                        <div class="field input-icon grow"><?= $icon('search') ?><label class="sr-only" for="qf-q">Recherche</label><input class="input" id="qf-q" name="q" type="search" placeholder="Nom d’article… puis Entrée"></div>
                        <div class="field"><label class="sr-only" for="qf-category">Catégorie</label>
                            <select class="select" id="qf-category" name="category">
                                <option value="">Toutes catégories</option>
                                <?php foreach ($categories as $category): ?><option value="<?= $e($category) ?>"><?= $e($category) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn--sm">Filtrer</button>
                    </form>
                    <div class="table-wrap mb-0">
                        <table class="table table--compact">
                            <thead><tr><th class="col-num">#</th><th>Article</th><th>Catégorie</th><th class="col-num">Qté</th></tr></thead>
                            <tbody data-demo-results-body>
                                <tr><td colspan="4" class="table__empty">Saisissez un critère pour lancer la recherche.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('refresh') ?> Formulaire avec confirmation</h3><span class="badge badge--warning">data-confirm</span></div>
                <div class="card__body">
                    <p class="text-small text-muted">Avant l’envoi, le noyau ouvre la boîte de confirmation ; <code>data-danger</code> colore le bouton principal en rouge. L’action régénère les 120 articles déterministes et les sort de la corbeille (permission <code>update</code>). C’est le seul effacement physique du module : un outil de démonstration, pas un modèle de suppression.</p>
                    <form data-action="reset-items" data-confirm="Régénérer les 120 articles de démonstration ? Les modifications faites dans l’écran Tableaux seront perdues et les articles en corbeille restaurés." data-danger>
                        <div class="form-actions mt-0 pt-0" style="border-top: 0; padding-top: 0">
                            <button type="submit" class="btn btn--outline-danger" <?= $canUpdate ? '' : 'disabled title="Permission update requise"' ?>><?= $icon('refresh') ?> Réinitialiser les articles</button>
                            <?php if (!$canUpdate): ?><span class="text-small text-muted">Permission <code>update</code> requise (bouton désactivé, et route protégée côté serveur).</span><?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('edit') ?> Bouton avec saisie préalable</h3><span class="badge badge--info">data-prompt</span></div>
                <div class="card__body">
                    <?php if ($firstItem !== null): ?>
                        <p class="text-small text-muted">Le noyau demande une valeur (<code>data-prompt</code>) et l’envoie dans le champ <code>data-prompt-field</code>, fusionnée à <code>data-params</code>. La vue est rechargée par <code>-&gt;refresh()</code>.</p>
                        <div class="flex">
                            <span class="grow">Article n° <?= (int) $firstItem['id'] ?> : <strong><?= $e($firstItem['name']) ?></strong></span>
                            <button type="button" class="btn" data-action="rename" data-params='{"id": <?= (int) $firstItem['id'] ?>}' data-prompt="Nouveau nom de l’article n° <?= (int) $firstItem['id'] ?>" data-prompt-field="name" data-prompt-value="<?= $e($firstItem['name']) ?>" data-confirm-title="Renommer" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('edit') ?> Renommer…</button>
                        </div>
                    <?php else: ?>
                        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun article', 'message' => 'Lancez « console db:seed » ou utilisez « Réinitialiser les articles ».']) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title">États des champs</h3></div>
                <div class="card__body">
                    <div class="form-grid">
                        <div class="field"><label class="field__label" for="s-normal">Normal</label><input class="input" id="s-normal" type="text" value="Valeur"></div>
                        <div class="field"><label class="field__label" for="s-disabled">Désactivé</label><input class="input" id="s-disabled" type="text" value="Indisponible" disabled></div>
                        <div class="field is-invalid"><label class="field__label" for="s-invalid">En erreur</label><input class="input" id="s-invalid" type="text" value="abc" aria-invalid="true" aria-describedby="s-invalid-err"><span class="field__error" id="s-invalid-err">Message d’erreur près du champ (<code>.field.is-invalid</code>).</span></div>
                        <div class="field"><label class="field__label" for="s-small">Petit (<code>.input--sm</code>)</label><input class="input input--sm" id="s-small" type="text" placeholder="compact"></div>
                        <div class="field field--inline"><label class="field__label" for="s-inline">Inline</label><select class="select select--sm" id="s-inline" style="width: 140px"><option>Option</option></select></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
