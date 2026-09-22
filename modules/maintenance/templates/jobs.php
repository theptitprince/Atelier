<?php
/**
 * Liste des tâches d'entretien (ou des pannes), triées par urgence.
 * @var list<array<string, mixed>> $rows (avec state)
 * @var array{q: string, asset: int, kind: string, status: string, state: string} $query
 * @var string $screen jobs|defects
 * @var list<array<string, mixed>> $assets
 * @var array<string, string> $kinds
 * @var array<string, string> $statuses
 * @var array<string, bool> $rights
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$isDefects = $screen === 'defects';
$filtered = $query['q'] !== '' || $query['asset'] > 0 || $query['kind'] !== '' || $query['status'] !== 'open' || $query['state'] !== '';
?>
<div class="module module-maintenance">
    <form class="card maintenance__filters" data-action="filter-jobs" data-auto-submit novalidate>
        <input type="hidden" name="screen" value="<?= $e($screen) ?>">
        <div class="card__body">
            <div class="toolbar mb-0">
                <div class="field grow">
                    <label class="sr-only" for="maintenance-job-q">Recherche</label>
                    <div class="input-icon">
                        <?= $module->icon('search', 'icon--sm') ?>
                        <input class="input" type="search" id="maintenance-job-q" name="q" value="<?= $e($query['q']) ?>" placeholder="Titre de la tâche ou nom de l’équipement" autocomplete="off">
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
                <?php if (!$isDefects): ?>
                    <label class="field field--inline">
                        <span class="field__label">Nature</span>
                        <select class="select select--sm maintenance__select" name="kind" aria-label="Nature">
                            <option value="">Toutes</option>
                            <?php foreach ($kinds as $code => $label): ?>
                                <option value="<?= $e($code) ?>"<?= $query['kind'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label class="field field--inline">
                    <span class="field__label">État</span>
                    <select class="select select--sm maintenance__select" name="status" aria-label="État">
                        <option value="open"<?= $query['status'] === 'open' ? ' selected' : '' ?>>Ouvertes</option>
                        <option value="closed"<?= $query['status'] === 'closed' ? ' selected' : '' ?>>Clôturées</option>
                        <option value="all"<?= $query['status'] === '' ? ' selected' : '' ?>>Toutes</option>
                    </select>
                </label>
                <label class="checkbox field--inline"><input type="checkbox" name="state" value="alert"<?= $query['state'] === 'alert' ? ' checked' : '' ?>> En rappel seulement</label>
                <button type="submit" class="btn"><?= $module->icon('filter') ?> Filtrer</button>
                <a class="btn btn--ghost" href="#" data-route="<?= $e($screen) ?>"<?= $filtered ? '' : ' aria-disabled="true"' ?>><?= $module->icon('close') ?> Effacer</a>
            </div>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => $filtered ? 'Aucune tâche ne correspond aux filtres' : ($isDefects ? 'Aucune panne ouverte' : 'Aucune tâche d’entretien'),
            'message' => $filtered ? 'Modifiez les filtres.' : ($isDefects ? 'Signalez un défaut constaté pour le suivre jusqu’à sa réparation.' : 'Planifiez un premier entretien : vidange, contrôle technique, entretien de chaudière…'),
            'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="job/new' . ($isDefects ? '?kind=corrective' : '') . '">' . ($isDefects ? 'Signaler une panne' : 'Nouvelle tâche') . '</a>' : '',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table maintenance__table">
                <thead>
                <tr>
                    <th>État</th>
                    <th>Tâche</th>
                    <th>Équipement</th>
                    <th>Priorité</th>
                    <?php if (!$isDefects): ?><th>Périodicité</th><?php endif; ?>
                    <th><?= $isDefects ? 'À traiter avant' : 'Échéance' ?></th>
                    <th><?= $isDefects ? 'Signalée le' : 'Dernière fois' ?></th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $job): ?>
                    <?php $unit = $job['asset_meter_unit']; $jid = (int) $job['id']; ?>
                    <tr class="maintenance__row--<?= $e($job['state']['code']) ?>">
                        <td><?= $module->stateBadge($job['state'], $unit) ?></td>
                        <td><a href="#" data-route="job/<?= $jid ?>" class="maintenance__name"><?= $e($job['title']) ?></a> <?= $isDefects || $query['kind'] !== '' ? '' : $module->kindBadge((string) $job['kind']) ?></td>
                        <td><a href="#" data-route="asset/<?= (int) $job['asset_id'] ?>"><?= $e($job['asset_name']) ?></a></td>
                        <td><?= $module->priorityBadge((string) $job['priority']) ?></td>
                        <?php if (!$isDefects): ?><td class="text-small"><?= $e($module->intervalLabel($job['interval_days'], $job['interval_meter'], $unit)) ?></td><?php endif; ?>
                        <td class="text-nowrap"><?= $e($module->dueLabel($job['next_due_at'], $job['next_due_meter'], $unit)) ?><?= $job['status'] === 'open' && $job['state']['code'] !== 'none' ? '<span class="text-muted text-small"> · ' . $e($module->stateDetail($job['state'], $unit)) . '</span>' : '' ?></td>
                        <td class="text-nowrap"><?= $isDefects ? $e($date($job['created_at'])) : $e($module->dueLabel($job['last_done_at'], $job['last_done_meter'], $unit)) ?></td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <?php if ($rights['create'] && $job['status'] === 'open'): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="log/new?job=<?= $jid ?>" title="<?= $isDefects ? 'Réparée : enregistrer l’intervention' : 'Marquer comme fait' ?>" aria-label="Marquer « <?= $e($job['title']) ?> » comme fait"><?= $module->icon('check') ?></a><?php endif; ?>
                                <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="job/<?= $jid ?>" title="Fiche de la tâche" aria-label="Fiche de « <?= $e($job['title']) ?> »"><?= $module->icon('eye') ?></a>
                                <?php if ($rights['update']): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="job/<?= $jid ?>/edit" title="Modifier" aria-label="Modifier « <?= $e($job['title']) ?> »"><?= $module->icon('edit') ?></a><?php endif; ?>
                                <a class="btn btn--sm btn--icon btn--ghost" href="<?= $e($module->url('job/' . $jid . '/print')) ?>" target="_blank" rel="noopener" title="Imprimer la fiche" aria-label="Imprimer la fiche « <?= $e($job['title']) ?> »"><?= $module->icon('print') ?></a>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
