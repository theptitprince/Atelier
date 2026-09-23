<?php
/**
 * Corbeille du module : équipements, tâches et interventions supprimés logiquement,
 * restauration ou suppression définitive. Les identifiants d'action sont préfixés (asset:12, job:5, log:9),
 * comme dans la corbeille globale.
 * @var list<array<string, mixed>> $assets
 * @var list<array<string, mixed>> $jobs
 * @var list<array<string, mixed>> $logs
 * @var int $retentionDays
 * @var array<string, string> $categories
 * @var bool $canRestore
 * @var bool $canPurge
 * @var bool $trashModule
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$total = count($assets) + count($jobs) + count($logs);
$actions = static function (string $type, int $id, string $label, string $purgeConfirm) use ($module, $canRestore, $canPurge, $e): string {
    $key = json_encode($type . ':' . $id);
    $html = '<span class="table-actions">';
    if ($canRestore) {
        $html .= '<button type="button" class="btn btn--sm" data-action="trash-restore" data-params=\'{"id":' . $key . '}\'>' . $module->icon('refresh') . ' Restaurer</button>';
    }
    if ($canPurge) {
        $html .= '<button type="button" class="btn btn--sm btn--outline-danger" data-action="trash-purge" data-params=\'{"id":' . $key . '}\' data-confirm="' . $e($purgeConfirm) . '" data-danger>' . $module->icon('trash') . ' Supprimer</button>';
    }
    return $html . '</span>';
};
?>
<div class="module module-maintenance">
    <?php if ($total === 0): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'La corbeille est vide', 'message' => 'Les équipements, tâches et interventions supprimés y restent ' . (int) $retentionDays . ' jours avant purge automatique.']) ?>
    <?php else: ?>
        <div class="alert alert--warning" role="status">
            <?= $module->icon('warning') ?>
            <div>
                <p class="mb-0">Les éléments sont purgés automatiquement <?= (int) $retentionDays ?> jours après leur suppression. La suppression définitive d’un équipement efface aussi ses tâches et son historique ; celle d’une tâche conserve ses interventions dans l’historique. Les pièces jointes restent gérées par le module Fichiers joints.<?= $trashModule ? ' Ces éléments apparaissent aussi dans la <a href="#" data-open-module="trash" data-open-route="list?module=maintenance">corbeille globale</a>.' : '' ?></p>
            </div>
        </div>

        <section class="card" aria-labelledby="mt-assets-title">
            <div class="card__header">
                <h2 class="card__title" id="mt-assets-title"><?= $module->icon('layers') ?> Équipements</h2>
                <span class="badge badge--muted"><?= count($assets) ?></span>
            </div>
            <div class="card__body<?= $assets === [] ? '' : ' card__body--flush' ?>">
                <?php if ($assets === []): ?>
                    <p class="text-muted mb-0">Aucun équipement en corbeille.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table table--compact">
                            <thead><tr><th>Équipement</th><th>Catégorie</th><th>Supprimé le</th><th class="col-actions">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($assets as $row): ?>
                                <tr>
                                    <td><?= $module->icon($module->categoryIcon((string) $row['category']), 'icon--sm text-muted') ?> <?= $e($row['name']) ?><?= $row['identifier'] !== null ? ' <code>' . $e($row['identifier']) . '</code>' : '' ?></td>
                                    <td><?= $e($categories[$row['category']] ?? $row['category']) ?></td>
                                    <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                                    <td class="col-actions"><?= $actions('asset', (int) $row['id'], (string) $row['name'], 'Supprimer définitivement « ' . $row['name'] . ' », ses tâches et son historique ?') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card" aria-labelledby="mt-jobs-title">
            <div class="card__header">
                <h2 class="card__title" id="mt-jobs-title"><?= $module->icon('clock') ?> Tâches et pannes</h2>
                <span class="badge badge--muted"><?= count($jobs) ?></span>
            </div>
            <div class="card__body<?= $jobs === [] ? '' : ' card__body--flush' ?>">
                <?php if ($jobs === []): ?>
                    <p class="text-muted mb-0">Aucune tâche en corbeille.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table table--compact">
                            <thead><tr><th>Tâche</th><th>Équipement</th><th>État</th><th>Supprimée le</th><th class="col-actions">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($jobs as $row): ?>
                                <tr>
                                    <td><?= $module->kindBadge((string) $row['kind']) ?> <?= $e($row['title']) ?></td>
                                    <td><?= $e($row['asset_name']) ?><?= $row['asset_deleted_at'] !== null ? ' <span class="badge badge--warning" title="L’équipement est lui-même en corbeille : il sera restauré avec la tâche">équipement en corbeille</span>' : '' ?></td>
                                    <td><?= $row['status'] === 'closed' ? 'Clôturée' : 'Ouverte' ?></td>
                                    <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                                    <td class="col-actions"><?= $actions('job', (int) $row['id'], (string) $row['title'], 'Supprimer définitivement la tâche « ' . $row['title'] . ' » ? Ses interventions restent dans l’historique.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card" aria-labelledby="mt-logs-title">
            <div class="card__header">
                <h2 class="card__title" id="mt-logs-title"><?= $module->icon('activity') ?> Interventions</h2>
                <span class="badge badge--muted"><?= count($logs) ?></span>
            </div>
            <div class="card__body<?= $logs === [] ? '' : ' card__body--flush' ?>">
                <?php if ($logs === []): ?>
                    <p class="text-muted mb-0">Aucune intervention en corbeille.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table table--compact">
                            <thead><tr><th>Date</th><th>Intervention</th><th>Équipement</th><th class="col-num">Coût</th><th>Supprimée le</th><th class="col-actions">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($logs as $row): ?>
                                <tr>
                                    <td class="text-nowrap mono"><?= $e($module->day($row['done_at'])) ?></td>
                                    <td><?= $e($row['title']) ?><?= $row['performed_by'] !== null ? ' <span class="text-muted text-small">· ' . $e($row['performed_by']) . '</span>' : '' ?></td>
                                    <td><?= $e($row['asset_name']) ?><?= $row['asset_deleted_at'] !== null ? ' <span class="badge badge--warning" title="L’équipement est lui-même en corbeille : il sera restauré avec l’intervention">équipement en corbeille</span>' : '' ?></td>
                                    <td class="col-num text-nowrap"><?= $e($module->money($row['cost'])) ?></td>
                                    <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                                    <td class="col-actions"><?= $actions('log', (int) $row['id'], (string) $row['title'], 'Supprimer définitivement l’intervention « ' . $row['title'] . ' » du ' . $module->day($row['done_at']) . ' ?') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
