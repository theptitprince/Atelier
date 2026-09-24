<?php
/**
 * Corbeille du module : articles supprimés logiquement, restauration et suppression définitive.
 * Le même contenu est visible, avec celui des autres modules, dans le module Corbeille globale.
 * Variables : $rows (list, avec deleted_by_name, purge_at, days_left), $retention (int), $module, $e, $datetime.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
?>
<div class="module module-demo">
    <p class="text-small text-muted">
        Un article supprimé n’est jamais effacé tout de suite : il reste ici <?= (int) $retention ?> jours, masqué de tous les écrans,
        du service intermodule et des tags, puis la maintenance (<code>console maintenance:purge</code>) le supprime définitivement.
    </p>
    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => 'La corbeille est vide',
            'message' => 'Supprimez un article depuis l’écran Tableaux (bouton de ligne ou action groupée) pour le voir apparaître ici.',
            'actions' => '<a class="btn" href="#" data-route="tables">' . $icon('list') . ' Aller aux tableaux</a>',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table demo-table">
                <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th>Nom</th>
                    <th>Catégorie</th>
                    <th>Supprimé le</th>
                    <th>Par</th>
                    <th>Purge</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="col-num mono"><?= (int) $row['id'] ?></td>
                        <td><?= $e($row['name']) ?></td>
                        <td><span class="badge badge--muted"><?= $e($row['category']) ?></span></td>
                        <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                        <td><?= $row['deleted_by_name'] === null ? '<span class="text-muted">—</span>' : $e($row['deleted_by_name']) ?></td>
                        <td class="text-nowrap" title="<?= $e($datetime($row['purge_at'])) ?>">
                            <?php if ($row['days_left'] === null): ?>
                                <span class="text-muted">—</span>
                            <?php elseif ($row['days_left'] <= 3): ?>
                                <span class="badge badge--warning">dans <?= (int) $row['days_left'] ?> j</span>
                            <?php else: ?>
                                dans <?= (int) $row['days_left'] ?> j
                            <?php endif; ?>
                        </td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <button type="button" class="btn btn--sm" data-action="restore" data-params='{"id": <?= (int) $row['id'] ?>}'><?= $icon('refresh') ?> Restaurer</button>
                                <button type="button" class="btn btn--sm btn--outline-danger" data-action="purge" data-params='{"id": <?= (int) $row['id'] ?>}' data-confirm="Supprimer définitivement « <?= $e($row['name']) ?> » ? Ses tags, relations et pièces jointes seront perdus." data-danger><?= $icon('trash') ?> Supprimer définitivement</button>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
