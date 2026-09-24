<?php
/**
 * Détail d'un tag partagé : métadonnées, actions de gestion, informations portant le tag, tags voisins.
 * Variables : $tag (array), $usage (int, informations vivantes), $totalUsage (int, corbeille comprise), $author (string), $infos (list : label, dataset_name, module_id,
 *             module_name, open_route|null), $hidden (int), $neighbors (list : id, name, shared_count),
 *             $others (list des autres tags, si gestion), $canManage (bool), $module, $e, $datetime.
 */
$tagId = (int) $tag['id'];
?>
<div class="module module-tags">
    <div class="split tags__detail">
        <div>
            <div class="card">
                <div class="card__header">
                    <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-tag"></use></svg> <?= $e($tag['name']) ?></h2>
                </div>
                <div class="card__body">
                    <dl class="dl">
                        <dt>Nom</dt>
                        <dd><span class="chip"><?= $e($tag['name']) ?></span></dd>
                        <dt>Utilisations</dt>
                        <dd><?= (int) $usage ?></dd>
                        <dt>Créé le</dt>
                        <dd><?= $e($datetime($tag['created_at'] ?? null) ?: '—') ?></dd>
                        <dt>Auteur</dt>
                        <dd><?= $e($author) ?></dd>
                    </dl>
                </div>
                <?php if ($canManage): ?>
                    <div class="card__footer tags__actions">
                        <button type="button" class="btn btn--sm" data-action="rename" data-params='{"id":<?= $tagId ?>}' data-prompt="Nouveau nom du tag" data-prompt-field="name" data-prompt-value="<?= $e($tag['name']) ?>" data-confirm-title="Renommer le tag">
                            <svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg> Renommer
                        </button>
                        <button type="button" class="btn btn--sm btn--outline-danger" data-action="delete" data-params='{"id":<?= $tagId ?>}' data-confirm="Supprimer le tag « <?= $e($tag['name']) ?> » ? Il sera retiré de <?= (int) $totalUsage ?> information<?= $totalUsage > 1 ? 's' : '' ?><?= $totalUsage > $usage ? ', dont ' . ($totalUsage - $usage) . ' en corbeille' : '' ?>." data-confirm-title="Supprimer le tag" data-confirm-label="Supprimer" data-danger>
                            <svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($canManage): ?>
                <div class="card card--compact">
                    <div class="card__header">
                        <h3 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg> Fusionner dans un autre tag</h3>
                    </div>
                    <div class="card__body">
                        <?php if ($others === []): ?>
                            <p class="text-muted mb-0">Aucun autre tag partagé : la fusion n’est pas possible.</p>
                        <?php else: ?>
                            <form data-action="merge" data-confirm="Fusionner « <?= $e($tag['name']) ?> » dans le tag cible ? Le tag « <?= $e($tag['name']) ?> » sera supprimé et ses <?= (int) $totalUsage ?> utilisation<?= $totalUsage > 1 ? 's' : '' ?> reportée<?= $totalUsage > 1 ? 's' : '' ?>." novalidate>
                                <input type="hidden" name="id" value="<?= $tagId ?>">
                                <div class="field">
                                    <label class="field__label" for="tags-merge-target-<?= $tagId ?>">Tag cible</label>
                                    <input class="input" type="text" id="tags-merge-target-<?= $tagId ?>" name="target" value="" placeholder="Choisir le tag cible…" autocomplete="off" data-tags-input data-tags-max="1">
                                    <p class="field__help">Les informations portant « <?= $e($tag['name']) ?> » recevront le tag cible ; « <?= $e($tag['name']) ?> » disparaîtra.</p>
                                </div>
                                <div class="form-actions form-actions--end">
                                    <button type="submit" class="btn btn--primary btn--sm"><svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg> Fusionner</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card card--compact">
                <div class="card__header">
                    <h3 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-link"></use></svg> Tags voisins</h3>
                </div>
                <div class="card__body">
                    <?php if ($neighbors === []): ?>
                        <p class="text-muted mb-0">Aucun autre tag ne partage d’information avec celui-ci.</p>
                    <?php else: ?>
                        <div class="chips">
                            <?php foreach ($neighbors as $neighbor): ?>
                                <a class="chip tags__neighbor" href="#" data-route="detail/<?= (int) $neighbor['id'] ?>" title="<?= (int) $neighbor['shared_count'] ?> information<?= (int) $neighbor['shared_count'] > 1 ? 's' : '' ?> en commun">
                                    <svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg>
                                    <span><?= $e($neighbor['name']) ?></span>
                                    <span class="tag-cloud__count"><?= (int) $neighbor['shared_count'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card__header">
                    <h3 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-database"></use></svg> Informations portant ce tag</h3>
                    <span class="badge"><?= count($infos) ?></span>
                </div>
                <?php if ($infos === []): ?>
                    <div class="card__body">
                        <?= $module->renderCore('state', [
                            'type' => 'empty',
                            'title' => $hidden > 0 ? 'Aucune information visible' : 'Aucune information ne porte ce tag',
                            'message' => $hidden > 0
                                ? 'Les informations taguées appartiennent à des jeux de données privés ou que vous ne pouvez pas lire.'
                                : 'Ce tag n’est utilisé par aucune information pour le moment.',
                        ]) ?>
                    </div>
                <?php else: ?>
                    <div class="card__body card__body--flush">
                        <div class="table-wrap mb-0">
                            <table class="table table--compact tags__infos">
                                <thead>
                                <tr>
                                    <th>Libellé</th>
                                    <th>Jeu de données</th>
                                    <th>Module</th>
                                    <th class="col-actions">Ouvrir</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($infos as $info): ?>
                                    <tr>
                                        <td><?= $info['label'] === '' ? '<em class="text-muted">Sans libellé</em>' : $e($info['label']) ?></td>
                                        <td><span title="<?= $e($info['dataset_code']) ?>"><?= $e($info['dataset_name']) ?></span></td>
                                        <td><?= $e($info['module_name']) ?></td>
                                        <td class="col-actions">
                                            <?php if ($info['open_route'] !== null): ?>
                                                <a class="btn btn--sm btn--ghost" href="#" data-open-module="<?= $e($info['module_id']) ?>" data-open-route="<?= $e($info['open_route']) ?>" title="Ouvrir dans le module <?= $e($info['module_name']) ?>">
                                                    <svg class="icon" aria-hidden="true"><use href="#i-external"></use></svg> Ouvrir
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($hidden > 0): ?>
                    <div class="card__footer text-muted text-small">
                        <svg class="icon icon--sm" aria-hidden="true"><use href="#i-eye-off"></use></svg>
                        <?= (int) $hidden ?> information<?= $hidden > 1 ? 's' : '' ?> d’autres jeux de données non affichée<?= $hidden > 1 ? 's' : '' ?> (jeux privés ou non lisibles).
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
