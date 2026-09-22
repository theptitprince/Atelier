<?php
/**
 * Catégories (arbre) avec budget courant.
 * @var list<array<string, mixed>> $tree
 * @var array<int, array<string, mixed>> $envelopes enveloppes applicables ce mois
 * @var array<string, string> $kinds
 * @var array<string, string> $periods
 * @var array<string, bool> $rights
 * @var string $month
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
$rowHtml = static function (array $c, bool $child) use ($module, $e, $rights, $envelopes, $periods): string {
    $env = $envelopes[$c['id']] ?? null;
    $html = '<tr class="' . ($child ? 'budget__row--child' : 'budget__row--parent') . ($c['archived'] ? ' text-muted' : '') . '">'
        . '<td>' . ($child ? '<span class="budget__indent">' : '<strong>') . $e($c['name']) . ($child ? '</span>' : '</strong>') . ($c['archived'] ? ' <span class="badge badge--muted">archivée</span>' : '') . '</td>'
        . '<td class="col-num mono">' . ($env !== null ? $e($module->money($env['amount'])) . ' <span class="text-muted text-small">' . $e($periods[$env['period']] ?? '') . '</span>' : '<span class="text-muted">—</span>') . '</td>'
        . '<td class="col-actions"><span class="table-actions">';
    if ($rights['create'] && !$child && !$c['archived']) {
        $html .= '<a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="category/new?kind=' . $e($c['kind']) . '&amp;parent=' . (int) $c['id'] . '" title="Ajouter une sous-catégorie">' . $module->icon('plus') . '</a>';
    }
    if ($rights['update']) {
        $html .= '<a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="category/' . (int) $c['id'] . '/edit" title="Modifier">' . $module->icon('edit') . '</a>'
            . '<button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="category-archive" data-params=\'{"id":' . (int) $c['id'] . '}\' title="' . ($c['archived'] ? 'Réactiver' : 'Archiver') . '">' . $module->icon($c['archived'] ? 'refresh' : 'archive') . '</button>';
    }
    if ($rights['delete']) {
        $html .= '<button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="category-delete" data-params=\'{"id":' . (int) $c['id'] . '}\' data-confirm="Supprimer « ' . $e($c['name']) . ' » ? Ses opérations resteront sans catégorie." data-danger title="Supprimer">' . $module->icon('trash') . '</button>';
    }
    return $html . '</span></td></tr>';
};
?>
<div class="module module-budget">
    <?php if ($tree === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucune catégorie', 'message' => 'Créez vos catégories ou partez d’un jeu courant (logement, alimentation, véhicule, abonnements…) que vous adapterez.', 'actions' => $rights['create'] ? '<button type="button" class="btn btn--sm btn--primary" data-action="categories-defaults">Créer les catégories courantes</button> <a class="btn btn--sm" href="#" data-route="category/new">Nouvelle catégorie</a>' : '']) ?>
    <?php else: ?>
        <div class="budget__categories">
            <?php foreach ($kinds as $kindCode => $kindLabel): ?>
                <section class="card">
                    <div class="card__header"><h2 class="card__title"><?= $module->icon($kindCode === 'expense' ? 'arrow-down' : 'arrow-up') ?> <?= $e($kindLabel) ?>s</h2><?php if ($rights['create']): ?><a class="btn btn--sm" href="#" data-route="category/new?kind=<?= $e($kindCode) ?>"><?= $module->icon('plus') ?> Catégorie</a><?php endif; ?></div>
                    <div class="card__body card__body--flush">
                        <table class="table table--compact budget__table">
                            <thead><tr><th>Catégorie</th><th class="col-num">Budget (<?= $e($module->monthLabel($month)) ?>)</th><th class="col-actions">Actions</th></tr></thead>
                            <tbody>
                            <?php $any = false; foreach ($tree as $parent): if ($parent['kind'] !== $kindCode) { continue; } $any = true; ?>
                                <?= $rowHtml($parent, false) ?>
                                <?php foreach ($parent['children'] as $child) { echo $rowHtml($child, true); } ?>
                            <?php endforeach; ?>
                            <?php if (!$any): ?><tr><td colspan="3" class="text-muted">Aucune catégorie.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <p class="text-small text-muted">Les budgets se fixent dans <a href="#" data-route="envelopes">Budget du mois</a>. Deux niveaux au plus : catégorie et sous-catégorie.<?= $rights['create'] ? ' <button type="button" class="btn btn--sm btn--ghost" data-action="categories-defaults">Compléter avec les catégories courantes</button>' : '' ?></p>
    <?php endif; ?>
</div>
