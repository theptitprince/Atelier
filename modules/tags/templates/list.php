<?php
/**
 * Liste des tags partagés : recherche, tri, nuage et tableau (bascule par onglets internes).
 * Variables : $tags (list décorée : usage_count, level, author), $q, $sort, $dir, $query (array),
 *             $total, $canManage, $module, $e, $datetime.
 */
$sortQuery = array_diff_key($query, ['sort' => 1, 'dir' => 1]);
$sortOptions = [
    'list?' . http_build_query($sortQuery + ['sort' => 'name', 'dir' => 'asc']) => 'Nom (A → Z)',
    'list?' . http_build_query($sortQuery + ['sort' => 'name', 'dir' => 'desc']) => 'Nom (Z → A)',
    'list?' . http_build_query($sortQuery + ['sort' => 'usage', 'dir' => 'desc']) => 'Usage (décroissant)',
    'list?' . http_build_query($sortQuery + ['sort' => 'usage', 'dir' => 'asc']) => 'Usage (croissant)',
];
$currentSort = 'list?' . http_build_query($sortQuery + ['sort' => $sort, 'dir' => $dir]);
?>
<div class="module module-tags">
    <form class="toolbar tags__toolbar" data-action="search" data-auto-submit novalidate>
        <input type="hidden" name="sort" value="<?= $e($sort) ?>">
        <input type="hidden" name="dir" value="<?= $e($dir) ?>">
        <div class="field input-icon tags__search">
            <svg class="icon" aria-hidden="true"><use href="#i-search"></use></svg>
            <label class="sr-only" for="tags-search">Rechercher un tag</label>
            <input class="input" type="search" id="tags-search" name="q" value="<?= $e($q) ?>" placeholder="Rechercher un tag…" autocomplete="off">
        </div>
        <button type="submit" class="btn">Rechercher</button>
        <?php if ($q !== ''): ?>
            <a class="btn btn--ghost" href="#" data-route="list<?= $sortQuery === [] ? '' : '?' . $e(http_build_query($sortQuery)) ?>">
                <svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg> Effacer
            </a>
        <?php endif; ?>
        <span class="toolbar__spacer"></span>
        <label class="field field--inline tags__sort">
            <span class="field__label">Trier par</span>
            <select class="select" data-route-select aria-label="Trier les tags">
                <?php foreach ($sortOptions as $route => $label): ?>
                    <option value="<?= $e($route) ?>"<?= $route === $currentSort ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <?php if ($tags === []): ?>
        <?php if ($q !== ''): ?>
            <?= $module->renderCore('state', [
                'type' => 'empty',
                'title' => 'Aucun tag ne correspond',
                'message' => 'Aucun tag ne commence par « ' . $q . ' ». Essayez un autre terme.',
            ]) ?>
        <?php else: ?>
            <?= $module->renderCore('state', [
                'type' => 'empty',
                'title' => 'Aucun tag partagé pour le moment',
                'message' => 'Les tags sont créés depuis les modules (par exemple en enregistrant une note avec des tags).',
            ]) ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="subtabs" role="tablist" data-subtabs="tags-list-panels">
            <button type="button" class="subtabs__tab" role="tab" data-subtab="cloud" aria-selected="true">
                <svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg> Nuage
            </button>
            <button type="button" class="subtabs__tab" role="tab" data-subtab="table" aria-selected="false">
                <svg class="icon icon--sm" aria-hidden="true"><use href="#i-list"></use></svg> Tableau
            </button>
        </div>

        <div id="tags-list-panels">
            <div data-subtab-panel="cloud">
                <div class="card">
                    <div class="card__body">
                        <div class="chips tag-cloud">
                            <?php foreach ($tags as $tag): ?>
                                <a class="chip tag-cloud__item tag-cloud__item--<?= (int) $tag['level'] ?>" href="#" data-route="detail/<?= (int) $tag['id'] ?>" title="<?= $e($tag['usage_count']) ?> utilisation<?= (int) $tag['usage_count'] > 1 ? 's' : '' ?>">
                                    <svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg>
                                    <span><?= $e($tag['name']) ?></span>
                                    <span class="tag-cloud__count"><?= (int) $tag['usage_count'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-muted text-small tags__legend">La taille d’un tag reflète son nombre d’utilisations. Cliquez sur un tag pour voir les informations qui le portent.</p>
                    </div>
                </div>
            </div>

            <div data-subtab-panel="table" hidden>
                <div class="table-wrap">
                    <table class="table tags__table">
                        <thead>
                        <tr>
                            <th><?= $module->renderCore('sort_header', ['label' => 'Tag', 'column' => 'name', 'sort' => $sort, 'direction' => $dir, 'route' => 'list', 'query' => $sortQuery]) ?></th>
                            <th class="col-num"><?= $module->renderCore('sort_header', ['label' => 'Utilisations', 'column' => 'usage', 'sort' => $sort, 'direction' => $dir, 'route' => 'list', 'query' => $sortQuery]) ?></th>
                            <th class="tags__col-date">Créé le</th>
                            <th>Auteur</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tags as $tag): ?>
                            <tr<?= (int) $tag['usage_count'] === 0 ? ' class="tags__row--unused"' : '' ?>>
                                <td>
                                    <a class="tags__name" href="#" data-route="detail/<?= (int) $tag['id'] ?>">
                                        <svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg>
                                        <span><?= $e($tag['name']) ?></span>
                                    </a>
                                </td>
                                <td class="col-num">
                                    <?php if ((int) $tag['usage_count'] === 0): ?>
                                        <span class="badge badge--muted">inutilisé</span>
                                    <?php else: ?>
                                        <?= (int) $tag['usage_count'] ?>
                                    <?php endif; ?>
                                </td>
                                <td class="tags__col-date text-nowrap"><?= $e($datetime($tag['created_at'] ?? null)) ?></td>
                                <td><?= $e($tag['author']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
