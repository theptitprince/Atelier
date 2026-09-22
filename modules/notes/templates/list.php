<?php
/**
 * Liste des notes de l'utilisateur : recherche, tri, pagination.
 * Variables : $notes (list), $total, $search, $sort, $direction, $page, $perPage, $query (array), $rights (array), $module, $e, $datetime.
 */
$sortQuery = array_diff_key($query, ['sort' => 1, 'dir' => 1]);
?>
<div class="module module-notes">
    <form class="toolbar notes__toolbar" data-action="search" data-auto-submit novalidate>
        <input type="hidden" name="sort" value="<?= $e($sort) ?>">
        <input type="hidden" name="dir" value="<?= $e($direction) ?>">
        <div class="field input-icon notes__search">
            <svg class="icon" aria-hidden="true"><use href="#i-search"></use></svg>
            <label class="sr-only" for="notes-search">Rechercher dans mes notes</label>
            <input class="input" type="search" id="notes-search" name="search" value="<?= $e($search) ?>" placeholder="Rechercher dans le titre ou le contenu…" autocomplete="off">
        </div>
        <button type="submit" class="btn">Rechercher</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn--ghost" href="#" data-route="list<?= $sortQuery === [] ? '' : '?' . $e(http_build_query($sortQuery)) ?>">
                <svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg> Effacer
            </a>
        <?php endif; ?>
        <span class="toolbar__spacer"></span>
        <span class="text-muted text-small"><?= $total ?> résultat<?= $total > 1 ? 's' : '' ?></span>
    </form>

    <?php if ($notes === []): ?>
        <?php if ($search !== ''): ?>
            <?= $module->renderCore('state', [
                'type' => 'empty',
                'title' => 'Aucune note ne correspond',
                'message' => 'Aucune note ne contient « ' . $search . ' ». Essayez un autre terme.',
            ]) ?>
        <?php else: ?>
            <?= $module->renderCore('state', [
                'type' => 'empty',
                'title' => 'Aucune note pour le moment',
                'message' => 'Vos notes sont personnelles : vous seul pouvez les lire et les modifier.',
                'actions' => ($rights['create'] ?? false)
                    ? '<a class="btn btn--primary" href="#" data-route="new"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg> Créer ma première note</a>'
                    : '',
            ]) ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table notes__table">
                <thead>
                <tr>
                    <th class="notes__col-title"><?= $module->renderCore('sort_header', ['label' => 'Titre', 'column' => 'title', 'sort' => $sort, 'direction' => $direction, 'route' => 'list', 'query' => $sortQuery]) ?></th>
                    <th>Extrait</th>
                    <th class="notes__col-tags">Tags</th>
                    <th class="notes__col-date"><?= $module->renderCore('sort_header', ['label' => 'Modifiée le', 'column' => 'updated_at', 'sort' => $sort, 'direction' => $direction, 'route' => 'list', 'query' => $sortQuery]) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($notes as $note): ?>
                    <tr>
                        <td class="notes__col-title">
                            <a class="notes__title" href="#" data-route="edit/<?= (int) $note['id'] ?>">
                                <svg class="icon icon--sm" aria-hidden="true"><use href="#i-note"></use></svg>
                                <span><?= $e($note['title']) ?></span>
                            </a>
                        </td>
                        <td class="notes__excerpt text-muted"><?= $note['excerpt'] === '' ? '<em>Note vide</em>' : $e($note['excerpt']) ?></td>
                        <td class="notes__col-tags">
                            <?php if ($note['tags'] !== []): ?>
                                <span class="chips">
                                    <?php foreach ($note['tags'] as $tag): ?>
                                        <span class="chip" title="Tag partagé"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg><?= $e($tag) ?></span>
                                    <?php endforeach; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="notes__col-date text-nowrap" title="Créée le <?= $e($datetime($note['created_at'])) ?>"><?= $e($datetime($note['updated_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'route' => 'list', 'query' => $query]) ?>
    <?php endif; ?>
</div>
