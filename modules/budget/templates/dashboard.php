<?php
/**
 * Tableau de bord du budget.
 * @var list<array<string, mixed>> $accounts
 * @var int $totalBalance
 * @var string $month
 * @var string $monthLabel
 * @var array{income: int, expense: int} $totals
 * @var array<string, mixed> $envelopes rapport du mois
 * @var list<array<string, mixed>> $due récurrences à poster
 * @var list<array<string, mixed>> $goals
 * @var array<string, mixed> $savings rapport de l'année
 * @var list<array<string, mixed>> $recent
 * @var int $uncategorized
 * @var array<string, bool> $rights
 * @var bool $hasAccounts
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
$remaining = $envelopes['expense']['budget'] - $envelopes['expense']['spent'];
?>
<div class="module module-budget">
    <?php if (!$hasAccounts): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun compte', 'message' => 'Créez un compte courant pour commencer à saisir ou importer vos opérations, puis des catégories avec leur budget.', 'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="account/new">Créer un compte</a> <a class="btn btn--sm" href="#" data-route="categories">Catégories</a>' : '']) ?>
    <?php else: ?>
    <div class="budget__stats">
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value<?= $totalBalance < 0 ? ' text-danger' : '' ?>"><?= $e($module->money($totalBalance)) ?></span><span class="kpi__label">solde total</span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value text-success"><?= $e($module->money($totals['income'], '0,00 €')) ?></span><span class="kpi__label">recettes de <?= $e($monthLabel) ?></span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value text-danger"><?= $e($module->money(-$totals['expense'], '0,00 €')) ?></span><span class="kpi__label">dépenses de <?= $e($monthLabel) ?></span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value<?= $remaining < 0 ? ' text-danger' : '' ?>"><?= $e($module->money($remaining, '—')) ?></span><span class="kpi__label">budget restant<?= $envelopes['overspent'] > 0 ? ' <span class="badge badge--danger">' . (int) $envelopes['overspent'] . ' dépassé' . ($envelopes['overspent'] > 1 ? 's' : '') . '</span>' : '' ?></span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value<?= $savings['total'] >= 0 ? ' text-success' : ' text-danger' ?>"><?= $e($module->money($savings['total'], '0,00 €', true)) ?></span><span class="kpi__label">économies <?= (int) $savings['year'] ?> (<?= (int) $savings['months'] ?> mois révolus)</span></div></div>
    </div>

    <div class="budget__dashboard">
        <div class="budget__dashboard-main">
            <section class="card" aria-labelledby="bd-due-title">
                <div class="card__header">
                    <h2 class="card__title" id="bd-due-title"><?= $module->icon('refresh', $due !== [] ? 'text-warning' : '') ?> Échéances à poster</h2>
                    <span class="flex gap-1">
                        <span class="badge <?= $due !== [] ? 'badge--warning' : 'badge--muted' ?>"><?= count($due) ?></span>
                        <?php if ($due !== [] && $rights['create']): ?><button type="button" class="btn btn--sm" data-action="recurring-post-due" data-confirm="Poster toutes les échéances atteintes ?"><?= $module->icon('check') ?> Tout poster</button><?php endif; ?>
                    </span>
                </div>
                <div class="card__body<?= $due === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($due === []): ?>
                        <p class="text-muted mb-0">Aucune récurrence n’a atteint son échéance. <a href="#" data-route="forecast">Voir le prévisionnel</a>.</p>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($due as $r): ?>
                                <li class="list__item">
                                    <span class="text-muted text-small mono text-nowrap"><?= $e($module->day($r['next_at'])) ?></span>
                                    <span class="grow"><strong><?= $e($r['label']) ?></strong> <span class="text-muted text-small">· <?= $e($r['account_name']) ?><?= $r['category_path'] !== null ? ' · ' . $e($r['category_path']) : '' ?></span></span>
                                    <?= $module->amountHtml($r['amount']) ?>
                                    <?php if ($rights['create']): ?><button type="button" class="btn btn--sm" data-action="recurring-post" data-params='{"id":<?= (int) $r['id'] ?>}' title="Créer l’opération et avancer l’échéance"><?= $module->icon('check') ?> Poster</button><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="bd-budget-title">
                <div class="card__header">
                    <h2 class="card__title" id="bd-budget-title"><?= $module->icon('grid') ?> Budget de <?= $e($monthLabel) ?></h2>
                    <a class="btn btn--sm btn--ghost" href="#" data-route="envelopes">Détail</a>
                </div>
                <div class="card__body<?= $envelopes['expense']['rows'] === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($envelopes['expense']['rows'] === []): ?>
                        <p class="text-muted mb-0">Aucune dépense ni budget ce mois-ci. Définissez des budgets par catégorie depuis <a href="#" data-route="envelopes">Budget du mois</a>.</p>
                    <?php else: ?>
                        <table class="table table--compact budget__table">
                            <thead><tr><th>Catégorie</th><th class="col-num">Dépensé</th><th class="col-num">Budget</th><th class="budget__col-bar">Consommation</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($envelopes['expense']['rows'], 0, 8) as $row): ?>
                                <tr>
                                    <td><a href="#" data-route="transactions?month=<?= $e($month) ?>&amp;category=<?= (int) $row['id'] ?>"><?= $e($row['name']) ?></a></td>
                                    <td class="col-num mono"><?= $e($module->money($row['spent'])) ?></td>
                                    <td class="col-num mono text-muted"><?= $e($module->money($row['budget'])) ?></td>
                                    <td><?= $row['budget'] !== null ? $module->bar($row['spent'], $row['budget']) : '<span class="text-muted text-small">sans budget</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($uncategorized > 0): ?>
                        <p class="text-small mb-0 <?= $envelopes['expense']['rows'] === [] ? 'mt-2' : 'budget__note' ?>"><?= $module->icon('warning', 'icon--sm text-warning') ?> <a href="#" data-route="transactions?month=<?= $e($month) ?>&amp;category=-1"><?= (int) $uncategorized ?> opération<?= $uncategorized > 1 ? 's' : '' ?> sans catégorie ce mois-ci</a>.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="bd-recent-title">
                <div class="card__header"><h2 class="card__title" id="bd-recent-title"><?= $module->icon('list') ?> Dernières opérations</h2><a class="btn btn--sm btn--ghost" href="#" data-route="transactions">Toutes</a></div>
                <div class="card__body<?= $recent === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($recent === []): ?>
                        <p class="text-muted mb-0">Aucune opération.<?= $rights['create'] ? ' <a href="#" data-route="transaction/new">Saisir une opération</a> ou <a href="#" data-route="import">importer un relevé</a>.' : '' ?></p>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($recent as $t): ?>
                                <li class="list__item">
                                    <span class="text-muted text-small mono text-nowrap"><?= $e($module->day($t['done_at'])) ?></span>
                                    <span class="grow truncate"><a href="#" data-route="transaction/<?= (int) $t['id'] ?>/edit"><?= $e($t['label']) ?></a> <span class="text-muted text-small">· <?= $e($t['category_path'] ?? 'sans catégorie') ?></span></span>
                                    <?= $module->amountHtml($t['amount']) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="budget__dashboard-side">
            <section class="card" aria-labelledby="bd-accounts-title">
                <div class="card__header"><h2 class="card__title" id="bd-accounts-title"><?= $module->icon('database') ?> Comptes</h2></div>
                <div class="card__body card__body--flush">
                    <ul class="list">
                        <?php foreach ($accounts as $a): ?>
                            <li class="list__item"><span class="grow"><?= $e($a['name']) ?> <span class="text-muted text-small">· <?= $e(\Atelier\Modules\Budget\AccountRepository::KINDS[$a['kind']] ?? $a['kind']) ?></span></span><span class="mono<?= $a['balance'] < 0 ? ' text-danger' : '' ?>"><?= $e($module->money($a['balance'])) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <section class="card" aria-labelledby="bd-goals-title">
                <div class="card__header"><h2 class="card__title" id="bd-goals-title"><?= $module->icon('archive') ?> Objectifs d’épargne</h2><a class="btn btn--sm btn--ghost" href="#" data-route="savings">Détail</a></div>
                <div class="card__body">
                    <?php if ($goals === []): ?>
                        <p class="text-muted mb-0">Aucun objectif.<?= $rights['create'] ? ' <a href="#" data-route="goal/new">Créer un objectif</a>.' : '' ?></p>
                    <?php else: ?>
                        <?php foreach ($goals as $g): ?>
                            <div class="budget__goal">
                                <div class="flex flex--between"><span><?= $e($g['name']) ?><?= $g['reached'] ? ' ' . $module->icon('check', 'icon--sm text-success') : '' ?></span><span class="text-small mono"><?= $e($module->money($g['progress'])) ?> / <?= $e($module->money($g['target'])) ?></span></div>
                                <?= $module->bar($g['progress'], $g['target'], false) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="bd-actions-title">
                <div class="card__header"><h2 class="card__title" id="bd-actions-title"><?= $module->icon('book') ?> Raccourcis</h2></div>
                <div class="card__body">
                    <div class="flex flex--col gap-1">
                        <?php if ($rights['create']): ?>
                            <a class="btn" href="#" data-route="transaction/new"><?= $module->icon('plus') ?> Dépense</a>
                            <a class="btn" href="#" data-route="transaction/new?kind=income"><?= $module->icon('plus') ?> Recette</a>
                            <a class="btn" href="#" data-route="import"><?= $module->icon('upload') ?> Importer un relevé</a>
                            <a class="btn" href="#" data-route="saving/new"><?= $module->icon('archive') ?> Économie réalisée</a>
                        <?php endif; ?>
                        <a class="btn btn--ghost" href="#" data-route="transactions?uncleared=1"><?= $module->icon('check') ?> Opérations à pointer</a>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <?php endif; ?>
</div>
