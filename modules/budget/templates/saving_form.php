<?php
/**
 * Économie réalisée (registre manuel).
 * @var array<string, mixed> $saving
 * @var bool $isNew
 * @var array<string, string> $kinds
 * @var list<array<string, mixed>> $categories arbre (dépenses)
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="module module-budget">
    <div class="budget__form-layout">
        <form class="card budget__form" data-action="saving-save" data-track-dirty data-save-shortcut autocomplete="off" novalidate>
            <div class="card__header"><h2 class="card__title"><?= $module->icon('archive') ?> <?= $isNew ? 'Économie réalisée' : 'Économie « ' . $e($saving['label']) . ' »' ?></h2></div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $saving['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="field__label" for="bs-label">Libellé <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="bs-label" name="label" value="<?= $e($saving['label']) ?>" maxlength="200" required placeholder="Changement de fournisseur d’électricité, abonnement résilié, réparation faite soi-même…"<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bs-kind">Nature du gain <span class="required" aria-hidden="true">*</span></label>
                        <select class="select" id="bs-kind" name="kind"><?php foreach ($kinds as $code => $label): ?><option value="<?= $e($code) ?>"<?= $saving['kind'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                        <span class="field__help">Ponctuelle : gain unique à la date d’effet. Par mois ou par an : gain récurrent, cumulé mois après mois.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bs-amount">Gain (€) <span class="required" aria-hidden="true">*</span></label>
                        <input class="input mono" id="bs-amount" name="amount" inputmode="decimal" value="<?= $e(\Atelier\Modules\Budget\Money::input($saving['amount'])) ?>" required placeholder="18,00">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bs-from">Date d’effet <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="bs-from" name="effective_from" type="date" value="<?= $e($saving['effective_from']) ?>" required>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bs-to">Fin (facultatif)</label>
                        <input class="input" id="bs-to" name="effective_to" type="date" value="<?= $e($saving['effective_to'] ?? '') ?>">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bs-category">Catégorie concernée</label>
                        <select class="select" id="bs-category" name="category_id"><?= $module->categoryOptions($categories, $saving['category_id'], 'expense') ?></select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="bs-notes">Notes</label>
                        <textarea class="textarea" id="bs-notes" name="notes" rows="3" maxlength="20000"><?= $e($saving['notes'] ?? '') ?></textarea>
                        <span class="field__error"></span>
                    </div>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="savings">Annuler</a>
                    <button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button>
                </div>
            </div>
        </form>
    </div>
</div>
