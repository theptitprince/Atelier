<?php
/**
 * Budget du mois : réalisé / budget par catégorie, saisie des budgets.
 * @var string $month
 * @var string $monthLabel
 * @var string $previous
 * @var string $next
 * @var array<string, mixed> $report
 * @var array<string, string> $periods
 * @var array<string, bool> $rights
 * @var bool $hasCategories
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
$envelopeForm = static function (array $row) use ($module, $e, $month, $periods): string {
    $env = $row['envelope'];
    return '<form class="budget__envelope-form" data-action="envelope-save" novalidate>'
        . '<input type="hidden" name="category_id" value="' . (int) $row['id'] . '"><input type="hidden" name="month" value="' . $e($month) . '">'
        . '<input class="input input--sm mono budget__envelope-amount" name="amount" inputmode="decimal" value="' . $e($env !== null ? \Atelier\Modules\Budget\Money::input($env['amount']) : '') . '" placeholder="0,00" aria-label="Budget de ' . $e($row['name']) . '">'
        . '<select class="select select--sm" name="period" aria-label="Période">' . implode('', array_map(static fn (string $code, string $label): string => '<option value="' . $e($code) . '"' . (($env['period'] ?? 'month') === $code ? ' selected' : '') . '>' . $e($label) . '</option>', array_keys($periods), $periods)) . '</select>'
        . '<button type="submit" class="btn btn--sm btn--icon" title="Enregistrer le budget à partir de ' . $e(\Atelier\Modules\Budget\Period::monthLabel($month)) . '">' . $module->icon('save') . '</button>'
        . '</form>';
};
$section = static function (string $kind, string $title, array $data) use ($module, $e, $rights, $envelopeForm, $month): string {
    $isExpense = $kind === 'expense';
    $html = '<section class="card"><div class="card__header"><h2 class="card__title">' . $module->icon($isExpense ? 'arrow-down' : 'arrow-up') . ' ' . $e($title) . '</h2>'
        . '<span class="text-small">' . $e($module->money($data['spent'])) . ' / ' . $e($module->money($data['budget'], 'sans budget')) . '</span></div>';
    if ($data['rows'] === []) {
        return $html . '<div class="card__body"><p class="text-muted mb-0">Aucune catégorie de ce type n’a de budget ni d’opération ce mois-ci.</p></div></section>';
    }
    $html .= '<div class="card__body card__body--flush"><table class="table table--compact budget__table"><thead><tr><th>Catégorie</th><th class="col-num">' . ($isExpense ? 'Dépensé' : 'Reçu') . '</th><th class="col-num">Budget</th><th class="col-num">' . ($isExpense ? 'Reste' : 'Écart') . '</th><th class="budget__col-bar">Consommation</th>' . ($rights['update'] ? '<th class="budget__col-form">Budget à partir de ce mois</th>' : '') . '</tr></thead><tbody>';
    foreach ($data['rows'] as $row) {
        $rows = array_merge([$row + ['level' => 0]], array_map(static fn (array $c): array => $c + ['level' => 1], $row['children']));
        foreach ($rows as $r) {
            $isParent = $r['level'] === 0;
            $remainingClass = $r['remaining'] === null ? 'text-muted' : ($isExpense ? ($r['remaining'] < 0 ? 'text-danger' : 'text-success') : ($r['remaining'] > 0 ? 'text-danger' : 'text-success'));
            $html .= '<tr class="' . ($isParent ? 'budget__row--parent' : 'budget__row--child') . '">'
                . '<td>' . ($isParent ? '<strong>' : '<span class="budget__indent">') . '<a href="#" data-route="transactions?month=' . $e($month) . '&amp;category=' . (int) $r['id'] . '">' . $e($r['name']) . '</a>' . ($isParent ? '</strong>' : '</span>') . ($isParent && !empty($r['inherited']) ? ' <span class="text-small text-muted" title="Somme des budgets des sous-catégories">Σ</span>' : '') . '</td>'
                . '<td class="col-num mono">' . $e($module->money($r['spent'], '0,00 €')) . '</td>'
                . '<td class="col-num mono text-muted">' . $e($module->money($r['budget'])) . '</td>'
                . '<td class="col-num mono ' . $remainingClass . '">' . $e($module->money($r['remaining'] === null ? null : ($isExpense ? $r['remaining'] : -$r['remaining']), '—', !$isExpense)) . '</td>'
                . '<td>' . ($r['budget'] !== null ? $module->bar($r['spent'], $r['budget'], $isExpense) : '<span class="text-muted text-small">—</span>') . '</td>'
                . ($rights['update'] ? '<td>' . $envelopeForm($r) . '</td>' : '')
                . '</tr>';
        }
    }
    $remaining = $data['budget'] - $data['spent'];
    return $html . '</tbody><tfoot><tr><th>Total</th><th class="col-num mono">' . $e($module->money($data['spent'], '0,00 €')) . '</th><th class="col-num mono">' . $e($module->money($data['budget'], '—')) . '</th><th class="col-num mono ' . ($isExpense ? ($remaining < 0 ? 'text-danger' : 'text-success') : '') . '">' . $e($module->money($isExpense ? $remaining : -$remaining, '—', !$isExpense)) . '</th><th colspan="' . ($rights['update'] ? 2 : 1) . '"></th></tr></tfoot></table></div></section>';
};
?>
<div class="module module-budget">
    <div class="toolbar budget__month-nav">
        <a class="btn btn--sm" href="#" data-route="envelopes?month=<?= $e($previous) ?>" title="Mois précédent"><?= $module->icon('chevron-left') ?></a>
        <label class="field field--inline"><span class="sr-only">Mois</span><input class="input input--sm budget__month" type="month" value="<?= $e($month) ?>" data-budget-month-nav aria-label="Mois affiché"></label>
        <strong><?= $e($monthLabel) ?></strong>
        <a class="btn btn--sm" href="#" data-route="envelopes?month=<?= $e($next) ?>" title="Mois suivant"><?= $module->icon('chevron-right') ?></a>
        <a class="btn btn--sm btn--ghost" href="#" data-route="envelopes">Ce mois-ci</a>
        <span class="toolbar__spacer"></span>
        <?php if ($report['uncategorized'] !== 0): ?><a class="text-small" href="#" data-route="transactions?month=<?= $e($month) ?>&amp;category=-1"><?= $module->icon('warning', 'icon--sm text-warning') ?> Opérations sans catégorie : <?= $e($module->money($report['uncategorized'], '', true)) ?></a><?php endif; ?>
    </div>

    <?php if (!$hasCategories): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucune catégorie', 'message' => 'Créez vos catégories de dépenses et de recettes, puis fixez un budget mensuel ou annuel à chacune.', 'actions' => $rights['update'] ? '<button type="button" class="btn btn--sm btn--primary" data-action="categories-defaults">Créer les catégories courantes</button> <a class="btn btn--sm" href="#" data-route="categories">Gérer les catégories</a>' : '']) ?>
    <?php else: ?>
        <?= $section('expense', 'Dépenses', $report['expense']) ?>
        <?= $section('income', 'Recettes', $report['income']) ?>
        <?php if ($rights['update']): ?><p class="text-small text-muted">Un budget saisi ici s’applique à partir de <?= $e($monthLabel) ?> et aux mois suivants, sans modifier les mois antérieurs. Un budget annuel est réparti par douzièmes. Une catégorie parente sans budget propre hérite de la somme (Σ) des budgets de ses sous-catégories.</p><?php endif; ?>
    <?php endif; ?>
</div>
