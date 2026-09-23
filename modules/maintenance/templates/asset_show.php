<?php
/**
 * Fiche d'un équipement : caractéristiques, compteur, tâches (avec état d'échéance), historique,
 * notes, tags, pièces jointes.
 * @var array<string, mixed> $asset
 * @var string|null $infoId
 * @var list<array<string, mixed>> $jobs (avec state)
 * @var list<array<string, mixed>> $logs
 * @var array<int, int> $logAttachments
 * @var list<array<string, mixed>> $tags
 * @var list<array<string, mixed>> $attachments
 * @var array<string, bool> $rights
 * @var array<string, mixed>|null $author
 * @var bool $attachmentsModule
 * @var array<string, string> $categories
 * @var array<string, string> $meterUnits
 * @var string $baseUrl
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$id = (int) $asset['id'];
$dash = '<span class="text-muted">—</span>';
$unit = $asset['meter_unit'];
$openJobs = array_values(array_filter($jobs, static fn (array $j): bool => $j['status'] === 'open'));
$closedJobs = array_values(array_filter($jobs, static fn (array $j): bool => $j['status'] !== 'open'));
?>
<div class="module module-maintenance">
    <div class="maintenance__show-layout">
        <div class="maintenance__show-main">
            <section class="card" aria-labelledby="ma-jobs-title">
                <div class="card__header">
                    <h2 class="card__title" id="ma-jobs-title"><?= $module->icon('clock') ?> Tâches et pannes</h2>
                    <span class="badge badge--muted"><?= count($openJobs) ?> ouverte<?= count($openJobs) > 1 ? 's' : '' ?></span>
                </div>
                <div class="card__body<?= $openJobs === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($openJobs === []): ?>
                        <p class="text-muted mb-0">Aucune tâche ouverte.<?= $rights['create'] ? ' <a href="#" data-route="job/new?asset=' . $id . '">Planifier un entretien</a> ou <a href="#" data-route="job/new?asset=' . $id . '&kind=corrective">signaler une panne</a>.' : '' ?></p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table table--compact">
                                <thead><tr><th>État</th><th>Tâche</th><th>Périodicité</th><th>Échéance</th><th>Dernière fois</th><th class="col-actions">Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($openJobs as $job): ?>
                                    <tr>
                                        <td><?= $module->stateBadge($job['state'], $unit) ?></td>
                                        <td><a href="#" data-route="job/<?= (int) $job['id'] ?>"><strong><?= $e($job['title']) ?></strong></a> <?= $module->kindBadge((string) $job['kind']) ?> <?= $job['priority'] !== 'normal' ? $module->priorityBadge((string) $job['priority']) : '' ?></td>
                                        <td class="text-small"><?= $e($module->intervalLabel($job['interval_days'], $job['interval_meter'], $unit)) ?></td>
                                        <td class="text-nowrap"><?= $e($module->dueLabel($job['next_due_at'], $job['next_due_meter'], $unit)) ?><span class="text-muted text-small"> · <?= $e($module->stateDetail($job['state'], $unit)) ?></span></td>
                                        <td class="text-nowrap"><?= $e($module->dueLabel($job['last_done_at'], $job['last_done_meter'], $unit)) ?></td>
                                        <td class="col-actions">
                                            <span class="table-actions">
                                                <?php if ($rights['create']): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="log/new?job=<?= (int) $job['id'] ?>" title="Marquer comme fait"><?= $module->icon('check') ?></a><?php endif; ?>
                                                <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="job/<?= (int) $job['id'] ?>" title="Fiche de la tâche"><?= $module->icon('eye') ?></a>
                                                <?php if ($rights['update']): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="job/<?= (int) $job['id'] ?>/edit" title="Modifier"><?= $module->icon('edit') ?></a><?php endif; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($closedJobs !== []): ?>
                    <div class="card__footer">
                        <details>
                            <summary class="text-small text-muted"><?= count($closedJobs) ?> tâche<?= count($closedJobs) > 1 ? 's' : '' ?> clôturée<?= count($closedJobs) > 1 ? 's' : '' ?></summary>
                            <ul class="list mt-2">
                                <?php foreach ($closedJobs as $job): ?>
                                    <li class="list__item"><?= $module->kindBadge((string) $job['kind']) ?><a class="grow" href="#" data-route="job/<?= (int) $job['id'] ?>"><?= $e($job['title']) ?></a><span class="text-muted text-small">clôturée le <?= $e($date($job['closed_at'])) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card" aria-labelledby="ma-history-title">
                <div class="card__header">
                    <h2 class="card__title" id="ma-history-title"><?= $module->icon('activity') ?> Historique des interventions</h2>
                    <span class="badge badge--muted"><?= count($logs) ?></span>
                </div>
                <div class="card__body<?= $logs === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($logs === []): ?>
                        <p class="text-muted mb-0">Aucune intervention enregistrée pour cet équipement.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table table--compact">
                                <thead><tr><th>Date</th><th>Intervention</th><?php if ($unit !== null): ?><th class="col-num">Compteur</th><?php endif; ?><th class="col-num">Coût</th><th>Intervenant</th><th class="col-actions">Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="text-nowrap mono"><?= $e($module->day($log['done_at'])) ?></td>
                                        <td>
                                            <strong><?= $e($log['title']) ?></strong>
                                            <?php if ($log['job_id'] !== null && $log['job_deleted_at'] === null): ?><a class="text-small text-muted" href="#" data-route="job/<?= (int) $log['job_id'] ?>" title="Tâche d’origine"><?= $module->icon('clock', 'icon--sm') ?></a><?php endif; ?>
                                            <?php if (($logAttachments[$log['id']] ?? 0) > 0): ?><span class="badge badge--muted" title="Pièces jointes"><?= $module->icon('paperclip', 'icon--sm') ?> <?= (int) $logAttachments[$log['id']] ?></span><?php endif; ?>
                                            <?php if ($log['notes'] !== null && $log['notes'] !== ''): ?><div class="text-small text-muted maintenance__excerpt"><?= $e(\Atelier\Support\Str::truncate(\Atelier\View\BbCode::toText($log['notes']), 160)) ?></div><?php endif; ?>
                                        </td>
                                        <?php if ($unit !== null): ?><td class="col-num text-nowrap"><?= $e($module->meter($log['meter_value'], $unit)) ?></td><?php endif; ?>
                                        <td class="col-num text-nowrap"><?= $e($module->money($log['cost'])) ?></td>
                                        <td><?= $log['performed_by'] !== null ? $e($log['performed_by']) : $dash ?></td>
                                        <td class="col-actions">
                                            <span class="table-actions">
                                                <?php if ($rights['update']): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="log/<?= (int) $log['id'] ?>/edit" title="Modifier, joindre une facture"><?= $module->icon('edit') ?></a><?php endif; ?>
                                                <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="log-delete" data-params='{"id":<?= (int) $log['id'] ?>}' data-confirm="Mettre cette intervention à la corbeille ?" data-danger title="Mettre à la corbeille"><?= $module->icon('trash') ?></button><?php endif; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($rights['create']): ?>
                    <div class="card__footer"><a class="btn btn--sm" href="#" data-route="log/new?asset=<?= $id ?>"><?= $module->icon('plus') ?> Enregistrer une intervention</a></div>
                <?php endif; ?>
            </section>

            <?php if ($asset['notes'] !== null && $asset['notes'] !== ''): ?>
                <section class="card" aria-labelledby="ma-notes-title">
                    <div class="card__header"><h2 class="card__title" id="ma-notes-title"><?= $module->icon('note') ?> Notes</h2></div>
                    <div class="card__body prose bb"><?= $module->bbcode($asset['notes']) ?></div>
                </section>
            <?php endif; ?>
        </div>

        <div class="maintenance__show-side">
            <section class="card" aria-labelledby="ma-info-title">
                <div class="card__header"><h2 class="card__title" id="ma-info-title"><?= $module->icon($module->categoryIcon((string) $asset['category'])) ?> Caractéristiques</h2></div>
                <div class="card__body">
                    <dl class="dl maintenance__detail">
                        <dt>Catégorie</dt><dd><?= $e($categories[$asset['category']] ?? $asset['category']) ?></dd>
                        <dt>Marque / modèle</dt><dd><?= trim(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? '')) !== '' ? $e(trim(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? ''))) : $dash ?></dd>
                        <dt>Identifiant</dt><dd><?= $asset['identifier'] !== null ? '<code>' . $e($asset['identifier']) . '</code>' : $dash ?></dd>
                        <dt>Emplacement</dt><dd><?= $asset['location'] !== null ? $e($asset['location']) : $dash ?></dd>
                        <dt>Acquis le</dt><dd><?= $e($module->day($asset['acquired_at'])) ?></dd>
                        <dt>Compteur</dt>
                        <dd>
                            <?php if ($unit === null): ?>
                                <?= $dash ?>
                            <?php else: ?>
                                <strong class="mono"><?= $e($module->meter($asset['meter_value'], $unit, 'non relevé')) ?></strong>
                                <?php if ($asset['meter_updated_at'] !== null): ?><span class="text-muted text-small">relevé le <?= $e($date($asset['meter_updated_at'])) ?></span><?php endif; ?>
                                <?php if ($rights['update']): ?>
                                    <button type="button" class="btn btn--sm mt-1" data-action="asset-meter" data-params='{"id":<?= $id ?>}' data-prompt="Nouveau relevé du compteur (<?= $e($meterUnits[$unit] ?? $unit) ?>)" data-prompt-field="meter_value"><?= $module->icon('hash') ?> Relever</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </dd>
                        <dt>Créé</dt><dd><?= $e($datetime($asset['created_at'])) ?><?= $author !== null ? ' par ' . $e($author['display_name']) : '' ?></dd>
                        <dt>Modifié</dt><dd><?= $e($datetime($asset['updated_at'])) ?></dd>
                    </dl>
                </div>
            </section>

            <section class="card" aria-labelledby="ma-tags-title">
                <div class="card__header"><h2 class="card__title" id="ma-tags-title"><?= $module->icon('tag') ?> Tags</h2></div>
                <div class="card__body">
                    <?php if ($tags === []): ?>
                        <p class="text-muted mb-0">Aucun tag.<?= $rights['update'] ? ' Ajoutez-en depuis <a href="#" data-route="asset/' . $id . '/edit">la modification</a>.' : '' ?></p>
                    <?php else: ?>
                        <div class="chips"><?php foreach ($tags as $tag): ?><span class="chip"><?= $e($tag['name']) ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="ma-files-title">
                <div class="card__header">
                    <h2 class="card__title" id="ma-files-title"><?= $module->icon('paperclip') ?> Documents</h2>
                    <span class="badge badge--muted"><?= count($attachments) ?></span>
                </div>
                <div class="card__body">
                    <?= $module->partial('_attachments', ['target' => 'asset', 'id' => $id, 'infoId' => $infoId, 'attachments' => $attachments, 'canUpdate' => $rights['update'], 'attachmentsModule' => $attachmentsModule]) ?>
                    <p class="text-small text-muted mt-2 mb-0">Notices, carte grise, contrat d’entretien, photos… Les factures se joignent à chaque intervention : dépliant « documents » de l’historique ou icône <?= $module->icon('edit', 'icon--sm') ?> de la ligne.</p>
                </div>
            </section>
        </div>
    </div>
</div>
