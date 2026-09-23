<?php
/**
 * Corbeille du budget : opérations, comptes, récurrences, objectifs et économies supprimés depuis moins de N jours.
 * @var list<array<string, mixed>> $rows lignes décorées (trash_kind, trash_id, trash_label, trash_type, deleted_at, expires_in_days)
 * @var int $retention
 * @var array<string, bool> $rights
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
$icons = ['transaction' => 'list', 'account' => 'database', 'recurring' => 'refresh', 'goal' => 'archive', 'saving' => 'archive'];
?>
<div class="module module-budget">
    <p class="text-muted">Les éléments supprimés sont conservés <?= (int) $retention ?> jours puis effacés définitivement par la maintenance. Un compte en corbeille masque ses opérations des soldes et des listes ; sa suppression définitive efface aussi ses opérations et ses récurrences.</p>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'La corbeille est vide', 'message' => 'Aucune donnée du budget supprimée au cours des ' . (int) $retention . ' derniers jours.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table budget__table">
                <thead><tr><th>Type</th><th>Élément</th><th>Détail</th><th>Supprimé le</th><th class="col-num">Expire dans</th><th class="col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $detail = match ($row['trash_kind']) {
                        'transaction' => $e($row['account_name']) . ($row['category_path'] !== null ? ' · ' . $e($row['category_path']) : '') . ($row['account_deleted_at'] !== null ? ' <span class="badge badge--muted">compte en corbeille</span>' : ''),
                        'account' => $e(\Atelier\Modules\Budget\AccountRepository::KINDS[$row['kind']] ?? $row['kind']) . ' · solde ' . $e($module->money($row['balance'])) . ' · ' . (int) $row['transaction_count'] . ' opération(s) masquée(s)',
                        'recurring' => $e($row['account_name']) . ' · prochaine le ' . $e($module->day($row['next_at'])),
                        'goal' => $row['account_name'] !== null ? 'solde de « ' . $e($row['account_name']) . ' »' : 'montant saisi à la main',
                        default => $row['category_name'] !== null ? $e($row['category_name']) : '<span class="text-muted">—</span>',
                    };
                    ?>
                    <tr<?= (int) $row['expires_in_days'] <= 3 ? ' class="is-warning"' : '' ?>>
                        <td class="text-nowrap"><?= $module->icon($icons[$row['trash_kind']] ?? 'trash', 'icon--sm text-muted') ?> <?= $e($row['trash_type']) ?></td>
                        <td><?= $e($row['trash_label']) ?></td>
                        <td class="text-small"><?= $detail ?></td>
                        <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                        <td class="col-num text-nowrap"><?= (int) $row['expires_in_days'] ?> j</td>
                        <td class="col-actions"><span class="table-actions">
                            <?php if ($rights['update']): ?><button type="button" class="btn btn--sm" data-action="trash-restore" data-params='<?= $e(json_encode(['id' => $row['trash_id']], JSON_UNESCAPED_UNICODE)) ?>' title="Restaurer"><?= $module->icon('refresh') ?> Restaurer</button><?php endif; ?>
                            <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--outline-danger" data-action="trash-purge" data-params='<?= $e(json_encode(['id' => $row['trash_id']], JSON_UNESCAPED_UNICODE)) ?>' data-confirm="Supprimer définitivement « <?= $e($row['trash_label']) ?> » ?<?= $row['trash_kind'] === 'account' ? ' Ses opérations et ses récurrences seront effacées.' : '' ?> Cette action est irréversible." data-confirm-title="Suppression définitive" data-confirm-label="Supprimer définitivement" data-danger title="Supprimer définitivement"><?= $module->icon('trash') ?></button><?php endif; ?>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
