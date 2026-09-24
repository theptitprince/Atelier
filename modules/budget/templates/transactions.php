<?php
/**
 * Livre des opérations : filtres, sélection et actions groupées, pointage, pagination.
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var int $sum
 * @var int $income
 * @var int $expense
 * @var array{q: string, account: int, category: int, month: string, uncleared: bool, source: string, page: int, per_page: int} $query
 * @var list<array<string, mixed>> $accounts
 * @var list<array<string, mixed>> $categories arbre
 * @var array<string, string> $sources
 * @var array<string, bool> $rights
 * @var bool $canExport
 * @var string $exportUrl
 * @var list<int> $perPageChoices
 * @var array<int, int> $attachmentCounts
 * @var array<int, list<string>> $tagsById tags partagés de chaque opération
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
$filtered = $query['q'] !== '' || $query['account'] > 0 || $query['category'] !== 0 || $query['month'] !== '' || $query['uncleared'] || $query['source'] !== '';
$pageQuery = array_filter(['q' => $query['q'], 'account' => $query['account'] ?: null, 'category' => $query['category'] ?: null, 'month' => $query['month'], 'uncleared' => $query['uncleared'] ? 1 : null, 'source' => $query['source'], 'per_page' => $query['per_page'] !== 50 ? $query['per_page'] : null], static fn ($v): bool => $v !== null && $v !== '');
$dash = '<span class="text-muted">—</span>';
?>
<div class="module module-budget">
    <form class="card budget__filters" data-action="filter-transactions" data-auto-submit novalidate>
        <div class="card__body">
            <div class="toolbar mb-0 flex--wrap">
                <div class="field grow">
                    <label class="sr-only" for="bt-q">Recherche</label>
                    <div class="input-icon"><?= $module->icon('search', 'icon--sm') ?><input class="input" type="search" id="bt-q" name="q" value="<?= $e($query['q']) ?>" placeholder="Libellé, tiers, notes…" autocomplete="off"></div>
                </div>
                <label class="field field--inline"><span class="field__label">Compte</span>
                    <select class="select select--sm budget__select" name="account" aria-label="Compte"><option value="">Tous</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $query['account'] === (int) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?><?= $a['archived'] ? ' (archivé)' : '' ?></option><?php endforeach; ?></select>
                </label>
                <label class="field field--inline"><span class="field__label">Catégorie</span>
                    <select class="select select--sm budget__select" name="category" aria-label="Catégorie"><option value="">Toutes</option><option value="-1"<?= $query['category'] === -1 ? ' selected' : '' ?>>Sans catégorie</option><?= $module->categoryOptions($categories, $query['category'] > 0 ? $query['category'] : null, null, false) ?></select>
                </label>
                <label class="field field--inline"><span class="field__label">Mois</span><input class="input input--sm budget__month" type="month" name="month" value="<?= $e($query['month']) ?>" aria-label="Mois"></label>
                <label class="field field--inline"><span class="field__label">Origine</span>
                    <select class="select select--sm budget__select" name="source" aria-label="Origine"><option value="">Toutes</option><?php foreach ($sources as $code => $label): ?><option value="<?= $e($code) ?>"<?= $query['source'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                </label>
                <label class="checkbox"><input type="checkbox" name="uncleared" value="1"<?= $query['uncleared'] ? ' checked' : '' ?>> Non pointées</label>
                <button type="submit" class="btn"><?= $module->icon('filter') ?> Filtrer</button>
                <a class="btn btn--ghost" href="#" data-route="transactions"<?= $filtered ? '' : ' aria-disabled="true"' ?>><?= $module->icon('close') ?> Effacer</a>
                <?php if ($canExport): ?><a class="btn" href="<?= $e($exportUrl) ?>" download title="Exporter les opérations filtrées"><?= $module->icon('download') ?> CSV</a><?php endif; ?>
                <?php if ($rights['create']): ?><a class="btn btn--ghost" href="#" data-route="import"><?= $module->icon('upload') ?> Importer</a><?php endif; ?>
                <label class="field field--inline"><span class="field__label">Par page</span>
                    <select class="select select--sm budget__per-page" data-route-select aria-label="Opérations par page"><?php foreach ($perPageChoices as $option): ?><option value="<?= $e('transactions?' . http_build_query(array_filter($pageQuery + ['per_page' => $option], static fn ($v): bool => $v !== null && $v !== ''))) ?>"<?= $query['per_page'] === $option ? ' selected' : '' ?>><?= (int) $option ?></option><?php endforeach; ?></select>
                </label>
            </div>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => $filtered ? 'Aucune opération ne correspond aux filtres' : 'Aucune opération', 'message' => $filtered ? 'Modifiez les filtres.' : 'Saisissez une première opération ou importez un relevé bancaire.', 'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="transaction/new">Nouvelle opération</a> <a class="btn btn--sm" href="#" data-route="import">Importer</a>' : '']) ?>
    <?php else: ?>
        <form data-action="transactions-bulk" novalidate data-budget-bulk>
            <div class="toolbar budget__bulk" data-budget-bulk-bar hidden>
                <span class="text-small"><strong data-budget-bulk-count>0</strong> sélectionnée(s)</span>
                <input type="hidden" name="op" value="" data-budget-bulk-op>
                <?php if ($rights['update']): ?>
                    <button type="submit" class="btn btn--sm" data-budget-op="clear"><?= $module->icon('check') ?> Pointer</button>
                    <button type="submit" class="btn btn--sm" data-budget-op="unclear">Dépointer</button>
                    <select class="select select--sm budget__select" name="category_id" aria-label="Catégorie à appliquer"><?= $module->categoryOptions($categories, null) ?></select>
                    <button type="submit" class="btn btn--sm" data-budget-op="categorize"><?= $module->icon('tag') ?> Catégoriser</button>
                <?php endif; ?>
                <?php if ($rights['delete']): ?><button type="submit" class="btn btn--sm btn--outline-danger" data-budget-op="delete" data-confirm="Placer les opérations sélectionnées dans la corbeille ? Elles restent restaurables pendant 30 jours."><?= $module->icon('trash') ?> Supprimer</button><?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="table budget__table">
                    <thead><tr>
                        <th class="col-check"><input type="checkbox" aria-label="Tout sélectionner" data-budget-check-all></th>
                        <th>Date</th><th>Libellé</th><th>Catégorie</th><th>Compte</th><th class="col-num">Montant</th><th class="text-center" title="Pointée">✓</th><th class="col-actions">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $t): ?>
                        <tr class="<?= $t['cleared'] ? '' : 'budget__row--uncleared' ?>">
                            <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $t['id'] ?>" aria-label="Sélectionner" data-budget-check></td>
                            <td class="text-nowrap mono"><?= $e($module->day($t['done_at'])) ?></td>
                            <td>
                                <a href="#" data-route="transaction/<?= (int) $t['id'] ?>/edit"><?= $e($t['label']) ?></a>
                                <?php if ($t['payee'] !== null && $t['payee'] !== $t['label']): ?><span class="text-muted text-small">· <?= $e($t['payee']) ?></span><?php endif; ?>
                                <?php if ($t['source'] !== 'manual'): ?><span class="badge badge--muted" title="Origine"><?= $e($module->sourceLabel($t['source'])) ?></span><?php endif; ?>
                                <?php if (($attachmentCounts[$t['id']] ?? 0) > 0): ?><span class="badge badge--muted" title="Justificatifs"><?= $module->icon('paperclip', 'icon--sm') ?> <?= (int) $attachmentCounts[$t['id']] ?></span><?php endif; ?>
                                <?php if (($tagsById[$t['id']] ?? []) !== []): ?><span class="chips budget__tags" title="Tags partagés"><?php foreach ($tagsById[$t['id']] as $tag): ?><span class="chip"><?= $e($tag) ?></span><?php endforeach; ?></span><?php endif; ?>
                            </td>
                            <td><?= $t['category_path'] !== null ? $e($t['category_path']) : '<span class="text-warning text-small">sans catégorie</span>' ?></td>
                            <td class="text-small"><?= $e($t['account_name']) ?></td>
                            <td class="col-num"><?= $module->amountHtml($t['amount']) ?></td>
                            <td class="text-center">
                                <?php if ($rights['update']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="transaction-clear" data-params='{"id":<?= (int) $t['id'] ?>}' title="<?= $t['cleared'] ? 'Dépointer' : 'Pointer' ?>"><?= $module->icon($t['cleared'] ? 'check' : 'close', $t['cleared'] ? 'text-success' : 'text-muted') ?></button>
                                <?php else: ?><?= $t['cleared'] ? $module->icon('check', 'text-success') : '' ?><?php endif; ?>
                            </td>
                            <td class="col-actions"><span class="table-actions">
                                <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="transaction/<?= (int) $t['id'] ?>/edit" title="Modifier"><?= $module->icon('edit') ?></a>
                                <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="transaction-delete" data-params='{"id":<?= (int) $t['id'] ?>}' data-confirm="Placer « <?= $e($t['label']) ?> » dans la corbeille ?" data-danger title="Supprimer (corbeille)"><?= $module->icon('trash') ?></button><?php endif; ?>
                            </span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr>
                        <th colspan="5" class="text-right">Total<?= $filtered ? ' (filtré)' : '' ?> · recettes <?= $e($module->money($income, '0,00 €')) ?> · dépenses <?= $e($module->money($expense, '0,00 €')) ?></th>
                        <th class="col-num"><?= $module->amountHtml($sum) ?></th><th colspan="2"></th>
                    </tr></tfoot>
                </table>
            </div>
        </form>
        <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => 'transactions', 'query' => $pageQuery]) ?>
    <?php endif; ?>
</div>
