<?php
/**
 * Création / modification d'une tâche d'entretien ou d'une panne, avec sa fiche d'intervention.
 * @var array<string, mixed> $job
 * @var bool $isNew
 * @var list<string> $tags
 * @var list<array<string, mixed>> $assets
 * @var array<string, string> $kinds
 * @var array<string, string> $priorities
 * @var array<string, string> $meterUnits
 * @var array<string, int> $defaultLeadMeter
 * @var string $today
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$isCorrective = $job['kind'] === 'corrective';
$assetUnits = [];
foreach ($assets as $a) {
    $assetUnits[(int) $a['id']] = (string) ($a['meter_unit'] ?? '');
}
$currentUnit = $assetUnits[(int) $job['asset_id']] ?? '';
$int = static fn (mixed $v): string => $v === null || $v === '' ? '' : (string) (int) $v;
?>
<div class="module module-maintenance">
    <div class="maintenance__form-layout">
        <form class="card maintenance__form" data-action="job-save" data-track-dirty data-save-shortcut autocomplete="off" novalidate data-maintenance-job-form data-asset-units='<?= $e(json_encode($assetUnits, JSON_UNESCAPED_UNICODE)) ?>' data-default-lead-meter='<?= $e(json_encode($defaultLeadMeter)) ?>'>
            <div class="card__header">
                <h2 class="card__title"><?= $module->icon($isCorrective ? 'warning' : 'clock') ?> <?= $isNew ? ($isCorrective ? 'Nouvelle panne' : 'Nouvelle tâche d’entretien') : 'Tâche « ' . $e($job['title']) . ' »' ?></h2>
            </div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $job['id'] ?>"><?php endif; ?>

                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="mj-asset">Équipement <span class="required" aria-hidden="true">*</span></label>
                        <select class="select" id="mj-asset" name="asset_id" required data-maintenance-job-asset>
                            <option value="">— Choisir —</option>
                            <?php foreach ($assets as $a): ?>
                                <option value="<?= (int) $a['id'] ?>"<?= (int) $job['asset_id'] === (int) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?><?= $a['meter_unit'] !== null ? ' (' . $e($module->meter($a['meter_value'], $a['meter_unit'], 'compteur ' . $a['meter_unit'])) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <span class="field__label">Nature <span class="required" aria-hidden="true">*</span></span>
                        <div class="flex flex--wrap gap-1">
                            <?php foreach ($kinds as $code => $label): ?>
                                <label class="radio"><input type="radio" name="kind" value="<?= $e($code) ?>"<?= $job['kind'] === $code ? ' checked' : '' ?> data-maintenance-job-kind> <?= $e($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="mj-title">Titre <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="mj-title" name="title" value="<?= $e($job['title']) ?>" required maxlength="150" placeholder="<?= $isCorrective ? 'Fuite au niveau du raccord, voyant moteur allumé…' : 'Vidange, contrôle technique, entretien annuel…' ?>"<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="mj-priority">Priorité</label>
                        <select class="select" id="mj-priority" name="priority">
                            <?php foreach ($priorities as $code => $label): ?>
                                <option value="<?= $e($code) ?>"<?= $job['priority'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field__error"></span>
                    </div>
                </div>

                <fieldset class="maintenance__fieldset" data-maintenance-schedule>
                    <legend><?= $module->icon('calendar', 'icon--sm') ?> <span data-maintenance-schedule-title><?= $isCorrective ? 'Délai de traitement' : 'Échéances et rappel' ?></span></legend>
                    <div class="form-grid">
                        <div class="field" data-maintenance-preventive-only<?= $isCorrective ? ' hidden' : '' ?>>
                            <label class="field__label" for="mj-interval-days">Périodicité (jours)</label>
                            <input class="input" id="mj-interval-days" name="interval_days" inputmode="numeric" value="<?= $e($int($job['interval_days'])) ?>" placeholder="365">
                            <span class="field__help">Exemples : 365 (chaque année), 730 (tous les 2 ans), 180 (tous les 6 mois).</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field" data-maintenance-preventive-only data-maintenance-meter-only<?= $isCorrective || $currentUnit === '' ? ' hidden' : '' ?>>
                            <label class="field__label" for="mj-interval-meter">Périodicité au compteur (<span data-maintenance-unit><?= $e($currentUnit) ?></span>)</label>
                            <input class="input" id="mj-interval-meter" name="interval_meter" inputmode="numeric" value="<?= $e($int($job['interval_meter'])) ?>" placeholder="15000">
                            <span class="field__help">Facultatif : la tâche est rappelée dès que l’un des deux seuils approche.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="mj-next-due"><span data-maintenance-due-label><?= $isCorrective ? 'À traiter avant le' : 'Prochaine échéance (date)' ?></span></label>
                            <input class="input" id="mj-next-due" name="next_due_at" type="date" value="<?= $e($job['next_due_at'] ?? '') ?>">
                            <span class="field__help" data-maintenance-preventive-only<?= $isCorrective ? ' hidden' : '' ?>>Laissez vide pour la déduire de la périodicité à partir d’aujourd’hui.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field" data-maintenance-preventive-only data-maintenance-meter-only<?= $isCorrective || $currentUnit === '' ? ' hidden' : '' ?>>
                            <label class="field__label" for="mj-next-meter">Prochaine échéance (compteur)</label>
                            <input class="input" id="mj-next-meter" name="next_due_meter" inputmode="numeric" value="<?= $e($int($job['next_due_meter'])) ?>" placeholder="76200">
                            <span class="field__help">Laissez vide pour la déduire du relevé actuel et de la périodicité.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="mj-lead-days">Rappel anticipé (jours avant la date)</label>
                            <input class="input" id="mj-lead-days" name="lead_days" inputmode="numeric" value="<?= $e($int($job['lead_days'])) ?>" placeholder="14">
                            <span class="field__help">La tâche passe « Bientôt » ce nombre de jours avant l’échéance (0 : pas d’anticipation).</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field" data-maintenance-preventive-only data-maintenance-meter-only<?= $isCorrective || $currentUnit === '' ? ' hidden' : '' ?>>
                            <label class="field__label" for="mj-lead-meter">Rappel anticipé (<span data-maintenance-unit><?= $e($currentUnit) ?></span> avant le compteur)</label>
                            <input class="input" id="mj-lead-meter" name="lead_meter" inputmode="numeric" value="<?= $e($int($job['lead_meter'])) ?>" placeholder="500">
                            <span class="field__error"></span>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="maintenance__fieldset">
                    <legend><?= $module->icon('book', 'icon--sm') ?> Fiche d’intervention</legend>
                    <div class="form-grid">
                        <div class="field field--full">
                            <label class="field__label" for="mj-description">Descriptif</label>
                            <textarea class="textarea" id="mj-description" name="description" rows="8" maxlength="20000" data-editor="bbcode" placeholder="<?= $isCorrective ? 'Symptômes, circonstances, diagnostic…' : 'Étapes de l’intervention, points de contrôle, couples de serrage, références…' ?>"><?= $e($job['description'] ?? '') ?></textarea>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="mj-parts">Pièces et consommables</label>
                            <textarea class="textarea" id="mj-parts" name="parts" rows="5" maxlength="20000" placeholder="Une ligne par pièce&#10;Huile 5W30 (4,3 l)&#10;Filtre à huile réf. …"><?= $e($job['parts'] ?? '') ?></textarea>
                            <span class="field__help">Une pièce par ligne.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="mj-contacts">Contacts</label>
                            <textarea class="textarea" id="mj-contacts" name="contacts" rows="5" maxlength="20000" placeholder="Un contact par ligne&#10;Garage Martin — 04 00 00 00 00&#10;Fournisseur de pièces — site web"><?= $e($job['contacts'] ?? '') ?></textarea>
                            <span class="field__help">Garagiste, chauffagiste, fournisseur, assistance… un contact par ligne.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="mj-tools">Outillage</label>
                            <textarea class="textarea" id="mj-tools" name="tools" rows="3" maxlength="20000" placeholder="Un outil par ligne"><?= $e($job['tools'] ?? '') ?></textarea>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <div class="form-grid">
                                <div class="field">
                                    <label class="field__label" for="mj-minutes">Durée estimée (min)</label>
                                    <input class="input" id="mj-minutes" name="estimated_minutes" inputmode="numeric" value="<?= $e($int($job['estimated_minutes'])) ?>" placeholder="60">
                                    <span class="field__error"></span>
                                </div>
                                <div class="field">
                                    <label class="field__label" for="mj-cost">Coût estimé (€)</label>
                                    <input class="input" id="mj-cost" name="estimated_cost" inputmode="decimal" value="<?= $e($job['estimated_cost'] === null ? '' : number_format($job['estimated_cost'] / 100, 2, ',', '')) ?>" placeholder="120,00">
                                    <span class="field__error"></span>
                                </div>
                            </div>
                        </div>
                        <div class="field field--full">
                            <label class="field__label" for="mj-tags"><?= $module->icon('tag', 'icon--sm') ?> Tags partagés</label>
                            <input class="input" type="text" id="mj-tags" name="tags" value="<?= $e(implode(', ', $tags)) ?>" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20">
                            <span class="field__error"></span>
                        </div>
                    </div>
                </fieldset>

                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="<?= $isNew ? ((int) $job['asset_id'] > 0 ? 'asset/' . (int) $job['asset_id'] : ($isCorrective ? 'defects' : 'jobs')) : 'job/' . (int) $job['id'] ?>">Annuler</a>
                    <button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button>
                </div>
            </div>
        </form>

        <aside class="card">
            <div class="card__header"><h2 class="card__title"><?= $module->icon('info') ?> Comment ça marche</h2></div>
            <div class="card__body">
                <ul class="mb-3">
                    <li><strong>Entretien planifié</strong> : périodicité en jours et/ou au compteur. Quand vous enregistrez l’intervention, la prochaine échéance est recalculée automatiquement.</li>
                    <li><strong>Panne ou défaut</strong> : intervention ponctuelle avec une date cible facultative ; la tâche est clôturée quand la réparation est enregistrée.</li>
                    <li><strong>Rappel anticipé</strong> : nombre de jours (ou d’unités de compteur) avant l’échéance à partir duquel la tâche apparaît dans les rappels et le badge du module.</li>
                </ul>
                <p class="text-small text-muted mb-0">La fiche d’intervention (descriptif, pièces, contacts, outillage) est imprimable depuis la tâche ; les notices et factures se joignent à l’équipement, à la tâche ou à l’intervention.</p>
            </div>
        </aside>
    </div>
</div>
