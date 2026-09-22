<?php
/**
 * Liste des comptes et soldes.
 * @var list<array<string, mixed>> $accounts
 * @var array<string, string> $kinds
 * @var array<string, bool> $rights
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="module module-budget">
    <?php if ($accounts === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun compte', 'message' => 'Créez au moins un compte courant ; le solde initial est celui du jour de départ de votre suivi.', 'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="account/new">Nouveau compte</a>' : '']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table budget__table">
                <thead><tr><th>Compte</th><th>Nature</th><th class="col-num">Solde initial</th><th class="col-num">Opérations</th><th class="col-num">Solde pointé</th><th class="col-num">Solde</th><th class="col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $a): ?>
                    <tr class="<?= $a['archived'] ? 'text-muted' : '' ?>">
                        <td><a href="#" data-route="transactions?account=<?= (int) $a['id'] ?>"><strong><?= $e($a['name']) ?></strong></a><?= $a['archived'] ? ' <span class="badge badge--muted">archivé</span>' : '' ?><?= $a['notes'] !== null ? '<div class="text-small text-muted">' . $e($a['notes']) . '</div>' : '' ?></td>
                        <td><?= $e($kinds[$a['kind']] ?? $a['kind']) ?></td>
                        <td class="col-num mono"><?= $e($module->money($a['initial_balance'])) ?></td>
                        <td class="col-num"><?= (int) $a['transaction_count'] ?></td>
                        <td class="col-num mono text-muted"><?= $e($module->money($a['cleared_balance'])) ?></td>
                        <td class="col-num mono<?= $a['balance'] < 0 ? ' text-danger' : '' ?>"><strong><?= $e($module->money($a['balance'])) ?></strong></td>
                        <td class="col-actions"><span class="table-actions">
                            <?php if ($rights['create'] && !$a['archived']): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="transaction/new?account=<?= (int) $a['id'] ?>" title="Nouvelle opération"><?= $module->icon('plus') ?></a><?php endif; ?>
                            <?php if ($rights['update']): ?>
                                <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="account/<?= (int) $a['id'] ?>/edit" title="Modifier"><?= $module->icon('edit') ?></a>
                                <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="account-archive" data-params='{"id":<?= (int) $a['id'] ?>}' title="<?= $a['archived'] ? 'Réactiver' : 'Archiver' ?>"><?= $module->icon($a['archived'] ? 'refresh' : 'archive') ?></button>
                            <?php endif; ?>
                            <?php if ($rights['delete'] && $a['transaction_count'] === 0): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="account-delete" data-params='{"id":<?= (int) $a['id'] ?>}' data-confirm="Supprimer le compte « <?= $e($a['name']) ?> » ?" data-danger title="Supprimer"><?= $module->icon('trash') ?></button><?php endif; ?>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-small text-muted">Le solde pointé ne compte que les opérations pointées (celles qui figurent sur le relevé bancaire) : il doit correspondre au solde affiché par la banque. Un compte qui porte des opérations s’archive plutôt qu’il ne se supprime.</p>
    <?php endif; ?>
</div>
