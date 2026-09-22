<?php
/**
 * Corbeille globale : filtres, tableau paginé des éléments supprimés, sélection multiple et actions groupées.
 * Variables : $items (list), $total, $countAll, $counts (par source), $warnings (list<string>), $modules (id => nom),
 *             $source, $moduleFilter, $search, $sort, $direction, $page, $perPage, $query (array), $retention,
 *             $warningDays, $canRestoreAny, $canPurgeAny, $module, $e, $datetime.
 */
$sortQuery = array_diff_key($query, ['sort' => 1, 'dir' => 1]);
$tabQuery = array_diff_key($query, ['source' => 1, 'page' => 1]);
$tabRoute = static function (string $s) use ($tabQuery): string {
    $params = array_filter(['source' => $s] + $tabQuery, static fn ($v): bool => $v !== '' && $v !== null);
    return 'list' . ($params === [] ? '' : '?' . http_build_query($params));
};
$params = static fn (array $item): string => json_encode(['source' => $item['source'], 'module' => $item['module_id'], 'id' => $item['id']], JSON_UNESCAPED_UNICODE) ?: '{}';
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$hasFilters = $source !== '' || $moduleFilter !== '' || $search !== '';
?>
<div class="module module-trash">
    <?php foreach ($warnings as $warning): ?>
        <div class="alert alert--warning" role="alert"><?= $icon('warning') ?> <span><?= $e($warning) ?></span></div>
    <?php endforeach; ?>

    <nav class="subtabs trash__subtabs" role="tablist" aria-label="Source des éléments">
        <a class="subtabs__tab" role="tab" href="#" data-route="<?= $e($tabRoute('')) ?>" aria-selected="<?= $source === '' ? 'true' : 'false' ?>">Tous <span class="badge badge--muted"><?= (int) $counts[''] ?></span></a>
        <a class="subtabs__tab" role="tab" href="#" data-route="<?= $e($tabRoute('module')) ?>" aria-selected="<?= $source === 'module' ? 'true' : 'false' ?>">Données des modules <span class="badge badge--muted"><?= (int) $counts['module'] ?></span></a>
        <a class="subtabs__tab" role="tab" href="#" data-route="<?= $e($tabRoute('attachment')) ?>" aria-selected="<?= $source === 'attachment' ? 'true' : 'false' ?>">Pièces jointes <span class="badge badge--muted"><?= (int) $counts['attachment'] ?></span></a>
    </nav>

    <form class="toolbar trash__filters" data-action="filter" data-auto-submit novalidate>
        <input type="hidden" name="source" value="<?= $e($source) ?>">
        <input type="hidden" name="sort" value="<?= $e($sort) ?>">
        <input type="hidden" name="dir" value="<?= $e($direction) ?>">
        <div class="field input-icon trash__search">
            <?= $icon('search') ?>
            <label class="sr-only" for="trash-search">Rechercher un élément supprimé</label>
            <input class="input" type="search" id="trash-search" name="q" value="<?= $e($search) ?>" placeholder="Rechercher dans les libellés…" autocomplete="off">
        </div>
        <div class="field">
            <label class="sr-only" for="trash-module">Module</label>
            <select class="select" id="trash-module" name="module">
                <option value="">Tous les modules</option>
                <?php foreach ($modules as $id => $name): ?>
                    <option value="<?= $e($id) ?>"<?= $moduleFilter === $id ? ' selected' : '' ?>><?= $e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">Filtrer</button>
        <?php if ($hasFilters): ?>
            <a class="btn btn--ghost" href="#" data-route="list"><?= $icon('close') ?> Effacer</a>
        <?php endif; ?>
        <span class="toolbar__spacer"></span>
        <span class="text-muted text-small"><?= (int) $total ?> résultat<?= $total > 1 ? 's' : '' ?> · conservation <?= (int) $retention ?> jours</span>
    </form>

    <?php if ($items === []): ?>
        <?php if ($countAll === 0): ?>
            <?= $module->renderCore('state', [
                'type' => 'empty',
                'title' => 'La corbeille est vide',
                'message' => 'Les éléments supprimés par les modules et les pièces jointes supprimées apparaissent ici pendant ' . (int) $retention . ' jours avant leur suppression définitive.',
            ]) ?>
        <?php else: ?>
            <?= $module->renderCore('state', [
                'type' => 'empty',
                'title' => 'Aucun élément ne correspond',
                'message' => 'Modifiez la recherche ou les filtres pour afficher d’autres éléments supprimés.',
                'actions' => '<a class="btn" href="#" data-route="list"><svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg> Effacer les filtres</a>',
            ]) ?>
        <?php endif; ?>
    <?php else: ?>
        <form data-action="restore-many" data-trash-bulk novalidate>
            <div class="table-wrap">
                <table class="table trash__table">
                    <thead>
                    <tr>
                        <th class="col-check"><label class="checkbox" title="Tout sélectionner sur cette page"><input type="checkbox" data-trash-select-all aria-label="Tout sélectionner sur cette page"></label></th>
                        <th class="trash__col-label"><?= $module->renderCore('sort_header', ['label' => 'Libellé', 'column' => 'label', 'sort' => $sort, 'direction' => $direction, 'route' => 'list', 'query' => $sortQuery]) ?></th>
                        <th><?= $module->renderCore('sort_header', ['label' => 'Type', 'column' => 'type', 'sort' => $sort, 'direction' => $direction, 'route' => 'list', 'query' => $sortQuery]) ?></th>
                        <th><?= $module->renderCore('sort_header', ['label' => 'Module', 'column' => 'module', 'sort' => $sort, 'direction' => $direction, 'route' => 'list', 'query' => $sortQuery]) ?></th>
                        <th class="trash__col-date"><?= $module->renderCore('sort_header', ['label' => 'Supprimé le', 'column' => 'deleted_at', 'sort' => $sort, 'direction' => $direction, 'route' => 'list', 'query' => $sortQuery]) ?></th>
                        <th class="trash__col-date"><?= $module->renderCore('sort_header', ['label' => 'Purge prévue le', 'column' => 'purge_at', 'sort' => $sort, 'direction' => $direction, 'route' => 'list', 'query' => $sortQuery]) ?></th>
                        <th class="col-actions">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php $soon = $item['expires_in_days'] !== null && $item['expires_in_days'] < $warningDays; ?>
                        <tr<?= $soon ? ' class="is-warning"' : '' ?>>
                            <td class="col-check"><label class="checkbox"><input type="checkbox" name="ids[]" value="<?= $e($item['key']) ?>" data-can-restore="<?= $item['can_restore'] ? '1' : '0' ?>" data-can-purge="<?= $item['can_purge'] ? '1' : '0' ?>" aria-label="Sélectionner « <?= $e($item['label']) ?> »"></label></td>
                            <td class="trash__col-label">
                                <span class="trash__label"><?= $icon($item['source'] === 'attachment' ? 'paperclip' : 'file', 'icon--sm') ?> <span><?= $e($item['label']) ?></span></span>
                                <?php if (($item['context'] ?? '') !== ''): ?><span class="trash__context text-muted text-small"><?= $e($item['context']) ?></span><?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($item['source'] === 'attachment'): ?>
                                    <span class="badge badge--muted">Pièce jointe</span>
                                <?php else: ?>
                                    <span class="badge badge--info" title="Jeu de données <?= $e($item['dataset']) ?>"><?= $e($item['type_label']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($item['source'] === 'module'): ?>
                                    <a href="#" data-open-module="<?= $e($item['module_id']) ?>" title="Ouvrir le module"><?= $e($item['module_name']) ?></a>
                                <?php else: ?>
                                    <?= $e($item['module_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="trash__col-date text-nowrap"><?= $e($datetime($item['deleted_at'])) ?></td>
                            <td class="trash__col-date text-nowrap">
                                <?php if ($item['purge_at'] === null): ?>
                                    <span class="text-muted">—</span>
                                <?php elseif ($soon): ?>
                                    <span class="badge badge--warning" title="Purge automatique le <?= $e($datetime($item['purge_at'])) ?>"><?= $icon('warning', 'icon--sm') ?> <?= (int) $item['expires_in_days'] === 0 ? 'imminente' : 'dans ' . (int) $item['expires_in_days'] . ' j' ?></span>
                                    <span class="text-small text-muted"><?= $e($datetime($item['purge_at'])) ?></span>
                                <?php else: ?>
                                    <?= $e($datetime($item['purge_at'])) ?> <span class="text-muted text-small">(<?= (int) $item['expires_in_days'] ?> j)</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-actions text-nowrap">
                                <?php if ($item['can_restore']): ?>
                                    <button type="button" class="btn btn--sm" data-action="restore" data-params="<?= $e($params($item)) ?>" title="Restaurer « <?= $e($item['label']) ?> »">
                                        <?= $icon('refresh') ?> Restaurer
                                    </button>
                                <?php endif; ?>
                                <?php if ($item['can_purge']): ?>
                                    <button type="button" class="btn btn--sm btn--outline-danger" data-action="purge" data-params="<?= $e($params($item)) ?>" data-confirm="Supprimer définitivement « <?= $e($item['label']) ?> » ? Cette action est irréversible." data-confirm-title="Suppression définitive" data-confirm-label="Supprimer définitivement" data-danger title="Supprimer définitivement">
                                        <?= $icon('trash') ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="toolbar trash__bulkbar">
                <span class="text-small"><strong data-trash-selected-count>0</strong> sélectionné(s)</span>
                <?php if ($canRestoreAny): ?>
                    <button type="submit" class="btn btn--sm" data-bulk="restore-many" disabled><?= $icon('refresh') ?> Restaurer la sélection</button>
                <?php endif; ?>
                <?php if ($canPurgeAny): ?>
                    <button type="submit" class="btn btn--sm btn--outline-danger" data-bulk="purge-many" data-bulk-confirm="Supprimer définitivement les éléments sélectionnés ? Cette action est irréversible." disabled><?= $icon('trash') ?> Supprimer définitivement la sélection</button>
                <?php endif; ?>
                <span class="toolbar__spacer"></span>
                <span class="text-muted text-small">Purge automatique <?= (int) $retention ?> jours après la suppression.</span>
            </div>
        </form>

        <?= $module->renderCore('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'route' => 'list', 'query' => $query]) ?>
    <?php endif; ?>
</div>
