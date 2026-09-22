<?php
/**
 * Corbeille des équipements : restauration ou suppression définitive (avec tâches et historique).
 * @var list<array<string, mixed>> $rows
 * @var int $retentionDays
 * @var array<string, string> $categories
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
?>
<div class="module module-maintenance">
    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'La corbeille est vide', 'message' => 'Les équipements supprimés y restent ' . (int) $retentionDays . ' jours avant purge automatique.']) ?>
    <?php else: ?>
        <div class="alert alert--warning" role="status">
            <?= $module->icon('warning') ?>
            <div><p class="mb-0">La suppression définitive efface aussi les tâches et l’historique de l’équipement. Ses pièces jointes restent gérées par le module Fichiers joints.</p></div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Équipement</th><th>Catégorie</th><th>Supprimé le</th><th class="col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $module->icon($module->categoryIcon((string) $row['category']), 'icon--sm text-muted') ?> <?= $e($row['name']) ?><?= $row['identifier'] !== null ? ' <code>' . $e($row['identifier']) . '</code>' : '' ?></td>
                        <td><?= $e($categories[$row['category']] ?? $row['category']) ?></td>
                        <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <button type="button" class="btn btn--sm" data-action="asset-restore" data-params='{"id":<?= (int) $row['id'] ?>}'><?= $module->icon('refresh') ?> Restaurer</button>
                                <button type="button" class="btn btn--sm btn--outline-danger" data-action="asset-purge" data-params='{"id":<?= (int) $row['id'] ?>}' data-confirm="Supprimer définitivement « <?= $e($row['name']) ?> », ses tâches et son historique ?" data-danger><?= $module->icon('trash') ?> Supprimer</button>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
