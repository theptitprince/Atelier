<?php
/**
 * Liste paginée des comptes utilisateurs.
 * @var list<array> $rows @var int $total @var array $query @var array $filterQuery @var list<array> $groups
 * @var array<string, bool> $rights @var int $currentUserId @var list<int> $perPageChoices
 * @var array<string, string> $statusFilters @var array<string, int> $counts
 * @var \Atelier\Modules\Users\UsersModule $module
 */
use Atelier\Modules\Users\UserPresenter;

$sortHeader = static fn (string $label, string $column): string => $module->renderCore('sort_header', [
    'label' => $label,
    'column' => $column,
    'sort' => $query['sort'],
    'direction' => $query['dir'],
    'route' => 'list',
    'query' => array_diff_key($filterQuery, ['sort' => 1, 'dir' => 1]),
]);
$hasFilters = $query['search'] !== '' || $query['status'] !== '' || $query['group'] > 0;
?>
<div class="module module-users">
    <div data-password-slot></div>

    <form class="toolbar users-filters" data-action="filter" data-auto-submit role="search" aria-label="Filtrer les utilisateurs">
        <input type="hidden" name="sort" value="<?= $e($query['sort']) ?>">
        <input type="hidden" name="dir" value="<?= $e($query['dir']) ?>">
        <div class="field input-icon users-filters__search">
            <svg class="icon" aria-hidden="true"><use href="#i-search"></use></svg>
            <input class="input" type="search" name="search" value="<?= $e($query['search']) ?>" placeholder="Rechercher (identifiant, nom, courriel)…" aria-label="Rechercher" autocomplete="off">
        </div>
        <div class="field">
            <select class="select" name="status" aria-label="Filtrer par état">
                <option value="">Tous les états</option>
                <?php foreach ($statusFilters as $code => $label): ?>
                    <option value="<?= $e($code) ?>"<?= $query['status'] === $code ? ' selected' : '' ?>><?= $e($label) ?> (<?= (int) ($counts[$code] ?? 0) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <select class="select" name="group" aria-label="Filtrer par groupe">
                <option value="">Tous les groupes</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?= (int) $group['id'] ?>"<?= $query['group'] === (int) $group['id'] ? ' selected' : '' ?>><?= $e($group['label']) ?> (<?= (int) $group['member_count'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <select class="select" name="per_page" aria-label="Lignes par page">
                <?php foreach ($perPageChoices as $choice): ?>
                    <option value="<?= (int) $choice ?>"<?= $query['per_page'] === $choice ? ' selected' : '' ?>><?= (int) $choice ?> / page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn" title="Appliquer les filtres"><svg class="icon" aria-hidden="true"><use href="#i-filter"></use></svg><span>Filtrer</span></button>
        <?php if ($hasFilters): ?>
            <a class="btn btn--ghost" href="#" data-route="list" title="Effacer les filtres"><svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg><span>Effacer</span></a>
        <?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $hasFilters ? 'Aucun compte ne correspond aux filtres' : 'Aucun compte utilisateur',
            'message' => $hasFilters ? 'Modifiez ou effacez les filtres pour élargir la recherche.' : 'Créez un premier compte avec le bouton « Ajouter ».',
            'actions' => $hasFilters ? '<a class="btn" href="#" data-route="list">Effacer les filtres</a>' : ($rights['create'] ? '<a class="btn btn--primary" href="#" data-route="new">Ajouter un utilisateur</a>' : ''),
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table users-table">
                <thead>
                <tr>
                    <th><?= $sortHeader('Identifiant', 'username') ?></th>
                    <th><?= $sortHeader('Nom affiché', 'display_name') ?></th>
                    <th>Courriel</th>
                    <th>Groupes</th>
                    <th><?= $sortHeader('État', 'status') ?></th>
                    <th><?= $sortHeader('Dernière connexion', 'last_login_at') ?></th>
                    <?php if ($rights['update'] || $rights['delete']): ?><th class="col-actions">Actions</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr class="<?= $row['status'] !== 'active' ? 'is-disabled' : '' ?>">
                        <td class="text-nowrap">
                            <?php if ($rights['update']): ?>
                                <a href="#" data-route="edit/<?= (int) $row['id'] ?>" class="users-table__username"><?= $e($row['username']) ?></a>
                            <?php else: ?>
                                <span class="users-table__username"><?= $e($row['username']) ?></span>
                            <?php endif; ?>
                            <?php if ((int) $row['id'] === $currentUserId): ?><span class="badge badge--info" title="Votre compte">vous</span><?php endif; ?>
                        </td>
                        <td><?= $e($row['display_name']) ?></td>
                        <td class="text-muted"><?= $e($row['email'] ?? '') ?></td>
                        <td>
                            <?php if ($row['groups'] === []): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <div class="chips">
                                    <?php foreach ($row['groups'] as $group): ?>
                                        <a class="chip" href="#" data-route="list?group=<?= (int) $group['id'] ?>" title="Filtrer sur ce groupe"><?= $e($group['label']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="users-table__status"><?= UserPresenter::statusBadges($row) ?></td>
                        <td class="text-nowrap text-muted"><?= $e($datetime($row['last_login_at']) ?: 'jamais') ?></td>
                        <?php if ($rights['update'] || $rights['delete']): ?>
                            <td class="col-actions"><div class="table-actions"><?= $module->quickActions($row, $rights, $currentUserId, true) ?></div></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => 'list', 'query' => $filterQuery]) ?>
    <?php endif; ?>
</div>
