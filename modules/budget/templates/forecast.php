<?php
/**
 * Prévisionnel : récurrences, échéances d'entretien, projection du solde.
 * @var int $months
 * @var list<int> $choices
 * @var int $opening
 * @var list<array<string, mixed>> $projection
 * @var list<array<string, mixed>> $recurrings
 * @var list<array<string, mixed>> $due
 * @var list<array{day: string, label: string, amount: int, source: string}> $externals
 * @var bool $maintenanceAvailable
 * @var array<string, string> $units
 * @var array<string, bool> $rights
 * @var string $today
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
$minClosing = $projection === [] ? $opening : min(array_column($projection, 'closing'));
?>
<div class="module module-budget">
    <div class="budget__forecast-layout">
        <div class="budget__forecast-main">
            <section class="card">
                <div class="card__header">
                    <h2 class="card__title"><?= $module->icon('calendar') ?> Projection du solde</h2>
                    <span class="flex gap-1">
                        <?php foreach ($choices as $choice): ?><a class="btn btn--sm<?= $choice === $months ? ' is-active' : '' ?>" href="#" data-route="forecast<?= $choice === 12 ? '' : '?months=' . $choice ?>"><?= (int) $choice ?> mois</a><?php endforeach; ?>
                    </span>
                </div>
                <div class="card__body card__body--flush">
                    <table class="table table--compact budget__table">
                        <thead><tr><th>Mois</th><th class="col-num">Recettes</th><th class="col-num">Dépenses</th><th class="col-num">Net</th><th class="col-num">Solde fin de mois</th><th></th></tr></thead>
                        <tbody>
                        <tr class="budget__row--parent"><td>Aujourd’hui</td><td colspan="3" class="text-muted text-small">solde des comptes actifs</td><td class="col-num mono"><strong><?= $e($module->money($opening)) ?></strong></td><td></td></tr>
                        <?php foreach ($projection as $i => $row): ?>
                            <tr>
                                <td><?= $e($row['label']) ?></td>
                                <td class="col-num mono text-success"><?= $e($module->money($row['income'], '')) ?></td>
                                <td class="col-num mono text-danger"><?= $e($module->money($row['expense'], '')) ?></td>
                                <td class="col-num"><?= $module->amountHtml($row['net']) ?></td>
                                <td class="col-num mono<?= $row['closing'] < 0 ? ' text-danger' : '' ?>"><strong><?= $e($module->money($row['closing'])) ?></strong></td>
                                <td class="text-right"><?php if ($row['items'] !== []): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-budget-toggle="fc-<?= (int) $i ?>" aria-expanded="false" title="Détail du mois"><?= $module->icon('chevron-down') ?></button><?php endif; ?></td>
                            </tr>
                            <?php if ($row['items'] !== []): ?>
                                <tr class="budget__row--detail" id="fc-<?= (int) $i ?>" hidden>
                                    <td colspan="6">
                                        <ul class="list">
                                            <?php foreach ($row['items'] as $item): ?>
                                                <li class="list__item"><span class="text-muted text-small mono text-nowrap"><?= $e($module->day($item['day'])) ?></span><span class="grow"><?= $e($item['label']) ?><?= $item['source'] === 'maintenance' ? ' <span class="badge badge--info">Entretien</span>' : '' ?></span><?= $module->amountHtml($item['amount']) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card__footer text-small text-muted">
                    Projection à partir des récurrences actives<?= $maintenanceAvailable ? ' et des coûts estimés des entretiens à venir (module Entretien)' : '' ?>, hors dépenses courantes non récurrentes. Solde minimal projeté : <strong class="<?= $minClosing < 0 ? 'text-danger' : '' ?>"><?= $e($module->money($minClosing)) ?></strong>.
                </div>
            </section>

            <?php if ($externals !== []): ?>
                <section class="card">
                    <div class="card__header"><h2 class="card__title"><?= $module->icon('tool') ?> Entretiens à venir</h2><span class="badge badge--muted"><?= count($externals) ?></span></div>
                    <div class="card__body card__body--flush">
                        <ul class="list">
                            <?php foreach ($externals as $item): ?>
                                <li class="list__item"><span class="text-muted text-small mono text-nowrap"><?= $e($module->day($item['day'])) ?></span><span class="grow"><?= $e($item['label']) ?></span><?= $module->amountHtml($item['amount']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="card__footer text-small text-muted">Coûts estimés des tâches du module Entretien dont l’échéance tombe dans l’horizon ; le coût réel est reporté dans les opérations quand l’intervention est enregistrée.</div>
                </section>
            <?php endif; ?>
        </div>

        <div class="budget__forecast-side">
            <section class="card">
                <div class="card__header">
                    <h2 class="card__title"><?= $module->icon('refresh') ?> Récurrences</h2>
                    <?php if ($due !== [] && $rights['create']): ?><button type="button" class="btn btn--sm" data-action="recurring-post-due" data-confirm="Poster les <?= count($due) ?> échéance(s) atteinte(s) ?"><?= $module->icon('check') ?> Poster (<?= count($due) ?>)</button><?php endif; ?>
                </div>
                <div class="card__body<?= $recurrings === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($recurrings === []): ?>
                        <p class="text-muted mb-0">Aucune récurrence.<?= $rights['create'] ? ' <a href="#" data-route="recurring/new">Ajouter un loyer, un salaire, un abonnement…</a>' : '' ?></p>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($recurrings as $r): ?>
                                <?php $isDue = $r['active'] && $r['next_at'] <= $today; ?>
                                <li class="list__item<?= $r['active'] ? '' : ' text-muted' ?>">
                                    <span class="grow">
                                        <a href="#" data-route="recurring/<?= (int) $r['id'] ?>/edit"><strong><?= $e($r['label']) ?></strong></a>
                                        <?php if (!$r['active']): ?><span class="badge badge--muted">terminée</span><?php elseif ($isDue): ?><span class="badge badge--warning">à poster</span><?php endif; ?>
                                        <span class="text-small text-muted budget__block"><?= $e($module->intervalLabel($r)) ?> · prochaine le <?= $e($module->day($r['next_at'])) ?><?= $r['ends_at'] !== null ? ' · jusqu’au ' . $e($module->day($r['ends_at'])) : '' ?> · <?= $e($r['account_name']) ?></span>
                                    </span>
                                    <?= $module->amountHtml($r['amount']) ?>
                                    <?php if ($isDue && $rights['create']): ?><button type="button" class="btn btn--sm btn--icon" data-action="recurring-post" data-params='{"id":<?= (int) $r['id'] ?>}' title="Poster l’échéance"><?= $module->icon('check') ?></button><?php endif; ?>
                                    <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="recurring-delete" data-params='{"id":<?= (int) $r['id'] ?>}' data-confirm="Placer la récurrence « <?= $e($r['label']) ?> » dans la corbeille ?" data-danger title="Supprimer (corbeille)"><?= $module->icon('trash') ?></button><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
