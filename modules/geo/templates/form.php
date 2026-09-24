<?php
/**
 * Création / modification d'un point GPS.
 * @var array<string, mixed> $point (id, code, name, coordinates, altitude, address, description, tags)
 * @var bool $isNew
 * @var array<string, bool> $rights
 * @var \Atelier\Modules\Geo\GeoModule $module
 */
?>
<div class="module module-geo">
    <div class="geo__form-layout">
        <form class="card geo__form" data-action="save" data-track-dirty data-save-shortcut autocomplete="off" novalidate>
            <div class="card__header">
                <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-map-pin"></use></svg> <?= $isNew ? 'Nouveau point' : 'Point « ' . $e($point['name']) . ' »' ?></h2>
            </div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $point['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="field__label" for="geo-name">Nom <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="geo-name" name="name" value="<?= $e($point['name']) ?>" required maxlength="200"<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="geo-coordinates">Coordonnées <span class="required" aria-hidden="true">*</span></label>
                        <div class="input-group">
                            <input class="input mono" id="geo-coordinates" name="coordinates" value="<?= $e($point['coordinates']) ?>" required placeholder="48.8566, 2.3522  ou  48°51'24&quot;N 2°21'03&quot;E" data-geo-coordinates>
                            <button type="button" class="btn" data-geo-check title="Interpréter les coordonnées saisies"><svg class="icon" aria-hidden="true"><use href="#i-check"></use></svg> Vérifier</button>
                        </div>
                        <span class="field__help">Latitude puis longitude (WGS 84) : degrés décimaux, degrés-minutes-secondes ou degrés-minutes décimales, avec ou sans lettres N/S/E/W.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="geo-code">Code</label>
                        <input class="input mono" id="geo-code" name="code" value="<?= $e($point['code'] ?? '') ?>" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{0,31}" placeholder="facultatif, unique" spellcheck="false">
                        <span class="field__help">Identifiant court et stable pour les imports et les autres modules.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="geo-altitude">Altitude (m)</label>
                        <input class="input" id="geo-altitude" name="altitude" value="<?= $e($point['altitude'] === null ? '' : (string) $point['altitude']) ?>" inputmode="decimal" placeholder="facultatif">
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="geo-address">Adresse ou lieu-dit</label>
                        <input class="input" id="geo-address" name="address" value="<?= $e($point['address'] ?? '') ?>" maxlength="300" placeholder="facultatif">
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="geo-description">Description</label>
                        <textarea class="textarea" id="geo-description" name="description" rows="4" maxlength="5000"><?= $e($point['description'] ?? '') ?></textarea>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="geo-tags"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg> Tags partagés</label>
                        <input class="input" type="text" id="geo-tags" name="tags" value="<?= $e(implode(', ', $point['tags'] ?? [])) ?>" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20">
                        <span class="field__help">Les tags existants sont proposés pendant la saisie (Entrée ou virgule pour ajouter). Ils restent modifiables depuis la fiche du point.</span>
                        <span class="field__error"></span>
                    </div>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="<?= $isNew ? 'list' : 'show/' . (int) $point['id'] ?>">Annuler</a>
                    <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer</button>
                </div>
            </div>
        </form>

        <aside class="card geo__preview" data-geo-preview hidden aria-live="polite">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-navigation"></use></svg> Interprétation</h2></div>
            <div class="card__body">
                <dl class="dl">
                    <dt>Décimal</dt><dd class="mono" data-geo-preview-decimal></dd>
                    <dt>DMS</dt><dd class="mono" data-geo-preview-dms></dd>
                    <dt>Carte</dt><dd><a href="#" target="_blank" rel="noopener noreferrer" data-geo-preview-osm>Ouvrir dans OpenStreetMap <svg class="icon icon--sm" aria-hidden="true"><use href="#i-external"></use></svg></a></dd>
                </dl>
                <div data-geo-preview-nearby hidden>
                    <h4 class="mt-3">Points déjà référencés à moins de 5 km</h4>
                    <ul class="list" data-geo-preview-nearby-list></ul>
                </div>
            </div>
        </aside>
    </div>
</div>
