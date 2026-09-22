<?php
/**
 * Liste des fichiers joints (portée « mine » ou « all »).
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var array{q: string, kind: string, linked: string, sort: string, dir: string, page: int, per_page: int} $query
 * @var string $scope
 * @var array<string, bool> $rights
 * @var bool $assist
 * @var int $currentUserId
 * @var array<string, array{0: string, 1: list<string>}> $kinds
 * @var list<int> $perPageChoices
 * @var string $route
 * @var array{mine: array<string, int>, quota: int, global: array<string, int>|null, max_total: int} $usage
 * @var bool $encryption
 * @var \Atelier\Modules\Attachments\AttachmentsModule $module
 */
$base = $scope === 'all' ? 'all' : 'list';
$filtered = $query['q'] !== '' || $query['kind'] !== '' || $query['linked'] !== '';
$sortQuery = array_filter(['q' => $query['q'], 'kind' => $query['kind'], 'linked' => $query['linked'], 'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null], static fn ($v): bool => $v !== null && $v !== '');
$sortHeader = static fn (string $label, string $column): string => $module->renderCore('sort_header', ['label' => $label, 'column' => $column, 'sort' => $query['sort'], 'direction' => $query['dir'], 'route' => $base, 'query' => $sortQuery]);
$pageQuery = $sortQuery + array_filter(['sort' => $query['sort'] !== 'created_at' ? $query['sort'] : null, 'dir' => $query['dir'] !== 'desc' ? $query['dir'] : null]);
$percent = static fn (int $used, int $max): int => $max > 0 ? (int) min(100, round($used * 100 / $max)) : 0;
?>
<div class="module module-attachments">
    <div class="attachments__kpis">
        <div class="card card--compact">
            <div class="card__body kpi">
                <span class="kpi__value"><?= $e($module->humanSize($usage['mine']['size'])) ?> <span class="text-muted text-small">/ <?= $e($module->humanSize($usage['quota'])) ?></span></span>
                <span class="kpi__label">Mon espace utilisé · <?= (int) $usage['mine']['count'] ?> fichier(s)</span>
                <progress class="attachments__quota" max="100" value="<?= $percent($usage['mine']['size'], $usage['quota']) ?>" aria-label="Quota personnel utilisé"></progress>
            </div>
        </div>
        <?php if ($usage['global'] !== null): ?>
            <div class="card card--compact">
                <div class="card__body kpi">
                    <span class="kpi__value"><?= $e($module->humanSize($usage['global']['size'])) ?> <span class="text-muted text-small">/ <?= $e($module->humanSize($usage['max_total'])) ?></span></span>
                    <span class="kpi__label">Espace global · <?= (int) $usage['global']['count'] ?> fichier(s), <?= (int) $usage['global']['trashed'] ?> en corbeille</span>
                    <progress class="attachments__quota" max="100" value="<?= $percent($usage['global']['size'], $usage['max_total']) ?>" aria-label="Quota global utilisé"></progress>
                </div>
            </div>
        <?php endif; ?>
        <div class="card card--compact">
            <div class="card__body kpi">
                <span class="kpi__value"><svg class="icon icon--lg <?= $encryption ? 'text-success' : 'text-warning' ?>" aria-hidden="true"><use href="#i-<?= $encryption ? 'lock' : 'unlock' ?>"></use></svg> <?= $encryption ? 'Chiffrés' : 'Non chiffrés' ?></span>
                <span class="kpi__label"><?= $encryption ? 'Stockage AES-256-GCM, déchiffrement à la volée après contrôle des droits' : 'Le chiffrement au repos est désactivé dans la configuration' ?></span>
            </div>
        </div>
    </div>

    <form class="card attachments__filters" data-action="filter" data-auto-submit novalidate>
        <input type="hidden" name="scope" value="<?= $e($scope) ?>">
        <div class="card__body toolbar mb-0">
            <div class="field grow">
                <label class="sr-only" for="att-q">Recherche</label>
                <div class="input-icon">
                    <svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg>
                    <input class="input" type="search" id="att-q" name="q" value="<?= $e($query['q']) ?>" placeholder="Nom, description ou information rattachée" autocomplete="off">
                </div>
            </div>
            <label class="field field--inline">
                <span class="field__label">Type</span>
                <select class="select select--sm" name="kind" aria-label="Type de fichier">
                    <option value="">Tous</option>
                    <?php foreach ($kinds as $code => [$label]): ?>
                        <option value="<?= $e($code) ?>"<?= $query['kind'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field field--inline">
                <span class="field__label">Rattachement</span>
                <select class="select select--sm" name="linked" aria-label="Rattachement">
                    <option value="">Indifférent</option>
                    <option value="1"<?= $query['linked'] === '1' ? ' selected' : '' ?>>Rattachés</option>
                    <option value="0"<?= $query['linked'] === '0' ? ' selected' : '' ?>>Orphelins</option>
                </select>
            </label>
            <a class="btn btn--sm btn--ghost" href="#" data-route="<?= $e($base) ?>"<?= $filtered ? '' : ' aria-disabled="true"' ?>><svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg> Réinitialiser</a>
            <label class="field field--inline">
                <span class="field__label">Par page</span>
                <select class="select select--sm attachments__per-page" data-route-select aria-label="Fichiers par page">
                    <?php foreach ($perPageChoices as $option): ?>
                        <option value="<?= $e($base . '?' . http_build_query(array_filter($pageQuery + ['per_page' => $option, 'page' => null], static fn ($v): bool => $v !== null && $v !== ''))) ?>"<?= $query['per_page'] === $option ? ' selected' : '' ?>><?= (int) $option ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $filtered ? 'Aucun fichier ne correspond aux filtres' : 'Aucun fichier',
            'message' => $filtered ? 'Modifiez la recherche ou les filtres.' : 'Téléversez un premier fichier ; il sera chiffré et accessible uniquement après contrôle des droits.',
            'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="upload">Téléverser</a>' : '',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table attachments__table">
                <thead>
                <tr>
                    <th class="col-icon"></th>
                    <th><?= $sortHeader('Nom', 'original_name') ?></th>
                    <th class="col-num"><?= $sortHeader('Taille', 'size') ?></th>
                    <th><?= $sortHeader('Téléversé', 'created_at') ?></th>
                    <?php if ($scope === 'all'): ?><th><?= $sortHeader('Auteur', 'uploader') ?></th><?php endif; ?>
                    <th>Rattaché à</th>
                    <th class="col-num" title="Téléchargements"><?= $sortHeader('Téléch.', 'downloads') ?></th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $manage = $assist || (int) ($row['uploaded_by'] ?? 0) === $currentUserId; ?>
                    <tr>
                        <td class="col-icon"><svg class="icon text-muted" aria-hidden="true"><use href="#i-<?= $e($module::kindIcon((string) $row['mime'])) ?>"></use></svg></td>
                        <td class="attachments__cell-name">
                            <a href="#" data-route="show/<?= $e($row['id']) ?>" class="attachments__name truncate" title="<?= $e($row['original_name']) ?>"><?= $e($row['original_name']) ?></a>
                            <?php if ($row['description'] !== null && $row['description'] !== ''): ?><span class="text-muted text-small truncate" title="<?= $e($row['description']) ?>"><?= $e($row['description']) ?></span><?php endif; ?>
                        </td>
                        <td class="col-num text-nowrap"><?= $e($module->humanSize((int) $row['size'])) ?></td>
                        <td class="text-nowrap"><?= $e($datetime($row['created_at'])) ?></td>
                        <?php if ($scope === 'all'): ?><td class="text-nowrap"><?= $row['uploader'] !== null ? $e($row['uploader_name'] ?? $row['uploader']) : '<span class="text-muted">—</span>' ?></td><?php endif; ?>
                        <td class="truncate attachments__cell-info">
                            <?php if ($row['info_id'] !== null): ?>
                                <span title="<?= $e($row['info_dataset']) ?>"><?= $e($row['info_label'] !== null && $row['info_label'] !== '' ? $row['info_label'] : $row['info_dataset'] . ' #' . $row['info_key']) ?></span>
                                <span class="text-muted text-small">· <?= $e($row['info_module']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-num"><?= (int) $row['downloads'] ?></td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <?php if ($rights['read']): ?>
                                    <a class="btn btn--sm btn--icon btn--ghost" href="<?= $e($module->url('download/' . $row['id'])) ?>" download title="Télécharger" aria-label="Télécharger <?= $e($row['original_name']) ?>"><svg class="icon" aria-hidden="true"><use href="#i-download"></use></svg></a>
                                <?php endif; ?>
                                <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="show/<?= $e($row['id']) ?>" title="Détail" aria-label="Détail de <?= $e($row['original_name']) ?>"><svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg></a>
                                <?php if ($manage && $rights['delete']): ?>
                                    <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="delete" data-params='{"id":"<?= $e($row['id']) ?>"}' data-confirm="Mettre « <?= $e($row['original_name']) ?> » à la corbeille ?" data-danger title="Supprimer" aria-label="Supprimer <?= $e($row['original_name']) ?>"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => $base, 'query' => $pageQuery]) ?>
    <?php endif; ?>
</div>
