<?php
/**
 * Tableau paginé des relations entre informations visibles.
 * Variables : $rows (list), $total, $type, $types (array type => nombre), $typeLabels (array), $page, $perPage,
 *             $query (array), $module, $e, $datetime.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
?>
<div class="module module-explorer">
    <div class="toolbar">
        <div class="field">
            <label class="sr-only" for="explorer-rel-filter">Type de relation</label>
            <select class="select" id="explorer-rel-filter" data-route-select>
                <option value="relations"<?= $type === '' ? ' selected' : '' ?>>Tous les types</option>
                <?php foreach ($types as $code => $count): ?>
                    <option value="relations?type=<?= $e(rawurlencode($code)) ?>"<?= $code === $type ? ' selected' : '' ?>><?= $e($typeLabels[$code] ?? $code) ?> (<?= (int) $count ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($type !== ''): ?>
            <a class="btn btn--ghost" href="#" data-route="relations"><?= $icon('close') ?> Effacer</a>
        <?php endif; ?>
        <span class="toolbar__spacer"></span>
        <span class="text-small text-muted">Seules les relations dont les deux informations sont visibles sont listées.</span>
    </div>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $type === '' ? 'Aucune relation' : 'Aucune relation de ce type',
            'message' => 'Les relations se créent depuis le détail d’une information (bouton « Relier ») ou depuis les modules.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table explorer__relations">
                <thead>
                <tr>
                    <th>Source</th>
                    <th>Type</th>
                    <th>Cible</th>
                    <th>Commentaire</th>
                    <th>Créée</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <a href="#" data-route="info/<?= $e($row['from_info']) ?>"><?= $e($row['from_label'] ?? ('#' . $row['from_key'])) ?></a>
                            <br><span class="text-small text-muted"><?= $e($row['from_dataset_name']) ?></span>
                        </td>
                        <td><span class="badge badge--info"><?= $e($row['type_label']) ?></span></td>
                        <td>
                            <a href="#" data-route="info/<?= $e($row['to_info']) ?>"><?= $e($row['to_label'] ?? ('#' . $row['to_key'])) ?></a>
                            <br><span class="text-small text-muted"><?= $e($row['to_dataset_name']) ?></span>
                        </td>
                        <td class="text-small"><?= empty($row['comment']) ? '<span class="text-muted">—</span>' : $e($row['comment']) ?></td>
                        <td class="text-nowrap text-small text-muted"><?= $e($datetime($row['created_at'])) ?><?php if (!empty($row['creator'])): ?><br><?= $e($row['creator']) ?><?php endif; ?></td>
                        <td class="col-actions">
                            <?php if ($row['can_remove']): ?>
                                <button type="button" class="btn btn--sm btn--ghost btn--icon" data-action="unrelate" data-params='{"id": <?= (int) $row['id'] ?>}' data-confirm="Supprimer cette relation ?" title="Supprimer" aria-label="Supprimer la relation"><?= $icon('trash') ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'route' => 'relations', 'query' => $query]) ?>
    <?php endif; ?>
</div>
