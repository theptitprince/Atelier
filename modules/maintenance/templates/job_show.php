<?php
/**
 * Fiche d'une tâche (job card) : état et échéances, descriptif, pièces, contacts, outillage,
 * historique des réalisations, tags, pièces jointes.
 * @var array<string, mixed> $job (avec state)
 * @var string|null $infoId
 * @var list<array<string, mixed>> $logs
 * @var array<int, int> $logAttachments
 * @var list<array<string, mixed>> $tags
 * @var list<array<string, mixed>> $attachments
 * @var array<string, bool> $rights
 * @var bool $attachmentsModule
 * @var string $printUrl
 * @var string $baseUrl
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$id = (int) $job['id'];
$unit = $job['asset_meter_unit'];
$isCorrective = $job['kind'] === 'corrective';
$dash = '<span class="text-muted">—</span>';
$parts = $module->lines($job['parts']);
$contacts = $module->lines($job['contacts']);
$tools = $module->lines($job['tools']);
$stateClass = match ($job['state']['code']) { 'overdue', 'due' => 'alert--error', 'soon' => 'alert--warning', 'closed' => 'alert--info', default => 'alert--success' };
?>
<div class="module module-maintenance">
    <div class="alert <?= $stateClass ?> maintenance__state-banner" role="status">
        <?= $module->icon($job['state']['code'] === 'closed' ? 'check' : 'bell') ?>
        <div class="grow">
            <p class="alert__title"><?= $e($module->stateLabel($job['state'])) ?><?= $job['status'] === 'open' && $job['state']['code'] !== 'none' ? ' · ' . $e($module->stateDetail($job['state'], $unit)) : '' ?></p>
            <p class="mb-0">
                <?php if ($job['status'] === 'closed'): ?>
                    Tâche clôturée le <?= $e($datetime($job['closed_at'])) ?>.
                <?php elseif ($isCorrective): ?>
                    Panne signalée le <?= $e($datetime($job['created_at'])) ?><?= $job['next_due_at'] !== null ? ', à traiter avant le ' . $e($module->day($job['next_due_at'])) : '' ?>.
                <?php else: ?>
                    Échéance : <strong><?= $e($module->dueLabel($job['next_due_at'], $job['next_due_meter'], $unit)) ?></strong> · périodicité : <?= $e($module->intervalLabel($job['interval_days'], $job['interval_meter'], $unit)) ?> · rappel <?= (int) $job['lead_days'] ?> jour<?= (int) $job['lead_days'] > 1 ? 's' : '' ?> avant<?= $job['lead_meter'] !== null ? ' ou ' . $e($module->meter($job['lead_meter'], $unit)) . ' avant' : '' ?>.
                    <?php if ($job['last_done_at'] !== null): ?>Dernière réalisation : <?= $e($module->dueLabel($job['last_done_at'], $job['last_done_meter'], $unit)) ?>.<?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($rights['create'] && $job['status'] === 'open'): ?>
            <a class="btn btn--primary" href="#" data-route="log/new?job=<?= $id ?>"><?= $module->icon('check') ?> <?= $isCorrective ? 'Réparée' : 'Marquer comme fait' ?></a>
        <?php endif; ?>
    </div>

    <div class="maintenance__show-layout">
        <div class="maintenance__show-main">
            <section class="card maintenance__jobcard" aria-labelledby="mj-card-title">
                <div class="card__header">
                    <h2 class="card__title" id="mj-card-title"><?= $module->icon('book') ?> Fiche d’intervention</h2>
                    <span class="flex gap-1"><?= $module->kindBadge((string) $job['kind']) ?> <?= $module->priorityBadge((string) $job['priority']) ?></span>
                </div>
                <div class="card__body">
                    <dl class="dl maintenance__detail mb-3">
                        <dt>Équipement</dt><dd><a href="#" data-route="asset/<?= (int) $job['asset_id'] ?>"><?= $e($job['asset_name']) ?></a><?= $job['asset_meter_value'] !== null ? ' <span class="text-muted">· compteur ' . $e($module->meter($job['asset_meter_value'], $unit)) . '</span>' : '' ?></dd>
                        <dt>Durée estimée</dt><dd><?= $e($module->duration($job['estimated_minutes'])) ?></dd>
                        <dt>Coût estimé</dt><dd><?= $e($module->money($job['estimated_cost'])) ?></dd>
                    </dl>

                    <h3 class="maintenance__h3"><?= $module->icon('note', 'icon--sm') ?> Descriptif</h3>
                    <?php if ($job['description'] === null || trim((string) $job['description']) === ''): ?>
                        <p class="text-muted">Aucun descriptif.<?= $rights['update'] ? ' <a href="#" data-route="job/' . $id . '/edit">Compléter la fiche</a>.' : '' ?></p>
                    <?php else: ?>
                        <div class="prose bb maintenance__description"><?= $module->bbcode($job['description']) ?></div>
                    <?php endif; ?>

                    <div class="maintenance__lists">
                        <div>
                            <h3 class="maintenance__h3"><?= $module->icon('layers', 'icon--sm') ?> Pièces et consommables</h3>
                            <?php if ($parts === []): ?><p class="text-muted mb-0">—</p><?php else: ?>
                                <ul class="maintenance__checklist"><?php foreach ($parts as $line): ?><li><?= $e($line) ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 class="maintenance__h3"><?= $module->icon('users', 'icon--sm') ?> Contacts</h3>
                            <?php if ($contacts === []): ?><p class="text-muted mb-0">—</p><?php else: ?>
                                <ul class="maintenance__checklist"><?php foreach ($contacts as $line): ?><li><?= $e($line) ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 class="maintenance__h3"><?= $module->icon('tool', 'icon--sm') ?> Outillage</h3>
                            <?php if ($tools === []): ?><p class="text-muted mb-0">—</p><?php else: ?>
                                <ul class="maintenance__checklist"><?php foreach ($tools as $line): ?><li><?= $e($line) ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card__footer">
                    <div class="toolbar mb-0">
                        <a class="btn btn--sm" href="<?= $e($printUrl) ?>" target="_blank" rel="noopener"><?= $module->icon('print') ?> Imprimer la fiche</a>
                        <span class="toolbar__spacer"></span>
                        <span class="text-small text-muted">Créée le <?= $e($datetime($job['created_at'])) ?> · modifiée le <?= $e($datetime($job['updated_at'])) ?></span>
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="mj-history-title">
                <div class="card__header">
                    <h2 class="card__title" id="mj-history-title"><?= $module->icon('activity') ?> Réalisations</h2>
                    <span class="badge badge--muted"><?= count($logs) ?></span>
                </div>
                <div class="card__body<?= $logs === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($logs === []): ?>
                        <p class="text-muted mb-0">Cette tâche n’a encore jamais été réalisée.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table table--compact">
                                <thead><tr><th>Date</th><?php if ($unit !== null): ?><th class="col-num">Compteur</th><?php endif; ?><th>Notes</th><th class="col-num">Coût</th><th>Intervenant</th><th class="col-actions">Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="text-nowrap mono"><?= $e($module->day($log['done_at'])) ?></td>
                                        <?php if ($unit !== null): ?><td class="col-num text-nowrap"><?= $e($module->meter($log['meter_value'], $unit)) ?></td><?php endif; ?>
                                        <td><?= $log['notes'] !== null && $log['notes'] !== '' ? $e(\Atelier\Support\Str::truncate(\Atelier\View\BbCode::toText($log['notes']), 200)) : $dash ?><?= ($logAttachments[$log['id']] ?? 0) > 0 ? ' <span class="badge badge--muted" title="Pièces jointes">' . $module->icon('paperclip', 'icon--sm') . ' ' . (int) $logAttachments[$log['id']] . '</span>' : '' ?></td>
                                        <td class="col-num text-nowrap"><?= $e($module->money($log['cost'])) ?></td>
                                        <td><?= $log['performed_by'] !== null ? $e($log['performed_by']) : $dash ?></td>
                                        <td class="col-actions"><span class="table-actions">
                                            <?php if ($rights['update']): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="log/<?= (int) $log['id'] ?>/edit" title="Modifier, joindre une facture"><?= $module->icon('edit') ?></a><?php endif; ?>
                                            <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="log-delete" data-params='{"id":<?= (int) $log['id'] ?>}' data-confirm="Mettre cette intervention à la corbeille ?" data-danger title="Mettre à la corbeille"><?= $module->icon('trash') ?></button><?php endif; ?>
                                        </span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="maintenance__show-side">
            <section class="card" aria-labelledby="mj-tags-title">
                <div class="card__header"><h2 class="card__title" id="mj-tags-title"><?= $module->icon('tag') ?> Tags</h2></div>
                <div class="card__body">
                    <?php if ($tags === []): ?>
                        <p class="text-muted mb-0">Aucun tag.</p>
                    <?php else: ?>
                        <div class="chips"><?php foreach ($tags as $tag): ?><span class="chip"><?= $e($tag['name']) ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="mj-files-title">
                <div class="card__header">
                    <h2 class="card__title" id="mj-files-title"><?= $module->icon('paperclip') ?> Documents</h2>
                    <span class="badge badge--muted"><?= count($attachments) ?></span>
                </div>
                <div class="card__body">
                    <?= $module->partial('_attachments', ['target' => 'job', 'id' => $id, 'infoId' => $infoId, 'attachments' => $attachments, 'canUpdate' => $rights['update'], 'attachmentsModule' => $attachmentsModule]) ?>
                    <p class="text-small text-muted mt-2 mb-0">Procédure du constructeur, schéma, devis… Les factures se joignent à chaque réalisation.</p>
                </div>
            </section>
        </div>
    </div>
</div>
