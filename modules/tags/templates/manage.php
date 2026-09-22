<?php
/**
 * Gestion des tags partagés : sélection multiple (fusion, suppression), doublons probables, tags inutilisés.
 * Variables : $tags (list décorée : usage_count, level, author), $unused (list), $duplicates (list de paires
 *             key/source/target), $module, $e, $datetime.
 */
?>
<div class="module module-tags">
    <?php if ($duplicates !== []): ?>
        <div class="card">
            <div class="card__header">
                <h3 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-copy"></use></svg> Doublons probables</h3>
                <span class="badge badge--warning"><?= count($duplicates) ?></span>
            </div>
            <div class="card__body card__body--flush">
                <p class="text-muted text-small tags__hint">Tags dont la forme normalisée ne diffère que par les accents, un « s » final ou des séparateurs. La fusion proposée conserve le tag le plus utilisé.</p>
                <div class="table-wrap mb-0">
                    <table class="table table--compact">
                        <thead>
                        <tr>
                            <th>À fusionner</th>
                            <th class="col-num">Usages</th>
                            <th>Dans</th>
                            <th class="col-num">Usages</th>
                            <th class="col-actions">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($duplicates as $pair): ?>
                            <tr>
                                <td><a class="chip" href="#" data-route="detail/<?= (int) $pair['source']['id'] ?>"><?= $e($pair['source']['name']) ?></a></td>
                                <td class="col-num"><?= (int) $pair['source']['usage_count'] ?></td>
                                <td><a class="chip" href="#" data-route="detail/<?= (int) $pair['target']['id'] ?>"><?= $e($pair['target']['name']) ?></a></td>
                                <td class="col-num"><?= (int) $pair['target']['usage_count'] ?></td>
                                <td class="col-actions">
                                    <button type="button" class="btn btn--sm" data-action="merge" data-params='{"id":<?= (int) $pair['source']['id'] ?>,"target_id":<?= (int) $pair['target']['id'] ?>}' data-confirm="Fusionner « <?= $e($pair['source']['name']) ?> » dans « <?= $e($pair['target']['name']) ?> » ? Le premier sera supprimé." data-confirm-title="Fusionner deux tags" data-confirm-label="Fusionner">
                                        <svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg> Fusionner
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card__header">
            <h3 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Tags inutilisés</h3>
            <span class="badge <?= $unused === [] ? 'badge--success' : 'badge--muted' ?>"><?= count($unused) ?></span>
            <?php if ($unused !== []): ?>
                <button type="button" class="btn btn--sm btn--outline-danger" data-action="delete-unused" data-confirm="Supprimer les <?= count($unused) ?> tags inutilisés ? Cette action est irréversible." data-confirm-title="Supprimer les tags inutilisés" data-confirm-label="Supprimer" data-danger>
                    <svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer les tags inutilisés
                </button>
            <?php endif; ?>
        </div>
        <div class="card__body">
            <?php if ($unused === []): ?>
                <p class="text-muted mb-0">Tous les tags sont utilisés par au moins une information.</p>
            <?php else: ?>
                <div class="chips">
                    <?php foreach ($unused as $tag): ?>
                        <a class="chip" href="#" data-route="detail/<?= (int) $tag['id'] ?>"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg><?= $e($tag['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header">
            <h3 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-tag"></use></svg> Tous les tags</h3>
            <span class="badge"><?= count($tags) ?></span>
        </div>
        <?php if ($tags === []): ?>
            <div class="card__body">
                <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun tag partagé', 'message' => 'Les tags sont créés depuis les modules qui en utilisent.']) ?>
            </div>
        <?php else: ?>
            <form data-action="merge-many" data-confirm="Fusionner les tags sélectionnés dans le tag cible ? Les tags sélectionnés seront supprimés et leurs utilisations reportées." novalidate>
                <div class="card__body card__body--flush">
                    <div class="table-wrap mb-0">
                        <table class="table table--compact tags__table" data-selection>
                            <thead>
                            <tr>
                                <th class="col-check"><label class="checkbox"><input type="checkbox" data-select-all aria-label="Tout sélectionner"></label></th>
                                <th>Tag</th>
                                <th class="col-num">Utilisations</th>
                                <th class="tags__col-date">Créé le</th>
                                <th>Auteur</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tags as $tag): ?>
                                <tr<?= (int) $tag['usage_count'] === 0 ? ' class="tags__row--unused"' : '' ?>>
                                    <td class="col-check"><label class="checkbox"><input type="checkbox" name="ids[]" value="<?= (int) $tag['id'] ?>" data-select-item aria-label="Sélectionner <?= $e($tag['name']) ?>"></label></td>
                                    <td>
                                        <a class="tags__name" href="#" data-route="detail/<?= (int) $tag['id'] ?>">
                                            <svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg>
                                            <span><?= $e($tag['name']) ?></span>
                                        </a>
                                    </td>
                                    <td class="col-num"><?= (int) $tag['usage_count'] === 0 ? '<span class="badge badge--muted">inutilisé</span>' : (int) $tag['usage_count'] ?></td>
                                    <td class="tags__col-date text-nowrap"><?= $e($datetime($tag['created_at'] ?? null)) ?></td>
                                    <td><?= $e($tag['author']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card__footer tags__bulk">
                    <span class="text-muted text-small" data-selection-count>Aucun tag sélectionné</span>
                    <span class="toolbar__spacer"></span>
                    <div class="field field--inline tags__bulk-target">
                        <label class="field__label" for="tags-bulk-target">Fusionner la sélection dans</label>
                        <input class="input input--sm" type="text" id="tags-bulk-target" name="target" value="" placeholder="Tag cible…" autocomplete="off" data-tags-input data-tags-max="1">
                    </div>
                    <button type="submit" class="btn btn--primary btn--sm" data-needs-selection disabled>
                        <svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg> Fusionner
                    </button>
                    <button type="button" class="btn btn--outline-danger btn--sm" data-action="delete-many" data-params='{"ids":[]}' data-delete-selected data-needs-selection data-confirm="Supprimer les tags sélectionnés ? Ils seront retirés de toutes les informations." data-confirm-title="Supprimer la sélection" data-confirm-label="Supprimer" data-danger disabled>
                        <svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer la sélection
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
