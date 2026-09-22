<?php
/**
 * Tableaux : pagination serveur (25/page), tri sur toutes les colonnes, filtres, sélection et
 * actions groupées, actions par ligne, export CSV, états de ligne, état vide.
 * Variables : $rows (list), $total, $filters (ItemFilters), $categories, $rights, $selected (list<int>), $countAll, $module, $e, $datetime.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$sortQuery = $filters->toQuery(false);            // filtres seuls : les en-têtes ajoutent sort/dir/page
$pageQuery = $filters->toQuery(true);             // filtres + tri : la pagination ajoute page
$sortHeader = static fn (string $label, string $column): string => $module->renderCore('sort_header', [
    'label' => $label, 'column' => $column, 'sort' => $filters->sort(), 'direction' => $filters->direction(), 'route' => 'tables', 'query' => $sortQuery,
]);
$canUpdate = (bool) ($rights['update'] ?? false);
$canDelete = (bool) ($rights['delete'] ?? false);
$canExport = (bool) ($rights['export'] ?? false);
$price = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' €';
?>
<div class="module module-demo">
    <form class="toolbar" data-action="filter-tables" data-auto-submit novalidate>
        <input type="hidden" name="sort" value="<?= $e($filters->sort()) ?>">
        <input type="hidden" name="dir" value="<?= $e($filters->direction()) ?>">
        <div class="field input-icon" style="width: 280px">
            <?= $icon('search') ?>
            <label class="sr-only" for="t-q">Rechercher un article</label>
            <input class="input" id="t-q" name="q" type="search" value="<?= $e($filters->q()) ?>" placeholder="Rechercher (Entrée)…" autocomplete="off">
        </div>
        <div class="field">
            <label class="sr-only" for="t-category">Catégorie</label>
            <select class="select" id="t-category" name="category">
                <option value="">Toutes les catégories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= $e($category) ?>"<?= $filters->category() === $category ? ' selected' : '' ?>><?= $e($category) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="sr-only" for="t-active">État</label>
            <select class="select" id="t-active" name="active">
                <option value="">Actifs et inactifs</option>
                <option value="1"<?= $filters->active() === '1' ? ' selected' : '' ?>>Actifs seulement</option>
                <option value="0"<?= $filters->active() === '0' ? ' selected' : '' ?>>Inactifs seulement</option>
            </select>
        </div>
        <?php if ($filters->isActive()): ?>
            <a class="btn btn--ghost" href="#" data-route="<?= $e(\Atelier\Modules\Demo\ItemFilters::fromArray(['sort' => $filters->sort(), 'dir' => $filters->direction()])->route('tables')) ?>"><?= $icon('close') ?> Effacer les filtres</a>
        <?php endif; ?>
        <span class="toolbar__spacer"></span>
        <span class="text-small text-muted" data-demo-count><?= (int) $total ?> élément<?= $total > 1 ? 's' : '' ?><?= $filters->isActive() ? ' sur ' . (int) $countAll : '' ?></span>
        <?php if ($canExport): ?>
            <a class="btn" href="<?= $e($module->url($filters->route('export.csv', ['page' => null]))) ?>" download title="Export CSV des lignes filtrées et triées (route brute)"><?= $icon('download') ?> Export CSV</a>
        <?php else: ?>
            <span class="btn" aria-disabled="true" title="Permission export requise"><?= $icon('download') ?> Export CSV</span>
        <?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $filters->isActive() ? 'Aucun article ne correspond aux filtres' : 'Aucun article',
            'message' => $filters->isActive() ? 'Modifiez la recherche ou effacez les filtres.' : 'Lancez « console db:seed » ou « Réinitialiser les articles » (écran Formulaires).',
            'actions' => $filters->isActive()
                ? '<a class="btn" href="#" data-route="tables">' . $icon('close') . ' Effacer les filtres</a>'
                : '<a class="btn btn--primary" href="#" data-route="forms">' . $icon('refresh') . ' Aller aux formulaires</a>',
        ]) ?>
    <?php else: ?>
        <form data-action="bulk" id="demo-bulk-form" novalidate>
            <input type="hidden" name="op" value="activate" data-demo-bulk-op>
            <div class="table-wrap">
                <table class="table demo-table">
                    <thead>
                    <tr>
                        <th class="col-check"><label class="checkbox" title="Tout sélectionner sur cette page"><input type="checkbox" data-demo-select-all aria-label="Tout sélectionner"></label></th>
                        <th class="col-num"><?= $sortHeader('#', 'id') ?></th>
                        <th><?= $sortHeader('Nom', 'name') ?></th>
                        <th><?= $sortHeader('Catégorie', 'category') ?></th>
                        <th class="col-num"><?= $sortHeader('Quantité', 'quantity') ?></th>
                        <th class="col-num"><?= $sortHeader('Prix', 'price') ?></th>
                        <th><?= $sortHeader('État', 'active') ?></th>
                        <th><?= $sortHeader('Créé le', 'created_at') ?></th>
                        <th class="col-actions">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $classes = [];
                        if (in_array($row['id'], $selected, true)) {
                            $classes[] = 'is-selected';
                        }
                        if (!$row['active']) {
                            $classes[] = 'is-disabled';
                        } elseif ($row['quantity'] < 5) {
                            $classes[] = 'is-warning';
                        }
                        ?>
                        <tr class="<?= $e(implode(' ', $classes)) ?>" data-demo-row>
                            <td class="col-check"><label class="checkbox"><input type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>"<?= in_array($row['id'], $selected, true) ? ' checked' : '' ?> aria-label="Sélectionner l’article <?= (int) $row['id'] ?>"></label></td>
                            <td class="col-num mono"><?= (int) $row['id'] ?></td>
                            <td>
                                <a href="#" data-route="shared?item=<?= (int) $row['id'] ?>" title="Ouvrir dans Données partagées"><?= $e($row['name']) ?></a>
                                <?php if ($row['quantity'] < 5 && $row['active']): ?><span class="badge badge--warning" title="Stock faible : ligne .is-warning">stock faible</span><?php endif; ?>
                            </td>
                            <td><span class="badge badge--muted"><?= $e($row['category']) ?></span></td>
                            <td class="col-num"><?= (int) $row['quantity'] ?></td>
                            <td class="col-num"><?= $e($price($row['price'])) ?></td>
                            <td><?= $row['active'] ? '<span class="badge badge--success badge--dot">actif</span>' : '<span class="badge badge--muted badge--dot">inactif</span>' ?></td>
                            <td class="text-nowrap text-muted"><?= $e($datetime($row['created_at'])) ?></td>
                            <td class="col-actions">
                                <span class="table-actions">
                                    <button type="button" class="btn btn--sm btn--ghost btn--icon" data-action="toggle" data-params='{"id": <?= (int) $row['id'] ?>}' title="<?= $row['active'] ? 'Désactiver' : 'Activer' ?> (data-action → refresh)" aria-label="<?= $row['active'] ? 'Désactiver' : 'Activer' ?>" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon($row['active'] ? 'eye-off' : 'eye') ?></button>
                                    <button type="button" class="btn btn--sm btn--ghost btn--icon" data-action="rename" data-params='{"id": <?= (int) $row['id'] ?>}' data-prompt="Nouveau nom de l’article n° <?= (int) $row['id'] ?>" data-prompt-field="name" data-prompt-value="<?= $e($row['name']) ?>" data-confirm-title="Renommer" title="Renommer (data-prompt)" aria-label="Renommer" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('edit') ?></button>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="toolbar demo-bulkbar">
                <span class="text-small"><strong data-demo-selected-count>0</strong> sélectionné(s)</span>
                <button type="submit" class="btn btn--sm" data-demo-bulk="activate" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('eye') ?> Activer</button>
                <button type="submit" class="btn btn--sm" data-demo-bulk="deactivate" <?= $canUpdate ? '' : 'disabled' ?>><?= $icon('eye-off') ?> Désactiver</button>
                <button type="submit" class="btn btn--sm btn--outline-danger" data-demo-bulk="delete" <?= $canDelete ? '' : 'disabled title="Permission delete requise"' ?>><?= $icon('trash') ?> Supprimer</button>
                <span class="text-small text-muted">Envoi : <code>&lt;form data-action="bulk"&gt;</code> avec <code>ids[]</code> et <code>op</code>. Sans sélection : <code>ActionResult::warning</code>.</span>
                <span class="toolbar__spacer"></span>
                <a class="btn btn--sm btn--ghost" href="#" data-route="<?= $e($filters->route('tables', ['selected' => implode(',', array_map(static fn (array $r): int => (int) $r['id'], array_slice($rows, 0, 3)))])) ?>" title="Lignes .is-selected posées par le serveur">Sélection serveur (3 premières)</a>
            </div>
        </form>

        <?= $module->renderCore('pagination', ['page' => $filters->page(), 'perPage' => $filters->perPage(), 'total' => $total, 'route' => 'tables', 'query' => $pageQuery]) ?>

        <p class="text-small text-muted mt-3">
            Légende des lignes : <span class="badge badge--muted">.is-disabled</span> article inactif (texte atténué) ·
            <span class="badge badge--warning">.is-warning</span> stock &lt; 5 ·
            <span class="badge badge--info">.is-selected</span> coché (posé par le JS du module ou par le serveur via <code>?selected=</code>).
            Le tri est un lien <code>data-route</code> produit par <code>renderCore('sort_header')</code> ; la pagination par <code>renderCore('pagination')</code>.
        </p>
    <?php endif; ?>
</div>
