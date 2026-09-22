<?php
/**
 * Tableau de bord : rappels (à faire / en retard, bientôt), pannes ouvertes, dernières interventions, indicateurs.
 * @var list<array<string, mixed>> $overdue tâches à faire ou en retard (avec state)
 * @var list<array<string, mixed>> $soon tâches dans la fenêtre de rappel
 * @var list<array<string, mixed>> $defects pannes ouvertes
 * @var list<array<string, mixed>> $recent dernières interventions
 * @var array{assets: int, openJobs: int, alerts: int, defects: int, cost12: int} $stats
 * @var array<string, bool> $rights
 * @var bool $canExport
 * @var string $today
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$jobRow = static function (array $job) use ($module, $e, $rights): string {
    $html = '<li class="list__item maintenance__reminder">'
        . $module->stateBadge($job['state'], $job['asset_meter_unit'])
        . '<span class="grow"><a href="#" data-route="job/' . (int) $job['id'] . '"><strong>' . $e($job['title']) . '</strong></a>'
        . ' <span class="text-muted">· ' . $e($job['asset_name']) . '</span>'
        . '<span class="text-small text-muted maintenance__reminder-detail">' . $e($module->stateDetail($job['state'], $job['asset_meter_unit'])) . ' · échéance ' . $e($module->dueLabel($job['next_due_at'], $job['next_due_meter'], $job['asset_meter_unit'])) . '</span></span>'
        . $module->priorityBadge((string) $job['priority']);
    if ($rights['create']) {
        $html .= '<a class="btn btn--sm" href="#" data-route="log/new?job=' . (int) $job['id'] . '" title="Enregistrer l’intervention">' . $module->icon('check') . ' Fait</a>';
    }
    return $html . '</li>';
};
?>
<div class="module module-maintenance">
    <div class="maintenance__stats">
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value"><?= (int) $stats['assets'] ?></span><span class="kpi__label">équipement<?= $stats['assets'] > 1 ? 's' : '' ?></span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value"><?= (int) $stats['openJobs'] ?></span><span class="kpi__label">tâche<?= $stats['openJobs'] > 1 ? 's' : '' ?> ouverte<?= $stats['openJobs'] > 1 ? 's' : '' ?></span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value<?= $stats['alerts'] > 0 ? ' text-danger' : ' text-success' ?>"><?= (int) $stats['alerts'] ?></span><span class="kpi__label">rappel<?= $stats['alerts'] > 1 ? 's' : '' ?> en cours</span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value<?= $stats['defects'] > 0 ? ' text-warning' : '' ?>"><?= (int) $stats['defects'] ?></span><span class="kpi__label">panne<?= $stats['defects'] > 1 ? 's' : '' ?> ouverte<?= $stats['defects'] > 1 ? 's' : '' ?></span></div></div>
        <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value"><?= $e($module->money($stats['cost12'], '0,00 €')) ?></span><span class="kpi__label">dépensés sur 12 mois</span></div></div>
    </div>

    <div class="maintenance__dashboard">
        <div class="maintenance__dashboard-main">
            <section class="card" aria-labelledby="maintenance-overdue-title">
                <div class="card__header">
                    <h2 class="card__title" id="maintenance-overdue-title"><?= $module->icon('bell', $overdue !== [] ? 'text-danger' : '') ?> À faire</h2>
                    <span class="badge <?= $overdue !== [] ? 'badge--danger' : 'badge--muted' ?>"><?= count($overdue) ?></span>
                </div>
                <div class="card__body<?= $overdue === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($overdue === []): ?>
                        <p class="text-muted mb-0">Aucune échéance atteinte ou dépassée. Tout est à jour.</p>
                    <?php else: ?>
                        <ul class="list"><?php foreach ($overdue as $job) { echo $jobRow($job); } ?></ul>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="maintenance-soon-title">
                <div class="card__header">
                    <h2 class="card__title" id="maintenance-soon-title"><?= $module->icon('clock', $soon !== [] ? 'text-warning' : '') ?> Bientôt</h2>
                    <span class="badge <?= $soon !== [] ? 'badge--warning' : 'badge--muted' ?>"><?= count($soon) ?></span>
                </div>
                <div class="card__body<?= $soon === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($soon === []): ?>
                        <p class="text-muted mb-0">Aucune tâche n’entre dans sa fenêtre de rappel anticipé.</p>
                    <?php else: ?>
                        <ul class="list"><?php foreach ($soon as $job) { echo $jobRow($job); } ?></ul>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="maintenance-defects-title">
                <div class="card__header">
                    <h2 class="card__title" id="maintenance-defects-title"><?= $module->icon('warning') ?> Pannes et défauts ouverts</h2>
                    <span class="badge <?= $defects !== [] ? 'badge--warning' : 'badge--muted' ?>"><?= count($defects) ?></span>
                </div>
                <div class="card__body<?= $defects === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($defects === []): ?>
                        <p class="text-muted mb-0">Aucune panne en cours.<?= $rights['create'] ? ' <a href="#" data-route="job/new?kind=corrective">Signaler un défaut</a>.' : '' ?></p>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($defects as $job): ?>
                                <li class="list__item maintenance__reminder">
                                    <?= $module->priorityBadge((string) $job['priority']) ?>
                                    <span class="grow"><a href="#" data-route="job/<?= (int) $job['id'] ?>"><strong><?= $e($job['title']) ?></strong></a> <span class="text-muted">· <?= $e($job['asset_name']) ?></span>
                                        <span class="text-small text-muted maintenance__reminder-detail">signalée le <?= $e($date($job['created_at'])) ?><?= $job['next_due_at'] !== null ? ' · à traiter avant le ' . $e($module->day($job['next_due_at'])) : '' ?></span></span>
                                    <?= in_array($job['state']['code'], \Atelier\Modules\Maintenance\Scheduler::ALERTING, true) ? $module->stateBadge($job['state']) : '' ?>
                                    <?php if ($rights['create']): ?><a class="btn btn--sm" href="#" data-route="log/new?job=<?= (int) $job['id'] ?>" title="Enregistrer la réparation"><?= $module->icon('check') ?> Réparée</a><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="maintenance__dashboard-side">
            <section class="card" aria-labelledby="maintenance-recent-title">
                <div class="card__header"><h2 class="card__title" id="maintenance-recent-title"><?= $module->icon('activity') ?> Dernières interventions</h2></div>
                <div class="card__body<?= $recent === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($recent === []): ?>
                        <p class="text-muted mb-0">Aucune intervention enregistrée.</p>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($recent as $log): ?>
                                <li class="list__item">
                                    <span class="text-muted text-small text-nowrap mono"><?= $e($module->day($log['done_at'])) ?></span>
                                    <span class="grow truncate"><a href="#" data-route="asset/<?= (int) $log['asset_id'] ?>" title="<?= $e($log['asset_name']) ?>"><?= $e($log['title']) ?></a> <span class="text-muted text-small">· <?= $e($log['asset_name']) ?></span></span>
                                    <span class="text-nowrap text-small"><?= $e($module->money($log['cost'], '')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="card__footer"><a class="btn btn--sm btn--ghost" href="#" data-route="history"><?= $module->icon('activity') ?> Tout l’historique</a></div>
            </section>

            <section class="card" aria-labelledby="maintenance-actions-title">
                <div class="card__header"><h2 class="card__title" id="maintenance-actions-title"><?= $module->icon('tool') ?> Raccourcis</h2></div>
                <div class="card__body">
                    <div class="flex flex--col gap-1">
                        <?php if ($rights['create']): ?>
                            <a class="btn" href="#" data-route="asset/new"><?= $module->icon('plus') ?> Nouvel équipement</a>
                            <a class="btn" href="#" data-route="job/new"><?= $module->icon('clock') ?> Planifier un entretien</a>
                            <a class="btn" href="#" data-route="job/new?kind=corrective"><?= $module->icon('warning') ?> Signaler une panne</a>
                            <a class="btn" href="#" data-route="log/new"><?= $module->icon('check') ?> Intervention réalisée</a>
                        <?php endif; ?>
                        <a class="btn btn--ghost" href="#" data-route="jobs?state=alert"><?= $module->icon('bell') ?> Toutes les tâches en rappel</a>
                        <?php if ($canExport): ?>
                            <a class="btn btn--ghost" href="<?= $e($module->url('reminders.ics')) ?>" download title="Un événement par échéance datée, avec alarme anticipée"><?= $module->icon('calendar') ?> Calendrier des rappels (.ics)</a>
                        <?php endif; ?>
                    </div>
                    <p class="text-small text-muted mt-3 mb-0">Le rappel anticipé se règle tâche par tâche (jours avant la date, unités avant le compteur). Le nombre de rappels s’affiche en badge dans la colonne de gauche.</p>
                </div>
            </section>
        </div>
    </div>
</div>
