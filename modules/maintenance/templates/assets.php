<?php
/**
 * Liste des équipements : recherche, catégorie, tri, pagination.
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var array{q: string, category: string, sort: string, dir: string, page: int, per_page: int} $query
 * @var array<string, string> $categories
 * @var array<int, int> $openByAsset
 * @var array<int, int> $alertsByAsset gravité maximale des rappels par équipement
 * @var array<string, bool> $rights
 * @var list<int> $perPageChoices
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$keep = array_filter(['q' => $query['q'], 'category' => $query['category'], 'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null], static fn ($v): bool => $v !== null && $v !== '');
$sortHeader = static fn (string $label, string $column): string => $module->renderCore('sort_header', ['label' => $label, 'column' => $column, 'sort' => $query['sort'], 'direction' => $query['dir'], 'route' => 'assets', 'query' => $keep]);
$pageQuery = $keep + array_filter(['sort' => $query['sort'] !== 'name' ? $query['sort'] : null, 'dir' => $query['dir'] !== 'asc' ? $query['dir'] : null]);
?>
<div class="module module-maintenance">
    <form class="card maintenance__filters" data-action="filter-assets" data-auto-submit novalidate>
        <div class="card__body">
            <div class="toolbar mb-0">
                <div class="field grow">
                    <label class="sr-only" for="maintenance-asset-q">Recherche</label>
                    <div class="input-icon">
                        <?= $module->icon('search', 'icon--sm') ?>
                        <input class="input" type="search" id="maintenance-asset-q" name="q" value="<?= $e($query['q']) ?>" placeholder="Nom, marque, modèle, immatriculation, emplacement…" autocomplete="off">
                    </div>
                </div>
                <label class="field field--inline">
                    <span class="field__label">Catégorie</span>
                    <select class="select select--sm maintenance__select" name="category" aria-label="Catégorie">
                        <option value="">Toutes</option>
                        <?php foreach ($categories as $code => $label): ?>
                            <option value="<?= $e($code) ?>"<?= $query['category'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn"><?= $module->icon('search') ?> Rechercher</button>
                <a class="btn btn--ghost" href="#" data-route="assets"<?= $query['q'] === '' && $query['category'] === '' ? ' aria-disabled="true"' : '' ?>><?= $module->icon('close') ?> Effacer</a>
                <label class="field field--inline">
                    <span class="field__label">Par page</span>
                    <select class="select select--sm maintenance__per-page" data-route-select aria-label="Équipements par page">
                        <?php foreach ($perPageChoices as $option): ?>
                            <option value="<?= $e('assets?' . http_build_query(array_filter($pageQuery + ['per_page' => $option], static fn ($v): bool => $v !== null && $v !== ''))) ?>"<?= $query['per_page'] === $option ? ' selected' : '' ?>><?= (int) $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $query['q'] !== '' || $query['category'] !== '' ? 'Aucun équipement ne correspond aux filtres' : 'Aucun équipement référencé',
            'message' => $query['q'] !== '' || $query['category'] !== '' ? 'Modifiez la recherche ou la catégorie.' : 'Commencez par référencer votre voiture, votre chaudière ou un appareil.',
            'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="asset/new">Nouvel équipement</a>' : '',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table maintenance__table">
                <thead>
                <tr>
                    <th><?= $sortHeader('Équipement', 'name') ?></th>
                    <th><?= $sortHeader('Catégorie', 'category') ?></th>
                    <th>Marque / modèle</th>
                    <th>Identifiant</th>
                    <th class="col-num"><?= $sortHeader('Compteur', 'meter_value') ?></th>
                    <th class="col-num">Tâches</th>
                    <th>Rappels</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $id = (int) $row['id']; $severity = $alertsByAsset[$id] ?? 0; ?>
                    <tr>
                        <td><a href="#" data-route="asset/<?= $id ?>" class="maintenance__name"><?= $module->icon($module->categoryIcon((string) $row['category']), 'icon--sm text-muted') ?> <?= $e($row['name']) ?></a><?= $row['location'] !== null ? '<span class="text-muted text-small"> · ' . $e($row['location']) . '</span>' : '' ?></td>
                        <td><?= $e($categories[$row['category']] ?? $row['category']) ?></td>
                        <td><?= $e(trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></td>
                        <td><?= $row['identifier'] !== null ? '<code>' . $e($row['identifier']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="col-num text-nowrap"><?= $e($module->meter($row['meter_value'], $row['meter_unit'])) ?></td>
                        <td class="col-num"><?= isset($openByAsset[$id]) ? '<span class="badge badge--muted">' . (int) $openByAsset[$id] . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if ($severity >= 4): ?><span class="badge badge--danger"><?= $module->icon('bell', 'icon--sm') ?> À faire</span>
                            <?php elseif ($severity === 3): ?><span class="badge badge--warning"><?= $module->icon('bell', 'icon--sm') ?> Bientôt</span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="asset/<?= $id ?>" title="Fiche de l’équipement" aria-label="Fiche de <?= $e($row['name']) ?>"><?= $module->icon('eye') ?></a>
                                <?php if ($rights['update'] && $row['meter_unit'] !== null): ?>
                                    <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="asset-meter" data-params='{"id":<?= $id ?>}' data-prompt="Nouveau relevé du compteur (<?= $e($row['meter_unit']) ?>)" data-prompt-field="meter_value" title="Relever le compteur" aria-label="Relever le compteur de <?= $e($row['name']) ?>"><?= $module->icon('hash') ?></button>
                                <?php endif; ?>
                                <?php if ($rights['update']): ?>
                                    <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="asset/<?= $id ?>/edit" title="Modifier" aria-label="Modifier <?= $e($row['name']) ?>"><?= $module->icon('edit') ?></a>
                                <?php endif; ?>
                                <?php if ($rights['delete']): ?>
                                    <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="asset-delete" data-params='{"id":<?= $id ?>}' data-confirm="Mettre « <?= $e($row['name']) ?> » à la corbeille ?" data-danger title="Supprimer" aria-label="Supprimer <?= $e($row['name']) ?>"><?= $module->icon('trash') ?></button>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => 'assets', 'query' => $pageQuery]) ?>
    <?php endif; ?>
</div>
