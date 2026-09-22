<?php
/**
 * Préférences d'interface de l'utilisateur.
 * @var array<string, mixed> $values pageSize, sidebarCollapsed, confirmClose
 * @var list<int> $pageSizes
 */
$pageSize = (int) $values['pageSize'];
?>
<div class="module module-profile">
    <form class="card profile__preferences" data-action="save-preferences" data-track-dirty data-save-shortcut novalidate>
        <div class="card__header">
            <h2 class="card__title">
                <svg class="icon" aria-hidden="true"><use href="#i-sliders"></use></svg> Préférences d’interface
            </h2>
        </div>
        <div class="card__body">
            <fieldset>
                <legend>Tableaux</legend>
                <div class="field">
                    <label class="field__label" for="pref-page-size">Nombre de lignes par page</label>
                    <select class="select profile__select" id="pref-page-size" name="pageSize">
                        <?php foreach ($pageSizes as $size): ?>
                            <option value="<?= (int) $size ?>"<?= $pageSize === (int) $size ? ' selected' : '' ?>><?= (int) $size ?> lignes</option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__help">Valeur par défaut des tableaux paginés ; chaque module peut la restreindre à ses propres options.</span>
                    <span class="field__error"></span>
                </div>
            </fieldset>

            <fieldset>
                <legend>Navigation</legend>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="sidebarCollapsed" value="1"<?= !empty($values['sidebarCollapsed']) ? ' checked' : '' ?>>
                        <span>Colonne des modules repliée par défaut</span>
                    </label>
                    <span class="field__help">Appliqué immédiatement à l’enregistrement, puis à chaque ouverture de l’application.</span>
                </div>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="confirmClose" value="1"<?= !empty($values['confirmClose']) ? ' checked' : '' ?>>
                        <span>Demander confirmation avant de fermer un onglet, même sans modification</span>
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend>Affichage et notifications</legend>
                <div class="field">
                    <label class="field__label" for="pref-time-format">Format d’heure</label>
                    <select class="select profile__select" id="pref-time-format" disabled aria-describedby="pref-time-format-help">
                        <option selected>24 h (ex. 16:05)</option>
                    </select>
                    <span class="field__help" id="pref-time-format-help">Seul le format 24 h est disponible dans cette version.</span>
                </div>
                <div class="field">
                    <label class="checkbox profile__disabled">
                        <input type="checkbox" disabled aria-describedby="pref-sound-help">
                        <span>Notifications sonores</span>
                        <span class="badge badge--muted">À venir</span>
                    </label>
                    <span class="field__help" id="pref-sound-help">Cette option sera proposée dans une prochaine version.</span>
                </div>
            </fieldset>
        </div>
        <div class="card__footer">
            <div class="form-actions form-actions--end mt-0 profile__footer-actions">
                <span class="text-muted text-small grow">Les préférences sont propres à votre compte et s’appliquent sur tous vos postes.</span>
                <button type="submit" class="btn btn--primary">
                    <svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer
                </button>
            </div>
        </div>
    </form>
</div>
