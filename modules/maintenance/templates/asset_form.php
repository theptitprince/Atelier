<?php
/**
 * Création / modification d'un équipement.
 * @var array<string, mixed> $asset
 * @var bool $isNew
 * @var list<string> $tags
 * @var array<string, string> $categories
 * @var array<string, string> $meterUnits
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
?>
<div class="module module-maintenance">
    <div class="maintenance__form-layout">
        <form class="card maintenance__form" data-action="asset-save" data-track-dirty data-save-shortcut autocomplete="off" novalidate data-maintenance-asset-form>
            <div class="card__header">
                <h2 class="card__title"><?= $module->icon('layers') ?> <?= $isNew ? 'Nouvel équipement' : 'Équipement « ' . $e($asset['name']) . ' »' ?></h2>
            </div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $asset['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="field__label" for="ma-name">Nom <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="ma-name" name="name" value="<?= $e($asset['name']) ?>" required maxlength="150" placeholder="Voiture, chaudière, lave-linge…"<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ma-category">Catégorie <span class="required" aria-hidden="true">*</span></label>
                        <select class="select" id="ma-category" name="category" required data-maintenance-category>
                            <?php foreach ($categories as $code => $label): ?>
                                <option value="<?= $e($code) ?>"<?= $asset['category'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ma-location">Emplacement</label>
                        <input class="input" id="ma-location" name="location" value="<?= $e($asset['location'] ?? '') ?>" maxlength="150" placeholder="Garage, buanderie, cuisine…">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ma-brand">Marque</label>
                        <input class="input" id="ma-brand" name="brand" value="<?= $e($asset['brand'] ?? '') ?>" maxlength="100">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ma-model">Modèle</label>
                        <input class="input" id="ma-model" name="model" value="<?= $e($asset['model'] ?? '') ?>" maxlength="100">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ma-identifier">Immatriculation ou n° de série</label>
                        <input class="input mono" id="ma-identifier" name="identifier" value="<?= $e($asset['identifier'] ?? '') ?>" maxlength="100" spellcheck="false">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ma-acquired">Date d’acquisition</label>
                        <input class="input" id="ma-acquired" name="acquired_at" type="date" value="<?= $e($asset['acquired_at'] ?? '') ?>">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ma-meter-unit">Compteur</label>
                        <select class="select" id="ma-meter-unit" name="meter_unit" data-maintenance-meter-unit>
                            <option value=""<?= ($asset['meter_unit'] ?? '') === '' ? ' selected' : '' ?>>Aucun compteur</option>
                            <?php foreach ($meterUnits as $code => $label): ?>
                                <option value="<?= $e($code) ?>"<?= ($asset['meter_unit'] ?? '') === $code ? ' selected' : '' ?>><?= $e($label) ?> (<?= $e($code) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field__help">Kilométrage d’un véhicule, heures de fonctionnement d’une chaudière ou d’une tondeuse : les échéances peuvent alors se définir au compteur.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field" data-maintenance-meter-field<?= ($asset['meter_unit'] ?? '') === '' ? ' hidden' : '' ?>>
                        <label class="field__label" for="ma-meter-value">Relevé actuel</label>
                        <input class="input" id="ma-meter-value" name="meter_value" inputmode="numeric" value="<?= $e($asset['meter_value'] === null ? '' : (string) $asset['meter_value']) ?>" placeholder="61200">
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="ma-notes">Notes</label>
                        <textarea class="textarea" id="ma-notes" name="notes" rows="6" maxlength="20000" data-editor="bbcode" placeholder="Références utiles : pneus, huile, filtres, contrat d’entretien, garantie…"><?= $e($asset['notes'] ?? '') ?></textarea>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="ma-tags"><?= $module->icon('tag', 'icon--sm') ?> Tags partagés</label>
                        <input class="input" type="text" id="ma-tags" name="tags" value="<?= $e(implode(', ', $tags)) ?>" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20">
                        <span class="field__error"></span>
                    </div>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="<?= $isNew ? 'assets' : 'asset/' . (int) $asset['id'] ?>">Annuler</a>
                    <button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button>
                </div>
            </div>
        </form>

        <aside class="card">
            <div class="card__header"><h2 class="card__title"><?= $module->icon('info') ?> Conseils</h2></div>
            <div class="card__body">
                <ul class="mb-0">
                    <li>Un équipement regroupe ses tâches d’entretien, ses pannes, son historique et ses documents (notices, factures, photos).</li>
                    <li>Renseignez le compteur pour les véhicules et machines : une vidange « tous les 15 000 km ou 12 mois » sera rappelée dès que l’un des deux seuils approche.</li>
                    <li>Les notes acceptent la mise en forme BBCode ; elles sont affichées sur la fiche.</li>
                </ul>
            </div>
        </aside>
    </div>
</div>
