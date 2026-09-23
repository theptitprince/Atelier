<?php
/**
 * Enregistrement / modification d'une intervention réalisée. À la création depuis une tâche, la tâche
 * est replanifiée (ou clôturée) et le compteur de l'équipement mis à jour. Les documents (facture, photos)
 * se déposent dès la création (champ files[] du formulaire, envoi multipart) puis depuis le panneau latéral.
 * @var array<string, mixed> $log
 * @var bool $isNew
 * @var array<string, mixed>|null $job tâche d'origine
 * @var list<array<string, mixed>> $assets
 * @var array<int, array<string, mixed>> $assetsById
 * @var array<int, list<array{id: int, title: string, kind: string}>> $jobsByAsset
 * @var string $today
 * @var string|null $infoId (modification)
 * @var list<array<string, mixed>> $attachments (modification)
 * @var bool $attachmentsModule (modification)
 * @var array<string, bool> $rights (modification)
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$infoId ??= null;
$attachments ??= [];
$attachmentsModule ??= false;
$rights ??= ['update' => true, 'delete' => false];
$assetId = (int) $log['asset_id'];
$asset = $assetsById[$assetId] ?? null;
$unit = $asset['meter_unit'] ?? null;
$assetUnits = [];
foreach ($assets as $a) {
    $assetUnits[(int) $a['id']] = (string) ($a['meter_unit'] ?? '');
}
$lockedJob = $job !== null && $isNew;
?>
<div class="module module-maintenance">
    <div class="maintenance__form-layout">
        <form class="card maintenance__form" data-action="log-save" data-track-dirty data-save-shortcut autocomplete="off" novalidate data-maintenance-log-form data-asset-units='<?= $e(json_encode($assetUnits, JSON_UNESCAPED_UNICODE)) ?>' data-jobs-by-asset='<?= $e(json_encode($jobsByAsset, JSON_UNESCAPED_UNICODE)) ?>'>
            <div class="card__header">
                <h2 class="card__title"><?= $module->icon('check') ?> <?= $isNew ? 'Intervention réalisée' : 'Intervention du ' . $e($module->day($log['done_at'])) ?></h2>
                <?php if ($job !== null): ?><span class="badge badge--info"><?= $module->icon('clock', 'icon--sm') ?> <?= $e($job['title']) ?></span><?php endif; ?>
            </div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $log['id'] ?>"><?php endif; ?>
                <?php if ($lockedJob): ?>
                    <div class="alert alert--info" role="status">
                        <?= $module->icon('info') ?>
                        <div>
                            <p class="alert__title">Réalisation de « <?= $e($job['title']) ?> »</p>
                            <p class="mb-0">
                                <?php if ($job['kind'] === 'corrective' || ($job['interval_days'] === null && $job['interval_meter'] === null)): ?>
                                    La tâche sera clôturée à l’enregistrement.
                                <?php else: ?>
                                    La prochaine échéance sera recalculée à partir de la date et du compteur saisis (<?= $e($module->intervalLabel($job['interval_days'], $job['interval_meter'], $unit)) ?>).
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="ml-asset">Équipement <span class="required" aria-hidden="true">*</span></label>
                        <?php if ($lockedJob || !$isNew): ?>
                            <input type="hidden" name="asset_id" value="<?= $assetId ?>">
                            <input class="input" id="ml-asset" value="<?= $e($asset['name'] ?? '') ?>" readonly>
                        <?php else: ?>
                            <select class="select" id="ml-asset" name="asset_id" required data-maintenance-log-asset>
                                <option value="">— Choisir —</option>
                                <?php foreach ($assets as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>"<?= $assetId === (int) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ml-job">Tâche d’origine</label>
                        <?php if ($lockedJob): ?>
                            <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                            <input class="input" id="ml-job" value="<?= $e($job['title']) ?>" readonly>
                        <?php else: ?>
                            <select class="select" id="ml-job" name="job_id" data-maintenance-log-job>
                                <option value="">Aucune (intervention libre)</option>
                                <?php foreach ($jobsByAsset[$assetId] ?? [] as $j): ?>
                                    <option value="<?= (int) $j['id'] ?>"<?= (int) ($log['job_id'] ?? 0) === (int) $j['id'] ? ' selected' : '' ?>><?= $e($j['title']) ?><?= $j['kind'] === 'corrective' ? ' (panne)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field__help"><?= $isNew ? 'Si une tâche est choisie, elle est replanifiée ou clôturée.' : 'La modification d’une intervention ne replanifie pas la tâche.' ?></span>
                        <?php endif; ?>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="ml-title">Titre <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="ml-title" name="title" value="<?= $e($log['title']) ?>" maxlength="150" placeholder="<?= $job !== null ? $e($job['title']) : 'Vidange, remplacement…' ?>" data-maintenance-log-title>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ml-date">Date de réalisation <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="ml-date" name="done_at" type="date" value="<?= $e($log['done_at']) ?>" max="<?= $e($today) ?>" required>
                        <span class="field__error"></span>
                    </div>
                    <div class="field" data-maintenance-meter-field<?= $unit === null ? ' hidden' : '' ?>>
                        <label class="field__label" for="ml-meter">Compteur (<span data-maintenance-unit><?= $e($unit ?? '') ?></span>)</label>
                        <input class="input" id="ml-meter" name="meter_value" inputmode="numeric" value="<?= $e($log['meter_value'] === null ? '' : (string) $log['meter_value']) ?>" placeholder="relevé au moment de l’intervention">
                        <span class="field__help">Met à jour le compteur de l’équipement s’il est plus récent.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ml-cost">Coût (€)</label>
                        <input class="input" id="ml-cost" name="cost" inputmode="decimal" value="<?= $e($log['cost'] === null ? '' : number_format($log['cost'] / 100, 2, ',', '')) ?>" placeholder="0,00">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ml-by">Intervenant</label>
                        <input class="input" id="ml-by" name="performed_by" value="<?= $e($log['performed_by'] ?? '') ?>" maxlength="150" placeholder="Moi-même, Garage Martin…">
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="ml-notes">Notes</label>
                        <textarea class="textarea" id="ml-notes" name="notes" rows="6" maxlength="20000" data-editor="bbcode" placeholder="Ce qui a été fait, pièces remplacées, observations, prochaine fois…"><?= $e($log['notes'] ?? '') ?></textarea>
                        <span class="field__error"></span>
                    </div>
                    <?php if ($isNew): ?>
                        <div class="field field--full">
                            <label class="field__label" for="ml-files"><?= $module->icon('paperclip', 'icon--sm') ?> Documents à joindre</label>
                            <input class="input" type="file" id="ml-files" name="files[]" multiple>
                            <span class="field__help">Facture, ticket, photos… Plusieurs fichiers possibles ; ils seront rattachés à l’intervention dès son enregistrement.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field field--full">
                            <label class="field__label" for="ml-files-desc">Description des documents</label>
                            <input class="input" type="text" id="ml-files-desc" name="files_description" maxlength="500" placeholder="Facture garage Martin, photo avant/après… — facultatif">
                            <span class="field__error"></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="<?= $job !== null ? 'job/' . (int) $job['id'] : ($assetId > 0 ? 'asset/' . $assetId : 'history') ?>">Annuler</a>
                    <button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button>
                </div>
            </div>
        </form>

        <aside class="maintenance__form-side">
            <?php if (!$isNew): ?>
                <section class="card">
                    <div class="card__header">
                        <h2 class="card__title"><?= $module->icon('paperclip') ?> Factures et documents</h2>
                        <span class="badge badge--muted"><?= count($attachments) ?></span>
                    </div>
                    <div class="card__body">
                        <?= $module->partial('_attachments', ['target' => 'log', 'id' => (int) $log['id'], 'infoId' => $infoId, 'attachments' => $attachments, 'canUpdate' => $rights['update'], 'attachmentsModule' => $attachmentsModule]) ?>
                    </div>
                </section>
            <?php else: ?>
                <section class="card">
                    <div class="card__header"><h2 class="card__title"><?= $module->icon('info') ?> Après l’enregistrement</h2></div>
                    <div class="card__body">
                        <ul class="mb-0">
                            <li>L’intervention apparaît dans l’historique de l’équipement et dans l’historique global.</li>
                            <li>Les documents choisis ci-contre sont joints immédiatement ; vous pourrez en ajouter à tout moment depuis l’historique (dépliant « documents ») ou la fiche de l’intervention (icône <?= $module->icon('edit', 'icon--sm') ?>).</li>
                            <li>Le coût alimente le total des 12 derniers mois du tableau de bord.</li>
                        </ul>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>
