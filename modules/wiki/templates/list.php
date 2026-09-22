<?php
/**
 * Liste des pages.
 * @var list<array<string, mixed>> $rows @var int $total
 * @var array{q: string, sort: string, dir: string, page: int, per_page: int} $query
 * @var array<string, bool> $rights @var list<int> $perPageChoices @var array<string, mixed>|null $home
 * @var \Atelier\Modules\Wiki\WikiModule $module
 */
$tag = $query['tag'] ?? '';
$sortQuery = array_filter(['q' => $query['q'], 'tag' => $tag, 'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null]);
$sortHeader = static fn (string $label, string $column): string => $module->renderCore('sort_header', ['label' => $label, 'column' => $column, 'sort' => $query['sort'], 'direction' => $query['dir'], 'route' => 'list', 'query' => $sortQuery]);
$pageQuery = array_filter(['q' => $query['q'], 'tag' => $tag, 'sort' => $query['sort'] !== 'title' ? $query['sort'] : null, 'dir' => $query['dir'] !== 'asc' ? $query['dir'] : null, 'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null]);
$tagRoute = static fn (string $normalized): string => 'list?' . http_build_query(array_filter(['q' => $query['q'], 'tag' => $normalized], static fn ($v): bool => $v !== ''));
?>
<div class="module module-wiki">
    <form class="card card--compact" data-action="filter" data-auto-submit novalidate>
        <input type="hidden" name="tag" value="<?= $e($tag) ?>">
        <div class="card__body toolbar mb-0">
            <div class="field grow mb-0">
                <label class="sr-only" for="wiki-q">Recherche</label>
                <div class="input-icon"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg><input class="input" type="search" id="wiki-q" name="q" value="<?= $e($query['q']) ?>" placeholder="Rechercher dans les titres et les contenus" autocomplete="off"></div>
            </div>
            <button type="submit" class="btn"><svg class="icon" aria-hidden="true"><use href="#i-search"></use></svg> Rechercher</button>
            <a class="btn btn--ghost" href="#" data-route="list"<?= $query['q'] === '' && $tag === '' ? ' aria-disabled="true"' : '' ?>>Effacer</a>
            <?php if ($home !== null): ?><a class="btn" href="#" data-route="show/<?= $e($home['slug']) ?>"><svg class="icon" aria-hidden="true"><use href="#i-home"></use></svg> Accueil</a><?php endif; ?>
            <label class="field field--inline"><span class="field__label">Par page</span>
                <select class="select select--sm" data-route-select aria-label="Pages par page">
                    <?php foreach ($perPageChoices as $option): ?><option value="<?= $e('list?' . http_build_query(array_filter($pageQuery + ['per_page' => $option, 'page' => null], static fn ($v): bool => $v !== null && $v !== ''))) ?>"<?= $query['per_page'] === $option ? ' selected' : '' ?>><?= (int) $option ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
    </form>

    <?php if ($categories !== []): ?>
        <nav class="wiki__categories chips" aria-label="Catégories (tags)">
            <a class="chip<?= $tag === '' ? ' is-active' : '' ?>" href="#" data-route="<?= $e($tagRoute('')) ?>"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-grid"></use></svg> Toutes</a>
            <?php foreach ($categories as $category): ?>
                <a class="chip<?= $tag === $category['normalized'] ? ' is-active' : '' ?>" href="#" data-route="<?= $e($tagRoute($category['normalized'])) ?>" title="<?= (int) $category['count'] ?> page(s)"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg> <?= $e($category['name']) ?> <span class="wiki__category-count"><?= (int) $category['count'] ?></span></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => $query['q'] !== '' || $tag !== '' ? 'Aucune page ne correspond' : 'Aucune page', 'message' => $query['q'] !== '' || $tag !== '' ? 'Modifiez la recherche ou la catégorie.' : 'Créez une première page : titres, listes, liens [[entre pages]], images et fichiers joints, lieux GPS.', 'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="new">Nouvelle page</a>' : '']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table wiki__table">
                <thead><tr><th><?= $sortHeader('Titre', 'title') ?></th><th>Extrait</th><th>Tags</th><th class="col-num" title="Pages pointant vers celle-ci">Liens entrants</th><th><?= $sortHeader('Modifiée', 'updated_at') ?></th><th class="col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a class="wiki__title" href="#" data-route="show/<?= $e($row['slug']) ?>"><?= $e($row['title']) ?></a><span class="text-muted text-small"> v<?= (int) $row['revision'] ?></span></td>
                        <td class="truncate wiki__cell-excerpt" title="<?= $e($row['excerpt']) ?>"><?= $row['excerpt'] !== '' ? $e($row['excerpt']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?php if ($row['tags'] === []): ?><span class="text-muted">—</span><?php else: ?><span class="chips"><?php foreach ($row['tags'] as $rowTag): ?><a class="chip" href="#" data-route="<?= $e($tagRoute(\Atelier\Support\Str::normalizeTag($rowTag))) ?>" title="Filtrer sur ce tag"><?= $e($rowTag) ?></a><?php endforeach; ?></span><?php endif; ?></td>
                        <td class="col-num"><?= (int) $row['backlinks'] ?></td>
                        <td class="text-nowrap"><?= $e($datetime($row['updated_at'])) ?><?= $row['updated_by_name'] !== null ? '<span class="text-muted text-small"> · ' . $e($row['updated_by_name']) . '</span>' : '' ?></td>
                        <td class="col-actions"><span class="table-actions">
                            <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="show/<?= $e($row['slug']) ?>" title="Lire"><svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg></a>
                            <?php if ($rights['update']): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="edit/<?= (int) $row['id'] ?>" title="Modifier"><svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg></a><?php endif; ?>
                            <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="delete" data-params='{"id":<?= (int) $row['id'] ?>}' data-confirm="Mettre « <?= $e($row['title']) ?> » à la corbeille ?" data-danger title="Supprimer"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button><?php endif; ?>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => 'list', 'query' => $pageQuery]) ?>
    <?php endif; ?>
</div>
