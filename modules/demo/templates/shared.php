<?php
/**
 * Données partagées : registre commun, tags, relations typées, pièces jointes, catalogue.
 * Variables : $items (list), $item (array|null), $info (array|null), $tags (list), $relations (list),
 *             $attachments (list), $relationTypes (array), $catalog (list), $secret (array), $itemReadable (bool),
 *             $serviceCount (int|null), $rights (array), $module, $baseUrl, $e, $datetime.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$canUpdate = (bool) ($rights['update'] ?? false);
$canDelete = (bool) ($rights['delete'] ?? false);
$itemId = $item === null ? 0 : (int) $item['id'];
$infoId = $info === null ? null : (string) $info['id'];
$inlineMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain'];
?>
<div class="module module-demo">
    <div class="toolbar">
        <div class="field" style="width: 420px">
            <label class="sr-only" for="sh-item">Article</label>
            <select class="select" id="sh-item" data-route-select title="Le choix charge la route de l’option (select[data-route-select])">
                <option value="shared"<?= $item === null ? ' selected' : '' ?>>— Choisir un article —</option>
                <?php foreach ($items as $candidate): ?>
                    <option value="shared?item=<?= (int) $candidate['id'] ?>"<?= (int) $candidate['id'] === $itemId ? ' selected' : '' ?>>n° <?= (int) $candidate['id'] ?> — <?= $e($candidate['name']) ?> (<?= $e($candidate['category']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($item !== null): ?>
            <a class="btn btn--ghost" href="#" data-route="shared"><?= $icon('close') ?> Désélectionner</a>
        <?php endif; ?>
        <span class="toolbar__spacer"></span>
        <span class="text-small text-muted">Jeu de données : <code>demo.item</code> · lecture par le catalogue : <?= $itemReadable ? '<span class="badge badge--success">autorisée</span>' : '<span class="badge badge--danger">refusée</span>' ?></span>
    </div>

    <?php if ($item === null): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => 'Aucun article sélectionné',
            'message' => 'Choisissez un article ci-dessus : vous pourrez l’enregistrer dans le registre commun, le tagger, le relier à un autre article et lui joindre des fichiers.',
            'actions' => $items !== [] ? '<a class="btn btn--primary" href="#" data-route="shared?item=' . (int) $items[0]['id'] . '">' . $icon('tag') . ' Prendre le premier article</a>' : '',
        ]) ?>
    <?php else: ?>
        <div class="split">
            <div>
                <div class="card">
                    <div class="card__header"><h3 class="card__title"><?= $icon('file') ?> Article n° <?= $itemId ?></h3><?= $item['active'] ? '<span class="badge badge--success badge--dot">actif</span>' : '<span class="badge badge--muted badge--dot">inactif</span>' ?></div>
                    <div class="card__body">
                        <dl class="dl">
                            <dt>Nom</dt><dd><?= $e($item['name']) ?></dd>
                            <dt>Catégorie</dt><dd><?= $e($item['category']) ?></dd>
                            <dt>Quantité</dt><dd><?= (int) $item['quantity'] ?></dd>
                            <dt>Prix</dt><dd><?= $e(number_format($item['price'] / 100, 2, ',', ' ')) ?> €</dd>
                            <dt>Créé le</dt><dd><?= $e($datetime($item['created_at'])) ?></dd>
                        </dl>
                    </div>
                </div>

                <div class="card">
                    <div class="card__header"><h3 class="card__title"><?= $icon('hash') ?> Registre commun</h3><code>registry-&gt;register()</code></div>
                    <div class="card__body">
                        <?php if ($info === null): ?>
                            <p class="text-small text-muted">Cet article n’a pas encore d’identifiant global. Tags, relations et pièces jointes le créent automatiquement ; vous pouvez aussi l’attribuer explicitement.</p>
                            <button type="button" class="btn btn--primary" data-action="register" data-params='{"item": <?= $itemId ?>}' <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('plus') ?> Enregistrer dans le registre</button>
                        <?php else: ?>
                            <dl class="dl">
                                <dt>Identifiant global</dt><dd><code><?= $e($infoId) ?></code></dd>
                                <dt>Jeu de données</dt><dd><?= $e($info['dataset_code']) ?></dd>
                                <dt>Clé locale</dt><dd><?= $e($info['local_key']) ?></dd>
                                <dt>Libellé</dt><dd><?= $e($info['label'] ?? '') ?></dd>
                                <dt>Créé le</dt><dd><?= $e($datetime($info['created_at'] ?? null)) ?></dd>
                            </dl>
                            <p class="text-small text-muted mt-2 mb-0">L’identifiant est stable : renommer l’article met à jour le libellé, jamais l’identifiant.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card__header"><h3 class="card__title"><?= $icon('database') ?> Catalogue partagé</h3><code>catalog-&gt;shared()</code></div>
                    <div class="card__body card__body--flush">
                        <table class="table table--compact">
                            <thead><tr><th>Code</th><th>Nom</th><th>Module</th></tr></thead>
                            <tbody>
                            <?php foreach ($catalog as $dataset): ?>
                                <tr<?= ($dataset['code'] ?? '') === 'demo.item' ? ' class="is-selected"' : '' ?>>
                                    <td><code><?= $e($dataset['code'] ?? '') ?></code></td>
                                    <td><?= $e($dataset['name'] ?? '') ?></td>
                                    <td><span class="badge badge--muted"><?= $e($dataset['module_id'] ?? '') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($catalog === []): ?><tr><td colspan="3" class="table__empty">Aucun jeu partagé.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card__footer text-small">
                        <p class="mb-1"><strong>Preuve du jeu privé <code>demo.secret</code></strong> (déclaré <code>visibility: private</code>) :</p>
                        <ul class="mb-1">
                            <li>déclaré dans la table des jeux (<code>catalog-&gt;find</code>) : <?= $secret['declared'] ? '<span class="badge badge--success">oui</span>' : '<span class="badge badge--danger">non</span>' ?></li>
                            <li>présent dans le catalogue partagé (<code>isShared</code>) : <?= $secret['shared'] ? '<span class="badge badge--danger">oui — anomalie</span>' : '<span class="badge badge--success">non</span>' ?></li>
                            <li>lisible par l’API intermodule (<code>canAccess(…, 'read')</code>) : <?= $secret['readable'] ? '<span class="badge badge--danger">oui — anomalie</span>' : '<span class="badge badge--success">non</span>' ?></li>
                        </ul>
                        <p class="mb-0 text-muted">Service intermodule <code>moduleService('demo')-&gt;items()</code> : <?= $serviceCount === null ? 'accès refusé pour vous (ForbiddenException)' : $serviceCount . ' article(s) lisible(s)' ?>.</p>
                    </div>
                </div>
            </div>

            <div>
                <div class="card">
                    <div class="card__header"><h3 class="card__title"><?= $icon('tag') ?> Tags partagés</h3><code>tags-&gt;attach() / detach()</code></div>
                    <div class="card__body">
                        <?php if ($tags === []): ?>
                            <p class="text-muted text-small">Aucun tag. Les tags sont partagés entre modules (portée <code>shared</code>) : un tag « urgent » posé ici est le même que dans le Bloc-notes.</p>
                        <?php else: ?>
                            <div class="chips mb-3">
                                <?php foreach ($tags as $tag): ?>
                                    <span class="chip"><?= $icon('tag', 'icon--sm') ?><?= $e($tag['name']) ?>
                                        <button type="button" class="chip__remove" data-action="tag-detach" data-params='{"item": <?= $itemId ?>, "tag_id": <?= (int) $tag['id'] ?>}' title="Détacher" aria-label="Détacher le tag <?= $e($tag['name']) ?>" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('close', 'icon--sm') ?></button>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <form class="flex" data-action="tag-attach" novalidate>
                            <input type="hidden" name="item" value="<?= $itemId ?>">
                            <div class="field grow mb-0">
                                <label class="sr-only" for="sh-tag">Nouveau tag</label>
                                <div class="input-group">
                                    <input class="input" id="sh-tag" name="tag" type="text" maxlength="60" placeholder="Ajouter un tag (ex. urgent)" <?= $canUpdate ? '' : 'disabled' ?>>
                                    <button type="submit" class="btn" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('plus') ?> Attacher</button>
                                </div>
                                <span class="field__error"></span>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card__header"><h3 class="card__title"><?= $icon('link') ?> Relations typées</h3><code>relations-&gt;relate()</code></div>
                    <div class="card__body">
                        <?php if ($relations === []): ?>
                            <p class="text-muted text-small">Aucune relation. Une relation relie deux identifiants globaux, éventuellement de modules différents.</p>
                        <?php else: ?>
                            <ul class="list mb-3">
                                <?php foreach ($relations as $relation): ?>
                                    <li class="list__item">
                                        <?= $icon($relation['direction'] === 'out' ? 'arrow-up' : 'arrow-down', 'text-muted') ?>
                                        <span class="grow">
                                            <span class="badge badge--info"><?= $e($relation['type_label']) ?></span>
                                            <?= $relation['direction'] === 'out' ? '→' : '←' ?>
                                            <a href="#" data-route="shared?item=<?= $e($relation['other_key']) ?>"><?= $e($relation['other_label'] ?? ('#' . $relation['other_key'])) ?></a>
                                            <span class="text-muted text-small">(<?= $e($relation['other_dataset']) ?>)</span>
                                            <?php if (!empty($relation['comment'])): ?><span class="text-muted text-small">— <?= $e($relation['comment']) ?></span><?php endif; ?>
                                        </span>
                                        <button type="button" class="btn btn--sm btn--ghost btn--icon" data-action="unrelate" data-params='{"id": <?= (int) $relation['id'] ?>}' data-confirm="Supprimer cette relation ?" title="Supprimer" aria-label="Supprimer la relation" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('trash') ?></button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <form data-action="relate" novalidate>
                            <input type="hidden" name="item" value="<?= $itemId ?>">
                            <div class="form-grid">
                                <div class="field">
                                    <label class="field__label" for="sh-rel-type">Type</label>
                                    <select class="select" id="sh-rel-type" name="type" <?= $canUpdate ? '' : 'disabled' ?>>
                                        <?php foreach ($relationTypes as $code => $label): ?><option value="<?= $e($code) ?>"><?= $e($label) ?></option><?php endforeach; ?>
                                    </select>
                                    <span class="field__error"></span>
                                </div>
                                <div class="field">
                                    <label class="field__label" for="sh-rel-to">Article cible</label>
                                    <select class="select" id="sh-rel-to" name="to" <?= $canUpdate ? '' : 'disabled' ?>>
                                        <option value="">— Choisir —</option>
                                        <?php foreach ($items as $candidate): ?>
                                            <option value="<?= (int) $candidate['id'] ?>">n° <?= (int) $candidate['id'] ?> — <?= $e($candidate['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="field__help">Choisir l’article lui-même provoque une ValidationException du noyau.</span>
                                    <span class="field__error"></span>
                                </div>
                                <div class="field field--full">
                                    <label class="field__label" for="sh-rel-comment">Commentaire</label>
                                    <input class="input" id="sh-rel-comment" name="comment" type="text" maxlength="200" placeholder="Facultatif" <?= $canUpdate ? '' : 'disabled' ?>>
                                </div>
                            </div>
                            <div class="form-actions"><button type="submit" class="btn btn--primary" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('link') ?> Relier</button></div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card__header"><h3 class="card__title"><?= $icon('paperclip') ?> Pièces jointes</h3><code>attachments-&gt;store()</code></div>
                    <div class="card__body">
                        <?php if ($attachments === []): ?>
                            <p class="text-muted text-small">Aucune pièce jointe. Types acceptés : PDF, images JPEG/PNG/WebP, texte, CSV, documents Office/OpenDocument (20 Mo max). Les exécutables, scripts et archives sont refusés.</p>
                        <?php else: ?>
                            <div class="table-wrap mb-3">
                                <table class="table table--compact">
                                    <thead><tr><th>Fichier</th><th>Type</th><th class="col-num">Taille</th><th>Par</th><th>Le</th><th class="col-actions">Actions</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($attachments as $attachment): ?>
                                        <tr>
                                            <td><?= $icon('file', 'icon--sm text-muted') ?> <?= $e($attachment['original_name']) ?></td>
                                            <td><code><?= $e($attachment['mime']) ?></code></td>
                                            <td class="col-num"><?= $e(\Atelier\Support\Str::humanSize((int) $attachment['size'])) ?></td>
                                            <td><?= $e($attachment['uploader'] ?? '—') ?></td>
                                            <td class="text-nowrap text-muted"><?= $e($datetime($attachment['created_at'])) ?></td>
                                            <td class="col-actions">
                                                <span class="table-actions">
                                                    <a class="btn btn--sm btn--ghost btn--icon" href="<?= $e($baseUrl) ?>/files/<?= $e($attachment['id']) ?>" download title="Télécharger (/files/{id})" aria-label="Télécharger"><?= $icon('download') ?></a>
                                                    <?php if (in_array($attachment['mime'], $inlineMimes, true)): ?>
                                                        <a class="btn btn--sm btn--ghost btn--icon" href="<?= $e($baseUrl) ?>/files/<?= $e($attachment['id']) ?>?inline=1" target="_blank" rel="noopener" title="Afficher dans le navigateur (?inline=1)" aria-label="Afficher"><?= $icon('eye') ?></a>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn--sm btn--ghost btn--icon" data-action="attachment-delete" data-params='{"id": "<?= $e($attachment['id']) ?>"}' data-confirm="Supprimer « <?= $e($attachment['original_name']) ?> » ? (suppression logique, purge par la maintenance)" data-danger title="Supprimer" aria-label="Supprimer" <?= $canDelete ? '' : 'disabled' ?>><?= $icon('trash') ?></button>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <form data-action="upload" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="item" value="<?= $itemId ?>">
                            <div class="field">
                                <label class="field__label" for="sh-file">Joindre un fichier</label>
                                <div class="input-group">
                                    <input class="input" id="sh-file" name="file" type="file" <?= $canUpdate ? '' : 'disabled' ?>>
                                    <button type="submit" class="btn btn--primary" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('upload') ?> Téléverser</button>
                                </div>
                                <span class="field__help">Le formulaire contient un champ fichier : le noyau envoie un <code>FormData</code> (multipart) et le serveur lit <code>$request-&gt;file('file')</code>.</span>
                                <span class="field__error"></span>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
