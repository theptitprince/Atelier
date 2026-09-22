<?php
/**
 * Liste paginée du journal d'activité.
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var \Atelier\Modules\Activity\ActivityFilters $filters
 * @var list<array<string, mixed>> $users
 * @var list<string> $modules
 * @var list<string> $actions
 * @var array<string, array{0: string, 1: string}> $results
 * @var list<int> $perPageOptions
 * @var \Atelier\Modules\Activity\ActivityModule $module
 */
$sortQuery = $filters->toQuery(false);
if ($filters->perPage() !== 25) {
    $sortQuery['per_page'] = $filters->perPage();
}
$sortHeader = static fn (string $label, string $column): string => $module->renderCore('sort_header', [
    'label' => $label,
    'column' => $column,
    'sort' => $filters->sort(),
    'direction' => $filters->direction(),
    'route' => 'list',
    'query' => $sortQuery,
]);
$pageQuery = $filters->toQuery(true);
?>
<div class="module module-activity">
    <form class="card activity__filters" data-action="filter" data-auto-submit novalidate>
        <div class="card__body">
            <div class="activity__filters-grid">
                <div class="field">
                    <label class="field__label" for="activity-from">Du</label>
                    <input class="input input--sm" type="date" id="activity-from" name="from" value="<?= $e($filters->value('from')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="activity-to">Au</label>
                    <input class="input input--sm" type="date" id="activity-to" name="to" value="<?= $e($filters->value('to')) ?>">
                </div>
                <div class="field">
                    <label class="field__label" for="activity-user">Utilisateur</label>
                    <select class="select select--sm" id="activity-user" name="user_id">
                        <option value="">Tous</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= (int) $user['id'] ?>"<?= $filters->value('user_id') === (string) $user['id'] ? ' selected' : '' ?>><?= $e($user['display_name']) ?> (<?= $e($user['username']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="activity-module">Module</label>
                    <select class="select select--sm" id="activity-module" name="module">
                        <option value="">Tous</option>
                        <?php foreach ($modules as $moduleId): ?>
                            <option value="<?= $e($moduleId) ?>"<?= $filters->value('module') === $moduleId ? ' selected' : '' ?>><?= $e($moduleId) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="activity-action">Type d’action</label>
                    <select class="select select--sm" id="activity-action" name="action">
                        <option value="">Toutes</option>
                        <?php foreach ($actions as $action): ?>
                            <option value="<?= $e($action) ?>"<?= $filters->value('action') === $action ? ' selected' : '' ?>><?= $e($action) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="activity-result">Résultat</label>
                    <select class="select select--sm" id="activity-result" name="result">
                        <option value="">Tous</option>
                        <?php foreach ($results as $code => [$label]): ?>
                            <option value="<?= $e($code) ?>"<?= $filters->value('result') === $code ? ' selected' : '' ?>><?= $e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="activity-category">Catégorie</label>
                    <select class="select select--sm" id="activity-category" name="category">
                        <option value="">Toutes</option>
                        <?php foreach (\Atelier\Activity\ActivityLog::categoryLabels() as $code => $label): ?>
                            <option value="<?= $e($code) ?>"<?= $filters->value('category') === $code ? ' selected' : '' ?>><?= $e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="activity-request">Requête</label>
                    <input class="input input--sm mono" type="text" id="activity-request" name="request" value="<?= $e($filters->value('request')) ?>" placeholder="identifiant de requête" autocomplete="off" title="Toutes les entrées produites par un même traitement">
                </div>
                <div class="field">
                    <label class="field__label" for="activity-resource">Ressource</label>
                    <input class="input input--sm" type="text" id="activity-resource" name="resource" value="<?= $e($filters->value('resource')) ?>" placeholder="ex. user:3" autocomplete="off">
                </div>
                <div class="field">
                    <label class="field__label" for="activity-q">Recherche</label>
                    <div class="input-icon">
                        <svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg>
                        <input class="input input--sm" type="search" id="activity-q" name="q" value="<?= $e($filters->value('q')) ?>" placeholder="Message, ressource, utilisateur, référence" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="toolbar activity__filters-actions">
                <button type="submit" class="btn btn--sm btn--primary">
                    <svg class="icon" aria-hidden="true"><use href="#i-filter"></use></svg> Filtrer
                </button>
                <a class="btn btn--sm btn--ghost" href="#" data-route="list"<?= $filters->isActive() ? '' : ' aria-disabled="true"' ?>>
                    <svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg> Réinitialiser les filtres
                </a>
                <span class="toolbar__spacer"></span>
                <label class="field field--inline">
                    <span class="field__label">Par page</span>
                    <select class="select select--sm activity__per-page" data-route-select aria-label="Entrées par page">
                        <?php foreach ($perPageOptions as $option): ?>
                            <option value="<?= $e($filters->route('list', ['per_page' => $option, 'page' => null])) ?>"<?= $filters->perPage() === $option ? ' selected' : '' ?>><?= (int) $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $filters->isActive() ? 'Aucune entrée ne correspond aux filtres' : 'Le journal est vide',
            'message' => $filters->isActive() ? 'Élargissez la période ou retirez un critère.' : 'Les actions des utilisateurs apparaîtront ici au fil de l’eau.',
            'actions' => $filters->isActive() ? '<a class="btn btn--sm" href="#" data-route="list">Réinitialiser les filtres</a>' : '',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact activity__table">
                <thead>
                <tr>
                    <th><?= $sortHeader('Date', 'occurred_at') ?></th>
                    <th><?= $sortHeader('Catégorie', 'category') ?></th>
                    <th><?= $sortHeader('Utilisateur', 'username') ?></th>
                    <th><?= $sortHeader('Module', 'module_id') ?></th>
                    <th><?= $sortHeader('Action', 'action') ?></th>
                    <th><?= $sortHeader('Résultat', 'result') ?></th>
                    <th>Ressource</th>
                    <th>Message</th>
                    <th class="col-num"><?= $sortHeader('Durée', 'duration_ms') ?></th>
                    <th>Requête</th>
                    <th class="col-actions">Détail</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php [$resultLabel, $resultTone] = $results[$row['result']] ?? [(string) $row['result'], 'muted']; ?>
                    <?php $category = (string) ($row['category'] ?? 'data'); $categoryTone = ['security' => 'warning', 'admin' => 'info', 'technical' => 'danger', 'debug' => 'muted'][$category] ?? ''; ?>
                    <tr class="activity__row--<?= $e($category) ?>">
                        <td class="text-nowrap"><?= $e(\Atelier\Support\Clock::formatDateTimeSeconds($row['occurred_at'])) ?></td>
                        <td><span class="badge<?= $categoryTone !== '' ? ' badge--' . $categoryTone : '' ?>"><?= $e(\Atelier\Activity\ActivityLog::categoryLabels()[$category] ?? $category) ?></span></td>
                        <td class="text-nowrap"><?= $row['username'] !== null && $row['username'] !== '' ? $e($row['username']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $e($row['module_id']) ?></td>
                        <td><code><?= $e($row['action']) ?></code></td>
                        <td><span class="badge badge--<?= $e($resultTone) ?>"><?= $e($resultLabel) ?></span></td>
                        <td class="truncate activity__cell-resource"><?= $row['resource_ref'] !== null && $row['resource_ref'] !== '' ? '<code>' . $e($row['resource_ref']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="truncate activity__cell-message" title="<?= $e($row['message'] ?? '') ?>"><?= $e($row['message'] ?? '') ?></td>
                        <td class="col-num text-muted"><?= isset($row['duration_ms']) && $row['duration_ms'] !== null ? (int) $row['duration_ms'] . ' ms' : '' ?></td>
                        <td><?php if (!empty($row['request_id'])): ?><a class="mono text-small" href="#" data-route="<?= $e('list?request=' . $row['request_id']) ?>" title="Toutes les entrées de cette requête"><?= $e(substr((string) $row['request_id'], 0, 8)) ?></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                        <td class="col-actions">
                            <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="detail/<?= (int) $row['id'] ?>" title="Voir le détail de l’entrée n° <?= (int) $row['id'] ?>" aria-label="Voir le détail de l’entrée n° <?= (int) $row['id'] ?>">
                                <svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', [
            'page' => $filters->page(),
            'perPage' => $filters->perPage(),
            'total' => $total,
            'route' => 'list',
            'query' => $pageQuery,
        ]) ?>
    <?php endif; ?>
</div>
