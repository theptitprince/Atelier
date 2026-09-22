<?php
/**
 * Détail d'une information du registre : identité, tags, relations, pièces jointes, historique.
 * Variables : $info (array décoré), $creator (array|null), $canUpdate (bool), $tags (list), $relations (list),
 *             $relationTypes (array), $attachments (list), $inlineMimes (list), $history (list), $historyTotal (int),
 *             $module, $baseUrl, $e, $datetime.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$infoId = (string) $info['id'];
$infoJson = json_encode($infoId, JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<div class="module module-explorer">
    <div class="toolbar">
        <a class="btn btn--ghost" href="#" data-route="search"><?= $icon('chevron-left') ?> Recherche</a>
        <span class="toolbar__spacer"></span>
        <?php if (!$canUpdate): ?>
            <span class="badge badge--muted" title="Vous n’avez pas le droit « update » sur ce jeu de données"><?= $icon('lock', 'icon--sm') ?> lecture seule</span>
        <?php endif; ?>
    </div>

    <div class="split explorer__detail">
        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('file') ?> Information</h3></div>
                <div class="card__body">
                    <dl class="dl">
                        <dt>Libellé</dt><dd><?= $e($info['label'] ?? '—') ?></dd>
                        <dt>Jeu de données</dt><dd><a href="#" data-route="search?dataset=<?= $e(rawurlencode($info['dataset_code'])) ?>"><?= $e($info['dataset_name']) ?></a> <code class="text-small"><?= $e($info['dataset_code']) ?></code></dd>
                        <dt>Module</dt><dd><a href="#" data-route="search?module=<?= $e(rawurlencode($info['module_id'])) ?>"><?= $e($info['module_name']) ?></a></dd>
                        <dt>Clé locale</dt><dd><code><?= $e($info['local_key']) ?></code></dd>
                        <dt>Identifiant global</dt><dd><code class="text-small"><?= $e($infoId) ?></code></dd>
                        <dt>Créé par</dt><dd><?= $creator === null ? '<span class="text-muted">—</span>' : $e($creator['display_name'] ?? $creator['username']) ?></dd>
                        <dt>Créé le</dt><dd><?= $e($datetime($info['created_at'])) ?></dd>
                    </dl>
                    <?php if ($info['open_route'] !== null): ?>
                        <a class="btn btn--primary mt-2" href="#" data-open-module="<?= $e($info['module_id']) ?>" data-open-route="<?= $e($info['open_route']) ?>"><?= $icon('external') ?> Ouvrir dans le module</a>
                    <?php else: ?>
                        <p class="text-small text-muted mt-2 mb-0">Le module <?= $e($info['module_name']) ?> ne déclare pas de route d’ouverture (<code>openRoute</code>) pour ce jeu de données.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('tag') ?> Tags</h3><span class="badge badge--muted"><?= count($tags) ?></span></div>
                <div class="card__body">
                    <?php if ($tags === []): ?>
                        <p class="text-small text-muted">Aucun tag.</p>
                    <?php else: ?>
                        <div class="chips mb-2">
                            <?php foreach ($tags as $tag): ?>
                                <span class="chip">
                                    <a class="explorer__chip-link" href="#" data-route="search?tag=<?= $e(rawurlencode($tag['name'])) ?>" title="Rechercher ce tag"><?= $icon('tag', 'icon--sm') ?><?= $e($tag['name']) ?></a>
                                    <?php if ($canUpdate): ?>
                                        <button type="button" class="chip__remove" data-action="tag-remove" data-params='{"info": <?= $infoJson ?>, "tag_id": <?= (int) $tag['id'] ?>}' title="Retirer" aria-label="Retirer le tag <?= $e($tag['name']) ?>"><?= $icon('close', 'icon--sm') ?></button>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($canUpdate): ?>
                        <form data-action="tag-add" novalidate>
                            <input type="hidden" name="info" value="<?= $e($infoId) ?>">
                            <div class="field mb-2">
                                <label class="sr-only" for="explorer-tags">Ajouter des tags</label>
                                <input class="input" type="text" id="explorer-tags" name="tags" value="" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="10">
                                <span class="field__help">Tags communs à toute l’application (60 caractères maximum). Entrée ou virgule ajoute une puce.</span>
                                <span class="field__error"></span>
                            </div>
                            <button type="submit" class="btn"><?= $icon('plus') ?> Ajouter</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('activity') ?> Historique</h3><span class="badge badge--muted"><?= (int) $historyTotal ?></span></div>
                <div class="card__body card__body--flush">
                    <?php if ($history === []): ?>
                        <p class="text-small text-muted explorer__pad">Aucune entrée du journal ne référence cette information.</p>
                    <?php else: ?>
                        <ul class="list explorer__history">
                            <?php foreach ($history as $entry): ?>
                                <li class="list__item">
                                    <span class="grow">
                                        <span class="text-small text-muted text-nowrap"><?= $e($datetime($entry['occurred_at'])) ?></span>
                                        <code class="text-small"><?= $e($entry['action']) ?></code>
                                        <?php if (!empty($entry['message'])): ?><span class="text-small">— <?= $e($entry['message']) ?></span><?php endif; ?>
                                        <?php if (!empty($entry['username'])): ?><span class="text-small text-muted">(<?= $e($entry['username']) ?>)</span><?php endif; ?>
                                    </span>
                                    <span class="badge badge--<?= $entry['result'] === 'success' ? 'success' : ($entry['result'] === 'denied' ? 'danger' : 'warning') ?>"><?= $e($entry['result']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('link') ?> Relations</h3><span class="badge badge--muted"><?= count($relations) ?></span></div>
                <div class="card__body">
                    <?php if ($relations === []): ?>
                        <p class="text-small text-muted">Aucune relation.</p>
                    <?php else: ?>
                        <ul class="list mb-3">
                            <?php foreach ($relations as $relation): ?>
                                <li class="list__item">
                                    <?= $icon($relation['direction'] === 'out' ? 'arrow-up' : 'arrow-down', 'text-muted') ?>
                                    <span class="grow">
                                        <span class="badge badge--info"><?= $e($relation['type_label']) ?></span>
                                        <?= $relation['direction'] === 'out' ? '→' : '←' ?>
                                        <?php if ($relation['other_visible']): ?>
                                            <a href="#" data-route="info/<?= $e($relation['other_id']) ?>"><?= $e($relation['other_label'] ?? ('#' . $relation['other_key'])) ?></a>
                                            <span class="text-muted text-small">(<?= $e($relation['other_dataset']) ?>)</span>
                                        <?php else: ?>
                                            <span class="text-muted" title="Le jeu de données de cette information est privé ou hors de vos droits"><?= $icon('lock', 'icon--sm') ?> information non accessible</span>
                                        <?php endif; ?>
                                        <?php if (!empty($relation['comment'])): ?><span class="text-muted text-small">— <?= $e($relation['comment']) ?></span><?php endif; ?>
                                    </span>
                                    <?php if ($canUpdate && $relation['direction'] === 'out' && $relation['other_visible']): ?>
                                        <button type="button" class="btn btn--sm btn--ghost btn--icon" data-action="unrelate" data-params='{"id": <?= (int) $relation['id'] ?>}' data-confirm="Supprimer cette relation ?" title="Supprimer" aria-label="Supprimer la relation"><?= $icon('trash') ?></button>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($canUpdate): ?>
                        <form data-action="relate" class="explorer__relate" novalidate data-explorer-relate>
                            <input type="hidden" name="info" value="<?= $e($infoId) ?>">
                            <div class="form-grid">
                                <div class="field">
                                    <label class="field__label" for="explorer-rel-type">Type</label>
                                    <select class="select" id="explorer-rel-type" name="type">
                                        <?php foreach ($relationTypes as $code => $label): ?><option value="<?= $e($code) ?>"><?= $e($label) ?></option><?php endforeach; ?>
                                    </select>
                                    <span class="field__error"></span>
                                </div>
                                <div class="field">
                                    <label class="field__label" for="explorer-rel-search">Information cible</label>
                                    <input class="input" type="search" id="explorer-rel-search" placeholder="Rechercher par libellé…" autocomplete="off" data-explorer-lookup data-exclude="<?= $e($infoId) ?>">
                                    <span class="field__help" data-explorer-lookup-hint>Saisissez au moins un caractère : seules les informations visibles sont proposées.</span>
                                </div>
                                <div class="field field--full">
                                    <label class="field__label" for="explorer-rel-to">Cible</label>
                                    <select class="select" id="explorer-rel-to" name="to" data-explorer-target>
                                        <option value="">— Recherchez une information ci-dessus —</option>
                                    </select>
                                    <span class="field__error"></span>
                                </div>
                                <div class="field field--full">
                                    <label class="field__label" for="explorer-rel-comment">Commentaire</label>
                                    <input class="input" id="explorer-rel-comment" name="comment" type="text" maxlength="200" placeholder="Facultatif">
                                    <span class="field__error"></span>
                                </div>
                            </div>
                            <div class="form-actions"><button type="submit" class="btn btn--primary"><?= $icon('link') ?> Relier</button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card__header"><h3 class="card__title"><?= $icon('paperclip') ?> Pièces jointes</h3><span class="badge badge--muted"><?= count($attachments) ?></span></div>
                <div class="card__body">
                    <?php if ($attachments === []): ?>
                        <p class="text-small text-muted">Aucune pièce jointe.</p>
                    <?php else: ?>
                        <div class="table-wrap mb-3">
                            <table class="table table--compact">
                                <thead><tr><th>Fichier</th><th>Type</th><th class="col-num">Taille</th><th>Par</th><th>Le</th><th class="col-actions">Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($attachments as $attachment): ?>
                                    <tr>
                                        <td><?= $icon('file', 'icon--sm text-muted') ?> <?= $e($attachment['original_name']) ?><?php if (!empty($attachment['description'])): ?><br><span class="text-small text-muted"><?= $e($attachment['description']) ?></span><?php endif; ?></td>
                                        <td><code class="text-small"><?= $e($attachment['mime']) ?></code></td>
                                        <td class="col-num text-nowrap"><?= $e(\Atelier\Support\Str::humanSize((int) $attachment['size'])) ?></td>
                                        <td><?= $e($attachment['uploader'] ?? '—') ?></td>
                                        <td class="text-nowrap text-muted"><?= $e($datetime($attachment['created_at'])) ?></td>
                                        <td class="col-actions">
                                            <span class="table-actions">
                                                <a class="btn btn--sm btn--ghost btn--icon" href="<?= $e($baseUrl) ?>/files/<?= $e($attachment['id']) ?>" download title="Télécharger" aria-label="Télécharger"><?= $icon('download') ?></a>
                                                <?php if (in_array($attachment['mime'], $inlineMimes, true)): ?>
                                                    <a class="btn btn--sm btn--ghost btn--icon" href="<?= $e($baseUrl) ?>/files/<?= $e($attachment['id']) ?>?inline=1" target="_blank" rel="noopener" title="Afficher dans le navigateur" aria-label="Afficher"><?= $icon('eye') ?></a>
                                                <?php endif; ?>
                                                <?php if ($canUpdate): ?>
                                                    <button type="button" class="btn btn--sm btn--ghost btn--icon" data-action="attachment-delete" data-params='{"id": <?= json_encode((string) $attachment['id'], JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>}' data-confirm="Supprimer « <?= $e($attachment['original_name']) ?> » ? (suppression logique, purge par la maintenance)" data-danger title="Supprimer" aria-label="Supprimer"><?= $icon('trash') ?></button>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php if ($canUpdate): ?>
                        <form data-action="upload" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="info" value="<?= $e($infoId) ?>">
                            <div class="field">
                                <label class="field__label" for="explorer-file">Joindre un fichier</label>
                                <div class="input-group">
                                    <input class="input" id="explorer-file" name="file" type="file">
                                    <button type="submit" class="btn btn--primary"><?= $icon('upload') ?> Téléverser</button>
                                </div>
                                <span class="field__help">PDF, images JPEG/PNG/WebP, texte, CSV, documents bureautiques ; 20 Mo maximum. Exécutables, scripts et archives refusés.</span>
                                <span class="field__error"></span>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
