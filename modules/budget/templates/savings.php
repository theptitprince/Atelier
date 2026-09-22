<?php
/**
 * Épargne et économies : objectifs, budget non dépensé par catégorie, gains enregistrés.
 * @var int $year
 * @var list<int> $years
 * @var list<array<string, mixed>> $goals
 * @var array<string, mixed> $report
 * @var array<string, string> $kinds
 * @var array<string, bool> $rights
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="module module-budget">
    <div class="budget__stats budget__stats--3">
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value<?= $report['total'] >= 0 ? ' text-success' : ' text-danger' ?>"><?= $e($module->money($report['total'], '0,00 €', true)) ?></span><span class="kpi__label">économies <?= (int) $year ?> (<?= (int) $report['months'] ?> mois)</span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value<?= $report['automatic_total'] >= 0 ? '' : ' text-danger' ?>"><?= $e($module->money($report['automatic_total'], '0,00 €', true)) ?></span><span class="kpi__label">budget non dépensé</span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value"><?= $e($module->money($report['manual_total'], '0,00 €', true)) ?></span><span class="kpi__label">gains enregistrés</span></div></div>
    </div>

    <div class="toolbar">
        <label class="field field--inline"><span class="field__label">Année</span>
            <select class="select select--sm budget__select" data-route-select aria-label="Année"><?php foreach ($years as $y): ?><option value="savings?year=<?= (int) $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= (int) $y ?></option><?php endforeach; ?></select>
        </label>
    </div>

    <div class="budget__savings-layout">
        <div class="budget__savings-main">
            <section class="card">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('grid') ?> Budget non dépensé par catégorie</h2><span class="text-small text-muted">janvier → <?= $report['months'] > 0 ? $e(\Atelier\Modules\Budget\Period::monthLabel(sprintf('%04d-%02d', $year, $report['months']))) : 'aucun mois' ?></span></div>
                <div class="card__body<?= $report['automatic'] === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($report['automatic'] === []): ?>
                        <p class="text-muted mb-0">Aucune catégorie de dépense n’a de budget sur cette période. Fixez des budgets depuis <a href="#" data-route="envelopes">Budget du mois</a> : l’écart entre budget et réalisé est alors calculé ici automatiquement.</p>
                    <?php else: ?>
                        <table class="table table--compact budget__table">
                            <thead><tr><th>Catégorie</th><th class="col-num">Budget cumulé</th><th class="col-num">Dépensé</th><th class="col-num">Économie</th><th class="budget__col-bar">Consommation</th></tr></thead>
                            <tbody>
                            <?php foreach ($report['automatic'] as $row): ?>
                                <tr>
                                    <td><?= $e($row['name']) ?> <span class="text-muted text-small">(<?= (int) $row['months'] ?> mois)</span></td>
                                    <td class="col-num mono text-muted"><?= $e($module->money($row['budget'])) ?></td>
                                    <td class="col-num mono"><?= $e($module->money($row['spent'], '0,00 €')) ?></td>
                                    <td class="col-num"><?= $module->amountHtml($row['saved']) ?></td>
                                    <td><?= $module->bar($row['spent'], $row['budget']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr><th>Total</th><th></th><th></th><th class="col-num"><?= $module->amountHtml($report['automatic_total']) ?></th><th></th></tr></tfoot>
                        </table>
                    <?php endif; ?>
                </div>
                <div class="card__footer text-small text-muted">Une économie négative signale un dépassement du budget. Le calcul porte sur les mois écoulés de l’année.</div>
            </section>

            <section class="card">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('archive') ?> Économies enregistrées</h2><?php if ($rights['create']): ?><a class="btn btn--sm" href="#" data-route="saving/new"><?= $module->icon('plus') ?> Ajouter</a><?php endif; ?></div>
                <div class="card__body<?= $report['manual'] === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($report['manual'] === []): ?>
                        <p class="text-muted mb-0">Aucune économie enregistrée. Notez ici les gains obtenus : changement de fournisseur, renégociation d’assurance, réparation faite soi-même, abonnement résilié…</p>
                    <?php else: ?>
                        <table class="table table--compact budget__table">
                            <thead><tr><th>Économie</th><th>Nature</th><th class="col-num">Gain</th><th>Depuis</th><th class="col-num">Réalisé en <?= (int) $year ?></th><th class="col-actions">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($report['manual'] as $s): ?>
                                <tr>
                                    <td><a href="#" data-route="saving/<?= (int) $s['id'] ?>/edit"><?= $e($s['label']) ?></a><?= $s['category_name'] !== null ? ' <span class="text-muted text-small">· ' . $e($s['category_name']) . '</span>' : '' ?></td>
                                    <td class="text-small"><?= $e($kinds[$s['kind']] ?? $s['kind']) ?></td>
                                    <td class="col-num mono"><?= $e($module->money($s['amount'])) ?><?= $s['kind'] === 'monthly' ? ' <span class="text-muted text-small">(' . $e($module->money($s['yearly'])) . '/an)</span>' : '' ?></td>
                                    <td class="text-nowrap"><?= $e($module->day($s['effective_from'])) ?><?= $s['effective_to'] !== null ? ' → ' . $e($module->day($s['effective_to'])) : '' ?></td>
                                    <td class="col-num"><?= $module->amountHtml($s['realized']) ?></td>
                                    <td class="col-actions"><span class="table-actions">
                                        <?php if ($rights['update']): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="saving/<?= (int) $s['id'] ?>/edit" title="Modifier"><?= $module->icon('edit') ?></a><?php endif; ?>
                                        <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="saving-delete" data-params='{"id":<?= (int) $s['id'] ?>}' data-confirm="Supprimer « <?= $e($s['label']) ?> » ?" data-danger title="Supprimer"><?= $module->icon('trash') ?></button><?php endif; ?>
                                    </span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr><th colspan="4">Total</th><th class="col-num"><?= $module->amountHtml($report['manual_total']) ?></th><th></th></tr></tfoot>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="budget__savings-side">
            <section class="card">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('archive') ?> Objectifs d’épargne</h2><?php if ($rights['create']): ?><a class="btn btn--sm" href="#" data-route="goal/new"><?= $module->icon('plus') ?> Objectif</a><?php endif; ?></div>
                <div class="card__body">
                    <?php if ($goals === []): ?>
                        <p class="text-muted mb-0">Aucun objectif. Un objectif suit un montant cible, lié à un compte d’épargne (solde réel) ou alimenté à la main.</p>
                    <?php else: ?>
                        <?php foreach ($goals as $g): ?>
                            <div class="budget__goal">
                                <div class="flex flex--between">
                                    <span><a href="#" data-route="goal/<?= (int) $g['id'] ?>/edit"><strong><?= $e($g['name']) ?></strong></a><?= $g['reached'] ? ' <span class="badge badge--success">atteint</span>' : '' ?></span>
                                    <span class="text-small mono"><?= $e($module->money($g['progress'])) ?> / <?= $e($module->money($g['target'])) ?></span>
                                </div>
                                <?= $module->bar($g['progress'], $g['target'], false) ?>
                                <div class="text-small text-muted"><?= $g['account_name'] !== null ? 'solde de « ' . $e($g['account_name']) . ' »' : 'montant saisi à la main' ?><?= $g['due_at'] !== null ? ' · échéance ' . $e($module->day($g['due_at'])) : '' ?><?= !$g['reached'] ? ' · reste ' . $e($module->money(max(0, $g['target'] - $g['progress']))) : '' ?></div>
                                <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--ghost budget__goal-delete" data-action="goal-delete" data-params='{"id":<?= (int) $g['id'] ?>}' data-confirm="Supprimer l’objectif « <?= $e($g['name']) ?> » ?" data-danger><?= $module->icon('trash', 'icon--sm') ?></button><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
