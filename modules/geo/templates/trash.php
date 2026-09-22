<?php
/**
 * Corbeille des points GPS.
 * @var list<array<string, mixed>> $rows
 * @var int $retentionDays
 * @var \Atelier\Modules\Geo\GeoModule $module
 */
?>
<div class="module module-geo">
    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'La corbeille est vide', 'message' => 'Les points supprimés y restent ' . $retentionDays . ' jours avant purge automatique.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table geo__table">
                <thead>
                <tr>
                    <th>Nom</th>
                    <th>Code</th>
                    <th>Coordonnées</th>
                    <th>Supprimé le</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $e($row['name']) ?></td>
                        <td><?= $row['code'] !== null && $row['code'] !== '' ? '<code>' . $e($row['code']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="mono"><?= $e($row['dms']) ?></td>
                        <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <button type="button" class="btn btn--sm" data-action="restore" data-params='{"id":<?= (int) $row['id'] ?>}'><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Restaurer</button>
                                <button type="button" class="btn btn--sm btn--outline-danger" data-action="purge" data-params='{"id":<?= (int) $row['id'] ?>}' data-confirm="Supprimer définitivement « <?= $e($row['name']) ?> » ? Ses tags, relations et rattachements de pièces jointes seront retirés." data-danger><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer</button>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
