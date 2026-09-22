<?php
/**
 * Tableau paginé des pièces jointes rattachées à des informations visibles.
 * Variables : $rows (list), $total, $size (octets), $dataset, $datasets (array code => meta), $page, $perPage,
 *             $query (array), $module, $baseUrl, $e, $datetime.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
?>
<div class="module module-explorer">
    <div class="toolbar">
        <div class="field">
            <label class="sr-only" for="explorer-att-filter">Jeu de données</label>
            <select class="select" id="explorer-att-filter" data-route-select>
                <option value="attachments"<?= $dataset === '' ? ' selected' : '' ?>>Tous les jeux de données</option>
                <?php foreach ($datasets as $code => $meta): ?>
                    <option value="attachments?dataset=<?= $e(rawurlencode($code)) ?>"<?= $code === $dataset ? ' selected' : '' ?>><?= $e($meta['name']) ?> (<?= $e($meta['module_name']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($dataset !== ''): ?>
            <a class="btn btn--ghost" href="#" data-route="attachments"><?= $icon('close') ?> Effacer</a>
        <?php endif; ?>
        <span class="toolbar__spacer"></span>
        <span class="text-small text-muted"><?= (int) $total ?> fichier<?= $total > 1 ? 's' : '' ?> · taille totale <?= $e(\Atelier\Support\Str::humanSize((int) $size)) ?></span>
    </div>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => 'Aucune pièce jointe',
            'message' => 'Aucun fichier n’est rattaché aux informations que vous pouvez consulter.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table explorer__attachments">
                <thead>
                <tr>
                    <th>Fichier</th>
                    <th>Information</th>
                    <th>Type</th>
                    <th class="col-num">Taille</th>
                    <th>Par</th>
                    <th>Le</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $icon('file', 'icon--sm text-muted') ?> <?= $e($row['original_name']) ?><?php if (!empty($row['description'])): ?><br><span class="text-small text-muted"><?= $e($row['description']) ?></span><?php endif; ?></td>
                        <td>
                            <a href="#" data-route="info/<?= $e($row['info_id']) ?>"><?= $e($row['info_label'] ?? ('#' . $row['info_key'])) ?></a>
                            <br><span class="text-small text-muted"><?= $e($row['info_dataset_name']) ?></span>
                        </td>
                        <td><code class="text-small"><?= $e($row['mime']) ?></code></td>
                        <td class="col-num text-nowrap"><?= $e(\Atelier\Support\Str::humanSize((int) $row['size'])) ?></td>
                        <td><?= $e($row['uploader'] ?? '—') ?></td>
                        <td class="text-nowrap text-muted text-small"><?= $e($datetime($row['created_at'])) ?></td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <a class="btn btn--sm btn--ghost btn--icon" href="<?= $e($baseUrl) ?>/files/<?= $e($row['id']) ?>" download title="Télécharger" aria-label="Télécharger"><?= $icon('download') ?></a>
                                <?php if ($row['inline']): ?>
                                    <a class="btn btn--sm btn--ghost btn--icon" href="<?= $e($baseUrl) ?>/files/<?= $e($row['id']) ?>?inline=1" target="_blank" rel="noopener" title="Afficher dans le navigateur" aria-label="Afficher"><?= $icon('eye') ?></a>
                                <?php endif; ?>
                                <?php if ($row['can_delete']): ?>
                                    <button type="button" class="btn btn--sm btn--ghost btn--icon" data-action="attachment-delete" data-params='{"id": <?= json_encode((string) $row['id'], JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>}' data-confirm="Supprimer « <?= $e($row['original_name']) ?> » ? (suppression logique, purge par la maintenance)" data-danger title="Supprimer" aria-label="Supprimer"><?= $icon('trash') ?></button>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'route' => 'attachments', 'query' => $query]) ?>
    <?php endif; ?>
</div>
