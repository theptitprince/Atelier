<?php
/**
 * Recherche dans le registre des informations partagées.
 * Variables : $rows (list), $total, $q, $dataset, $moduleFilter, $tagName, $sort, $page, $perPage, $query (array),
 *             $hasFilters (bool), $datasets (array code => meta), $modules (array id => nom), $module (objet module), $e, $datetime.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$filterLink = static function (array $changes) use ($query): string {
    $params = array_filter($changes + $query, static fn ($v): bool => $v !== '' && $v !== null);
    unset($params['page']);
    return 'search' . ($params === [] ? '' : '?' . http_build_query($params));
};
?>
<div class="module module-explorer">
    <form class="toolbar explorer__filters" data-action="search" data-auto-submit novalidate>
        <div class="field input-icon explorer__search">
            <svg class="icon" aria-hidden="true"><use href="#i-search"></use></svg>
            <label class="sr-only" for="explorer-q">Rechercher dans les libellés</label>
            <input class="input" type="search" id="explorer-q" name="q" value="<?= $e($q) ?>" placeholder="Rechercher un libellé…" autocomplete="off">
        </div>
        <div class="field explorer__filter">
            <label class="sr-only" for="explorer-dataset">Jeu de données</label>
            <select class="select" id="explorer-dataset" name="dataset">
                <option value="">Tous les jeux de données</option>
                <?php foreach ($datasets as $code => $meta): ?>
                    <option value="<?= $e($code) ?>"<?= $code === $dataset ? ' selected' : '' ?>><?= $e($meta['name']) ?> (<?= $e($meta['module_name']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field explorer__filter">
            <label class="sr-only" for="explorer-module">Module</label>
            <select class="select" id="explorer-module" name="module">
                <option value="">Tous les modules</option>
                <?php foreach ($modules as $id => $name): ?>
                    <option value="<?= $e($id) ?>"<?= $id === $moduleFilter ? ' selected' : '' ?>><?= $e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field explorer__filter explorer__filter--tag">
            <label class="sr-only" for="explorer-tag">Tag</label>
            <input class="input" type="text" id="explorer-tag" name="tag" value="<?= $e($tagName) ?>" placeholder="Filtrer par tag…" autocomplete="off" data-tags-input data-tags-max="1">
        </div>
        <div class="field explorer__filter">
            <label class="sr-only" for="explorer-sort">Tri</label>
            <select class="select" id="explorer-sort" name="sort">
                <option value="label"<?= $sort === 'label' ? ' selected' : '' ?>>Tri : libellé</option>
                <option value="recent"<?= $sort === 'recent' ? ' selected' : '' ?>>Tri : plus récentes</option>
                <option value="dataset"<?= $sort === 'dataset' ? ' selected' : '' ?>>Tri : jeu de données</option>
            </select>
        </div>
        <button type="submit" class="btn"><?= $icon('filter') ?> Filtrer</button>
        <?php if ($hasFilters): ?>
            <a class="btn btn--ghost" href="#" data-route="search<?= $sort === 'label' ? '' : '?sort=' . $e($sort) ?>"><?= $icon('close') ?> Effacer</a>
        <?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $hasFilters ? 'Aucune information ne correspond' : 'Aucune information partagée',
            'message' => $hasFilters
                ? 'Modifiez les filtres ou effacez-les pour élargir la recherche.'
                : ($datasets === []
                    ? 'Aucun jeu de données partagé n’est lisible avec vos droits.'
                    : 'Les modules enregistrent leurs informations dans le registre commun lorsqu’elles sont taguées, reliées ou reçoivent une pièce jointe.'),
            'actions' => $hasFilters ? '<a class="btn" href="#" data-route="search">' . $icon('close') . ' Effacer les filtres</a>' : '',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table explorer__results">
                <thead>
                <tr>
                    <th>Information</th>
                    <th>Jeu de données</th>
                    <th>Module</th>
                    <th>Tags</th>
                    <th class="col-num" title="Relations"><?= $icon('link', 'icon--sm') ?><span class="sr-only">Relations</span></th>
                    <th class="col-num" title="Pièces jointes"><?= $icon('paperclip', 'icon--sm') ?><span class="sr-only">Pièces jointes</span></th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="explorer__col-label">
                            <a class="explorer__label" href="#" data-route="info/<?= $e($row['id']) ?>" title="Détail de l’information">
                                <?= $icon('file', 'icon--sm text-muted') ?><span><?= $e($row['label'] ?? ('#' . $row['local_key'])) ?></span>
                            </a>
                            <span class="text-small text-muted">clé <?= $e($row['local_key']) ?> · <?= $e($datetime($row['created_at'])) ?></span>
                        </td>
                        <td><a href="#" class="explorer__facet" data-route="<?= $e($filterLink(['dataset' => $row['dataset_code']])) ?>" title="Filtrer sur ce jeu de données"><?= $e($row['dataset_name']) ?></a></td>
                        <td><a href="#" class="badge badge--muted explorer__facet" data-route="<?= $e($filterLink(['module' => $row['module_id']])) ?>" title="Filtrer sur ce module"><?= $e($row['module_name']) ?></a></td>
                        <td>
                            <?php if ($row['tags'] === []): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <span class="chips">
                                    <?php foreach ($row['tags'] as $tag): ?>
                                        <a class="chip explorer__chip" href="#" data-route="<?= $e($filterLink(['tag' => $tag['name']])) ?>" title="Filtrer sur ce tag"><?= $icon('tag', 'icon--sm') ?><?= $e($tag['name']) ?></a>
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="col-num"><?= (int) $row['relation_count'] ?: '<span class="text-muted">0</span>' ?></td>
                        <td class="col-num"><?= (int) $row['attachment_count'] ?: '<span class="text-muted">0</span>' ?></td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <a class="btn btn--sm btn--ghost btn--icon" href="#" data-route="info/<?= $e($row['id']) ?>" title="Détail" aria-label="Détail"><?= $icon('eye') ?></a>
                                <?php if ($row['open_route'] !== null): ?>
                                    <a class="btn btn--sm btn--ghost btn--icon" href="#" data-open-module="<?= $e($row['module_id']) ?>" data-open-route="<?= $e($row['open_route']) ?>" title="Ouvrir dans le module <?= $e($row['module_name']) ?>" aria-label="Ouvrir dans le module"><?= $icon('external') ?></a>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'route' => 'search', 'query' => $query]) ?>
    <?php endif; ?>
</div>
