<?php
/**
 * Liste des points GPS : recherche (texte ou coordonnées → points proches), tri, pagination.
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var array{q: string, sort: string, dir: string, page: int, per_page: int} $query
 * @var \Atelier\Modules\Geo\Coordinates|null $center
 * @var float $radiusKm
 * @var array<string, bool> $rights
 * @var list<int> $perPageChoices
 * @var string $route
 * @var \Atelier\Modules\Geo\GeoModule $module
 */
$sortQuery = array_filter(['q' => $query['q'], 'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null]);
$sortHeader = static fn (string $label, string $column): string => $center !== null
    ? $e($label)
    : $module->renderCore('sort_header', ['label' => $label, 'column' => $column, 'sort' => $query['sort'], 'direction' => $query['dir'], 'route' => 'list', 'query' => $sortQuery]);
$pageQuery = array_filter(['q' => $query['q'], 'sort' => $query['sort'] !== 'name' ? $query['sort'] : null, 'dir' => $query['dir'] !== 'asc' ? $query['dir'] : null, 'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null]);
?>
<div class="module module-geo">
    <form class="card geo__filters" data-action="filter" data-auto-submit novalidate>
        <div class="card__body">
            <div class="toolbar mb-0">
                <div class="field grow">
                    <label class="sr-only" for="geo-q">Recherche</label>
                    <div class="input-icon">
                        <svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg>
                        <input class="input" type="search" id="geo-q" name="q" value="<?= $e($query['q']) ?>" placeholder="Nom, code, adresse… ou des coordonnées pour trouver les points proches" autocomplete="off">
                    </div>
                </div>
                <button type="submit" class="btn">
                    <svg class="icon" aria-hidden="true"><use href="#i-search"></use></svg> Rechercher
                </button>
                <a class="btn btn--ghost" href="#" data-route="list"<?= $query['q'] === '' ? ' aria-disabled="true"' : '' ?>>
                    <svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg> Effacer
                </a>
                <label class="field field--inline">
                    <span class="field__label">Par page</span>
                    <select class="select select--sm geo__per-page" data-route-select aria-label="Points par page">
                        <?php foreach ($perPageChoices as $option): ?>
                            <option value="<?= $e('list?' . http_build_query(array_filter($pageQuery + ['per_page' => $option, 'page' => null], static fn ($v): bool => $v !== null && $v !== ''))) ?>"<?= $query['per_page'] === $option ? ' selected' : '' ?>><?= (int) $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p class="field__help mb-0 mt-2">Formats de coordonnées acceptés : <code>48.8566, 2.3522</code> · <code>48°51'24"N 2°21'03"E</code> · <code>N 48°51.400' E 2°21.050'</code>. Une recherche par coordonnées liste les points à moins de <?= (int) $radiusKm ?> km.</p>
        </div>
    </form>

    <?php if ($center !== null): ?>
        <div class="alert alert--info" role="status">
            <svg class="icon" aria-hidden="true"><use href="#i-navigation"></use></svg>
            <div>
                <p class="alert__title">Points à proximité de <?= $e($center->dms()) ?> <span class="text-muted">(<?= $e($center->decimal()) ?>)</span></p>
                <p class="mb-0">
                    <?= $total === 0 ? 'Aucun point référencé à moins de ' . (int) $radiusKm . ' km.' : $total . ' point(s) trié(s) du plus proche au plus éloigné.' ?>
                    <?php if ($rights['create']): ?>
                        <a href="#" data-route="new?coordinates=<?= $e(rawurlencode($center->decimal())) ?>">Créer un point à ces coordonnées</a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $query['q'] !== '' ? 'Aucun point ne correspond à la recherche' : 'Aucun point référencé',
            'message' => $query['q'] !== '' ? 'Modifiez le terme ou les coordonnées recherchées.' : 'Créez un premier point ou importez un fichier CSV.',
            'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="new">Nouveau point</a>' : '',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table geo__table">
                <thead>
                <tr>
                    <th><?= $sortHeader('Nom', 'name') ?></th>
                    <th><?= $sortHeader('Code', 'code') ?></th>
                    <th>Coordonnées</th>
                    <th class="col-num"><?= $sortHeader('Altitude', 'altitude') ?></th>
                    <th>Adresse</th>
                    <?php if ($center !== null): ?><th class="col-num">Distance</th><?php endif; ?>
                    <th class="col-num" title="Informations rattachées et pièces jointes">Liens</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="#" data-route="show/<?= (int) $row['id'] ?>" class="geo__name"><?= $e($row['name']) ?></a></td>
                        <td><?= $row['code'] !== null && $row['code'] !== '' ? '<code>' . $e($row['code']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="geo__coords">
                            <span class="mono"><?= $e($row['dms']) ?></span>
                            <span class="text-muted text-small mono"><?= $e($row['decimal']) ?></span>
                        </td>
                        <td class="col-num"><?= $row['altitude'] !== null ? $e(number_format((float) $row['altitude'], 0, ',', ' ')) . ' m' : '<span class="text-muted">—</span>' ?></td>
                        <td class="truncate geo__cell-address" title="<?= $e($row['address'] ?? '') ?>"><?= $row['address'] !== null && $row['address'] !== '' ? $e($row['address']) : '<span class="text-muted">—</span>' ?></td>
                        <?php if ($center !== null): ?><td class="col-num"><?= $e($module->formatDistance((float) $row['distance_km'])) ?></td><?php endif; ?>
                        <td class="col-num">
                            <?php if ((int) $row['links'] > 0): ?><span class="badge badge--info" title="Informations rattachées"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-link"></use></svg> <?= (int) $row['links'] ?></span><?php endif; ?>
                            <?php if ((int) $row['attachments'] > 0): ?><span class="badge badge--muted" title="Pièces jointes"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-paperclip"></use></svg> <?= (int) $row['attachments'] ?></span><?php endif; ?>
                            <?php if ((int) $row['links'] === 0 && (int) $row['attachments'] === 0): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="show/<?= (int) $row['id'] ?>" title="Fiche du point" aria-label="Fiche du point <?= $e($row['name']) ?>"><svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg></a>
                                <?php if ($rights['update']): ?>
                                    <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="edit/<?= (int) $row['id'] ?>" title="Modifier" aria-label="Modifier <?= $e($row['name']) ?>"><svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg></a>
                                <?php endif; ?>
                                <?php if ($rights['delete']): ?>
                                    <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="delete" data-params='{"id":<?= (int) $row['id'] ?>}' data-confirm="Mettre « <?= $e($row['name']) ?> » à la corbeille ?" data-danger title="Supprimer" aria-label="Supprimer <?= $e($row['name']) ?>"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => 'list', 'query' => $pageQuery]) ?>
    <?php endif; ?>
</div>
