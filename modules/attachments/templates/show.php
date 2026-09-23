<?php
/**
 * Détail d'un fichier joint : métadonnées, aperçu, tags, dossier, rattachement, actions.
 * @var array<string, mixed> $attachment
 * @var string $displayName
 * @var string $kind
 * @var array<string, array{0: string, 1: list<string>}> $kinds
 * @var bool $canDownload @var bool $canManage @var bool $canUpdate @var bool $canDelete
 * @var bool $inline
 * @var string $downloadUrl
 * @var bool $encryption
 * @var bool $openModule
 * @var list<string> $tags
 * @var list<array<string, mixed>> $folderPath
 * @var list<array{id: int, name: string, path: string, depth: int}> $folders
 * @var string $listRoute
 * @var \Atelier\Modules\Attachments\AttachmentsModule $module
 */
$id = (string) $attachment['id'];
$dash = '<span class="text-muted">—</span>';
$inTrash = $attachment['deleted_at'] !== null;
$hasLabel = $displayName !== (string) $attachment['original_name'];
$folderId = $attachment['folder_id'] !== null ? (int) $attachment['folder_id'] : null;
?>
<div class="module module-attachments">
    <?php if ($inTrash): ?>
        <div class="alert alert--warning" role="status"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg><div>Ce fichier est dans la corbeille depuis le <?= $e($datetime($attachment['deleted_at'])) ?>. Il n’est plus téléchargeable jusqu’à sa restauration.</div></div>
    <?php endif; ?>
    <div class="attachments__show-layout">
        <div>
            <section class="card" aria-labelledby="att-meta-title">
                <div class="card__header">
                    <h2 class="card__title" id="att-meta-title"><svg class="icon" aria-hidden="true"><use href="#i-<?= $e($module::kindIcon((string) $attachment['mime'])) ?>"></use></svg> Fichier</h2>
                    <span class="badge badge--muted"><?= $e($kinds[$kind][0] ?? $kind) ?></span>
                </div>
                <div class="card__body">
                    <dl class="dl attachments__detail">
                        <dt>Nom d’affichage</dt><dd><strong><?= $e($displayName) ?></strong><?= $hasLabel ? '' : ' <span class="text-muted text-small">(nom du fichier)</span>' ?></dd>
                        <dt>Nom du fichier</dt><dd><?= $e($attachment['original_name']) ?> <span class="text-muted text-small">(nom utilisé au téléchargement)</span></dd>
                        <dt>Description</dt><dd><?= $attachment['description'] !== null && $attachment['description'] !== '' ? $e($attachment['description']) : $dash ?></dd>
                        <dt>Dossier</dt>
                        <dd>
                            <?php if ($folderPath === []): ?>
                                <span class="text-muted">Non rangé (racine)</span>
                            <?php else: ?>
                                <?php foreach ($folderPath as $i => $crumb): ?>
                                    <?= $i > 0 ? ' <span class="text-muted">›</span> ' : '' ?><a href="#" data-route="list?folder=<?= (int) $crumb['id'] ?>"><?= $e($crumb['name']) ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </dd>
                        <dt>Tags</dt>
                        <dd>
                            <?php if ($tags === []): ?>
                                <?= $dash ?>
                            <?php else: ?>
                                <span class="flex flex--wrap attachments__chips">
                                    <?php foreach ($tags as $tag): ?>
                                        <a class="chip" href="#" data-route="list?tag=<?= $e(rawurlencode($tag)) ?>" title="Fichiers portant le tag <?= $e($tag) ?>"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg><?= $e($tag) ?></a>
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                        </dd>
                        <dt>Type</dt><dd><code><?= $e($attachment['mime']) ?></code></dd>
                        <dt>Taille</dt><dd><?= $e($module->humanSize((int) $attachment['size'])) ?> <span class="text-muted text-small">(<?= number_format((int) $attachment['size'], 0, ',', ' ') ?> octets)</span></dd>
                        <dt>Empreinte SHA-256</dt><dd class="mono text-small attachments__hash"><?= $e($attachment['sha256']) ?></dd>
                        <dt>Chiffrement</dt>
                        <dd>
                            <?php if ($attachment['cipher'] !== null): ?>
                                <span class="badge badge--success"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-lock"></use></svg> <?= $e(strtoupper((string) $attachment['cipher'])) ?></span> <span class="text-muted text-small">clé <?= $e($attachment['key_id']) ?></span>
                            <?php else: ?>
                                <span class="badge badge--warning"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-unlock"></use></svg> en clair</span>
                                <?php if ($encryption): ?><span class="text-muted text-small">— lancez <code>console attachments:encrypt</code> pour le chiffrer</span><?php endif; ?>
                            <?php endif; ?>
                        </dd>
                        <dt>Téléversé</dt><dd><?= $e($datetime($attachment['created_at'])) ?><?= $attachment['uploader'] !== null ? ' par ' . $e($attachment['uploader_name'] ?? $attachment['uploader']) : '' ?></dd>
                        <dt>Téléchargements</dt><dd><?= (int) $attachment['downloads'] ?><?= $attachment['last_downloaded_at'] !== null ? ' <span class="text-muted text-small">(dernier : ' . $e($datetime($attachment['last_downloaded_at'])) . ')</span>' : '' ?></dd>
                        <dt>Identifiant</dt><dd><code><?= $e($id) ?></code></dd>
                    </dl>
                </div>
                <div class="card__footer">
                    <div class="toolbar mb-0">
                        <?php if ($canDownload && !$inTrash): ?>
                            <a class="btn btn--sm btn--primary" href="<?= $e($downloadUrl) ?>" download title="Télécharge « <?= $e($attachment['original_name']) ?> »"><svg class="icon" aria-hidden="true"><use href="#i-download"></use></svg> Télécharger</a>
                            <?php if ($inline): ?>
                                <a class="btn btn--sm" href="<?= $e($downloadUrl . '?inline=1') ?>" target="_blank" rel="noopener noreferrer"><svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg> Ouvrir dans un onglet <svg class="icon icon--sm" aria-hidden="true"><use href="#i-external"></use></svg></a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <button type="button" class="btn btn--sm" data-action="verify" data-params='{"id":"<?= $e($id) ?>"}' title="Déchiffre le fichier et compare son empreinte"><svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg> Vérifier l’intégrité</button>
                        <span class="toolbar__spacer"></span>
                        <?php if ($canDelete): ?>
                            <?php if ($inTrash): ?>
                                <button type="button" class="btn btn--sm" data-action="restore" data-params='{"id":"<?= $e($id) ?>"}'><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Restaurer</button>
                                <button type="button" class="btn btn--sm btn--outline-danger" data-action="purge" data-params='{"id":"<?= $e($id) ?>"}' data-confirm="Supprimer définitivement ce fichier ? Cette action est irréversible." data-danger><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer définitivement</button>
                            <?php else: ?>
                                <button type="button" class="btn btn--sm btn--outline-danger" data-action="delete" data-params='{"id":"<?= $e($id) ?>"}' data-confirm="Mettre « <?= $e($displayName) ?> » à la corbeille ?" data-danger><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php if ($canDownload && !$inTrash && $kind === 'image'): ?>
                <section class="card" aria-labelledby="att-preview-title">
                    <div class="card__header"><h2 class="card__title" id="att-preview-title"><svg class="icon" aria-hidden="true"><use href="#i-image"></use></svg> Aperçu</h2></div>
                    <div class="card__body attachments__preview">
                        <img src="<?= $e($downloadUrl . '?inline=1') ?>" alt="Aperçu de <?= $e($displayName) ?>" loading="lazy">
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <div>
            <?php if ($canUpdate && !$inTrash): ?>
                <section class="card" aria-labelledby="att-rename-title">
                    <div class="card__header"><h2 class="card__title" id="att-rename-title"><svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg> Renommer</h2></div>
                    <form class="card__body" data-action="rename" data-track-dirty data-save-shortcut novalidate>
                        <input type="hidden" name="id" value="<?= $e($id) ?>">
                        <div class="field">
                            <label class="field__label" for="att-label">Nom d’affichage</label>
                            <input class="input" id="att-label" name="label" value="<?= $e($attachment['label'] ?? '') ?>" maxlength="200" placeholder="facultatif : nom lisible affiché dans les listes">
                            <span class="field__help">Vide : le nom du fichier est affiché. Le téléchargement conserve toujours le nom du fichier.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="att-name">Nom du fichier</label>
                            <input class="input" id="att-name" name="name" value="<?= $e($attachment['original_name']) ?>" maxlength="200" required>
                            <span class="field__help">L’extension doit rester identique.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="att-desc">Description</label>
                            <textarea class="textarea" id="att-desc" name="description" rows="3" maxlength="500"><?= $e($attachment['description'] ?? '') ?></textarea>
                            <span class="field__error"></span>
                        </div>
                        <div class="form-actions form-actions--end">
                            <button type="submit" class="btn btn--sm btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer</button>
                        </div>
                    </form>
                </section>

                <section class="card" aria-labelledby="att-tags-title">
                    <div class="card__header"><h2 class="card__title" id="att-tags-title"><svg class="icon" aria-hidden="true"><use href="#i-tag"></use></svg> Tags</h2></div>
                    <form class="card__body" data-action="tags-save" data-track-dirty novalidate>
                        <input type="hidden" name="id" value="<?= $e($id) ?>">
                        <div class="field">
                            <label class="field__label" for="att-tags">Tags partagés</label>
                            <input class="input" type="text" id="att-tags" name="tags" value="<?= $e(implode(', ', $tags)) ?>" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20">
                            <span class="field__help">Les tags existants sont proposés pendant la saisie ; Entrée ou virgule ajoute le tag. Ils sont communs à toute l’application.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="form-actions form-actions--end">
                            <button type="submit" class="btn btn--sm btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer les tags</button>
                        </div>
                    </form>
                </section>

                <section class="card" aria-labelledby="att-folder-title">
                    <div class="card__header"><h2 class="card__title" id="att-folder-title"><svg class="icon" aria-hidden="true"><use href="#i-folder"></use></svg> Dossier</h2></div>
                    <form class="card__body" data-action="move" novalidate>
                        <input type="hidden" name="id" value="<?= $e($id) ?>">
                        <div class="field">
                            <label class="field__label" for="att-folder">Déplacer vers</label>
                            <select class="select" id="att-folder" name="folder_id">
                                <option value=""<?= $folderId === null ? ' selected' : '' ?>>Racine (non rangés)</option>
                                <?php foreach ($folders as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"<?= $option['id'] === $folderId ? ' selected' : '' ?>><?= $e(str_repeat('— ', $option['depth']) . $option['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field__help">Les dossiers sont virtuels : le fichier ne bouge pas sur le disque.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="form-actions form-actions--end">
                            <button type="submit" class="btn btn--sm btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-folder"></use></svg> Déplacer</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <section class="card" aria-labelledby="att-link-title">
                <div class="card__header"><h2 class="card__title" id="att-link-title"><svg class="icon" aria-hidden="true"><use href="#i-link"></use></svg> Rattachement</h2></div>
                <div class="card__body">
                    <?php if ($attachment['info_id'] !== null): ?>
                        <p>
                            <strong><?= $e($attachment['info_label'] !== null && $attachment['info_label'] !== '' ? $attachment['info_label'] : $attachment['info_dataset'] . ' #' . $attachment['info_key']) ?></strong><br>
                            <span class="text-muted text-small">module <?= $e($attachment['info_module']) ?> · jeu <?= $e($attachment['info_dataset']) ?></span>
                        </p>
                        <div class="flex flex--wrap">
                            <?php if ($openModule): ?>
                                <a class="btn btn--sm" href="#" data-open-module="<?= $e($attachment['info_module']) ?>"<?= $attachment['info_dataset'] === 'geo.point' ? ' data-open-route="show/' . $e($attachment['info_key']) . '"' : '' ?>><svg class="icon" aria-hidden="true"><use href="#i-external"></use></svg> Ouvrir</a>
                            <?php endif; ?>
                            <?php if ($canUpdate && !$inTrash): ?>
                                <button type="button" class="btn btn--sm btn--ghost" data-action="unlink" data-params='{"id":"<?= $e($id) ?>"}' data-confirm="Retirer le rattachement ? Le fichier ne sera plus visible depuis cette information."><svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg> Retirer</button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted<?= $canUpdate && !$inTrash ? '' : ' mb-0' ?>">Fichier non rattaché : seul son auteur (et l’assistance) peut le consulter. Il peut être rattaché à une information à tout moment.</p>
                    <?php endif; ?>
                    <?php if ($canUpdate && !$inTrash): ?>
                        <form class="mt-3" data-action="link" novalidate data-attachments-link>
                            <input type="hidden" name="id" value="<?= $e($id) ?>">
                            <input type="hidden" name="info_id" value="" data-attachments-link-id>
                            <div class="field mb-0">
                                <label class="field__label" for="att-show-link-search"><?= $attachment['info_id'] !== null ? 'Changer de rattachement' : 'Rattacher à une information' ?></label>
                                <div class="input-icon">
                                    <svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg>
                                    <input class="input input--sm" type="search" id="att-show-link-search" placeholder="Rechercher (2 caractères minimum)" autocomplete="off" data-attachments-link-search>
                                </div>
                                <span class="field__error"></span>
                            </div>
                            <ul class="list attachments__link-results" data-attachments-link-results hidden></ul>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
