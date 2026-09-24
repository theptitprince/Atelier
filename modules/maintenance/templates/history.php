<?php
/**
 * Historique des interventions : filtres (équipement, année, recherche), pagination, coût total, export CSV,
 * documents de chaque intervention (dépliant : liste, téléchargement, dépôt).
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var int $cost coût total des lignes filtrées (centimes)
 * @var array{q: string, asset: int, year: int, page: int, per_page: int} $query
 * @var list<array<string, mixed>> $assets
 * @var list<int> $years
 * @var array<int, array{info_id: ?string, files: list<array<string, mixed>>, tags: list<string>}> $documents pièces jointes et tags par intervention
 * @var bool $attachmentsModule
 * @var array<string, bool> $rights
 * @var bool $canExport
 * @var string $exportUrl
 * @var list<int> $perPageChoices
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$filtered = $query['q'] !== '' || $query['asset'] > 0 || $query['year'] > 0;
$pageQuery = array_filter(['q' => $query['q'], 'asset' => $query['asset'] > 0 ? $query['asset'] : null, 'year' => $query['year'] > 0 ? $query['year'] : null, 'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null], static fn ($v): bool => $v !== null && $v !== '');
$dash = '<span class="text-muted">—</span>';
?>
<div class="module module-maintenance">
    <form class="card maintenance__filters" data-action="filter-history" data-auto-submit novalidate>
        <div class="card__body">
            <div class="toolbar mb-0">
                <div class="field grow">
                    <label class="sr-only" for="maintenance-log-q">Recherche</label>
                    <div class="input-icon">
                        <?= $module->icon('search', 'icon--sm') ?>
                        <input class="input" type="search" id="maintenance-log-q" name="q" value="<?= $e($query['q']) ?>" placeholder="Intervention, intervenant, équipement…" autocomplete="off">
                    </div>
                </div>
                <label class="field field--inline">
                    <span class="field__label">Équipement</span>
                    <select class="select select--sm maintenance__select" name="asset" aria-label="Équipement">
                        <option value="">Tous</option>
                        <?php foreach ($assets as $asset): ?>
                            <option value="<?= (int) $asset['id'] ?>"<?= $query['asset'] === (int) $asset['id'] ? ' selected' : '' ?>><?= $e($asset['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field field--inline">
                    <span class="field__label">Année</span>
                    <select class="select select--sm maintenance__select" name="year" aria-label="Année">
                        <option value="">Toutes</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= (int) $year ?>"<?= $query['year'] === (int) $year ? ' selected' : '' ?>><?= (int) $year ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn"><?= $module->icon('filter') ?> Filtrer</button>
                <a class="btn btn--ghost" href="#" data-route="history"<?= $filtered ? '' : ' aria-disabled="true"' ?>><?= $module->icon('close') ?> Effacer</a>
                <?php if ($canExport): ?>
                    <a class="btn" href="<?= $e($exportUrl) ?>" download title="Exporter les interventions filtrées au format CSV"><?= $module->icon('download') ?> CSV</a>
                <?php endif; ?>
                <label class="field field--inline">
                    <span class="field__label">Par page</span>
                    <select class="select select--sm maintenance__per-page" data-route-select aria-label="Interventions par page">
                        <?php foreach ($perPageChoices as $option): ?>
                            <option value="<?= $e('history?' . http_build_query(array_filter($pageQuery + ['per_page' => $option], static fn ($v): bool => $v !== null && $v !== ''))) ?>"<?= $query['per_page'] === $option ? ' selected' : '' ?>><?= (int) $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $filtered ? 'Aucune intervention ne correspond aux filtres' : 'Aucune intervention enregistrée',
            'message' => $filtered ? 'Modifiez les filtres.' : 'Enregistrez une intervention réalisée depuis un équipement, une tâche ou le bouton ci-dessus.',
            'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="log/new">Nouvelle intervention</a>' : '',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table maintenance__table">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Intervention</th>
                    <th>Équipement</th>
                    <th class="col-num">Compteur</th>
                    <th class="col-num">Coût</th>
                    <th>Intervenant</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $log): ?>
                    <tr>
                        <td class="text-nowrap mono"><?= $e($module->day($log['done_at'])) ?></td>
                        <td>
                            <strong><?= $e($log['title']) ?></strong>
                            <?php if ($log['job_id'] !== null && $log['job_deleted_at'] === null): ?><a class="text-small text-muted" href="#" data-route="job/<?= (int) $log['job_id'] ?>" title="Tâche : <?= $e($log['job_title'] ?? '') ?>"><?= $module->icon('clock', 'icon--sm') ?></a><?php endif; ?>
                            <?php if ($log['notes'] !== null && $log['notes'] !== ''): ?><div class="text-small text-muted maintenance__excerpt"><?= $e(\Atelier\Support\Str::truncate(\Atelier\View\BbCode::toText($log['notes']), 140)) ?></div><?php endif; ?>
                            <?php $docs = $documents[$log['id']] ?? ['info_id' => null, 'files' => [], 'tags' => []]; $docCount = count($docs['files']); ?>
                            <?php if ($docs['tags'] !== []): ?><span class="chips maintenance__tags" title="Tags partagés"><?php foreach ($docs['tags'] as $tag): ?><span class="chip"><?= $e($tag) ?></span><?php endforeach; ?></span><?php endif; ?>
                            <?php if ($docCount > 0 || $rights['update']): ?>
                                <details class="maintenance__docs">
                                    <summary class="text-small<?= $docCount > 0 ? '' : ' text-muted' ?>"><?= $module->icon('paperclip', 'icon--sm') ?> <?= $docCount > 0 ? $docCount . ' document' . ($docCount > 1 ? 's' : '') : 'Joindre un document' ?></summary>
                                    <div class="maintenance__docs-body">
                                        <?= $module->partial('_attachments', ['target' => 'log', 'id' => (int) $log['id'], 'infoId' => $docs['info_id'], 'attachments' => $docs['files'], 'canUpdate' => $rights['update'], 'attachmentsModule' => $attachmentsModule]) ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td><a href="#" data-route="asset/<?= (int) $log['asset_id'] ?>"><?= $e($log['asset_name']) ?></a></td>
                        <td class="col-num text-nowrap"><?= $e($module->meter($log['meter_value'], $log['asset_meter_unit'])) ?></td>
                        <td class="col-num text-nowrap"><?= $e($module->money($log['cost'])) ?></td>
                        <td><?= $log['performed_by'] !== null ? $e($log['performed_by']) : $dash ?></td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <?php if ($rights['update']): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="log/<?= (int) $log['id'] ?>/edit" title="Modifier, joindre une facture"><?= $module->icon('edit') ?></a><?php endif; ?>
                                <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="log-delete" data-params='{"id":<?= (int) $log['id'] ?>}' data-confirm="Mettre cette intervention à la corbeille ? Elle pourra être restaurée pendant la durée de rétention." data-danger title="Mettre à la corbeille"><?= $module->icon('trash') ?></button><?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr><th colspan="4" class="text-right">Total<?= $filtered ? ' (filtré)' : '' ?></th><th class="col-num text-nowrap"><?= $e($module->money($cost, '0,00 €')) ?></th><th colspan="2"></th></tr>
                </tfoot>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => 'history', 'query' => $pageQuery]) ?>
    <?php endif; ?>
</div>
