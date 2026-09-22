<?php
/**
 * Création / modification d'une opération récurrente.
 * @var array<string, mixed> $recurring
 * @var bool $isNew
 * @var string $kind expense|income
 * @var list<array<string, mixed>> $accounts
 * @var list<array<string, mixed>> $categories arbre
 * @var array<string, string> $units
 * @var string $today
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="module module-budget">
    <div class="budget__form-layout">
        <form class="card budget__form" data-action="recurring-save" data-track-dirty data-save-shortcut autocomplete="off" novalidate>
            <div class="card__header"><h2 class="card__title"><?= $module->icon('refresh') ?> <?= $isNew ? 'Nouvelle récurrence' : 'Récurrence « ' . $e($recurring['label']) . ' »' ?></h2></div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $recurring['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="field__label" for="br-label">Libellé <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="br-label" name="label" value="<?= $e($recurring['label']) ?>" maxlength="200" required placeholder="Loyer, salaire, assurance, abonnement…"<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <span class="field__label">Nature <span class="required" aria-hidden="true">*</span></span>
                        <div class="flex gap-1">
                            <label class="radio"><input type="radio" name="type" value="expense"<?= $kind === 'expense' ? ' checked' : '' ?>> Dépense</label>
                            <label class="radio"><input type="radio" name="type" value="income"<?= $kind === 'income' ? ' checked' : '' ?>> Recette</label>
                        </div>
                    </div>
                    <div class="field">
                        <label class="field__label" for="br-amount">Montant (€) <span class="required" aria-hidden="true">*</span></label>
                        <input class="input mono" id="br-amount" name="amount" inputmode="decimal" value="<?= $e(\Atelier\Modules\Budget\Money::input($recurring['amount'], true)) ?>" placeholder="850,00" required>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="br-account">Compte <span class="required" aria-hidden="true">*</span></label>
                        <select class="select" id="br-account" name="account_id" required><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $recurring['account_id'] === (int) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?></option><?php endforeach; ?></select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="br-category">Catégorie</label>
                        <select class="select" id="br-category" name="category_id"><?= $module->categoryOptions($categories, $recurring['category_id']) ?></select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="br-payee">Tiers</label>
                        <input class="input" id="br-payee" name="payee" value="<?= $e($recurring['payee'] ?? '') ?>" maxlength="150">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="br-count">Périodicité <span class="required" aria-hidden="true">*</span></label>
                        <div class="input-group">
                            <span class="input input--sm budget__prefix" aria-hidden="true">tous les</span>
                            <input class="input mono budget__count" id="br-count" name="interval_count" inputmode="numeric" value="<?= (int) $recurring['interval_count'] ?>" min="1" max="366" required aria-label="Nombre">
                            <select class="select" name="interval_unit" aria-label="Unité"><?php foreach ($units as $code => $label): ?><option value="<?= $e($code) ?>"<?= $recurring['interval_unit'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                        </div>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="br-next">Prochaine échéance <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="br-next" name="next_at" type="date" value="<?= $e($recurring['next_at']) ?>" required>
                        <span class="field__help">L’opération est créée (« postée ») à cette date, puis l’échéance avance.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="br-ends">Fin (facultatif)</label>
                        <input class="input" id="br-ends" name="ends_at" type="date" value="<?= $e($recurring['ends_at'] ?? '') ?>">
                        <span class="field__error"></span>
                    </div>
                    <?php if (!$isNew): ?>
                        <div class="field"><label class="checkbox"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"<?= $recurring['active'] ? ' checked' : '' ?>> Active</label></div>
                    <?php endif; ?>
                    <div class="field field--full">
                        <label class="field__label" for="br-notes">Notes</label>
                        <textarea class="textarea" id="br-notes" name="notes" rows="3" maxlength="20000"><?= $e($recurring['notes'] ?? '') ?></textarea>
                        <span class="field__error"></span>
                    </div>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="forecast">Annuler</a>
                    <button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button>
                </div>
            </div>
        </form>
        <aside class="card">
            <div class="card__header"><h2 class="card__title"><?= $module->icon('info') ?> Comment ça marche</h2></div>
            <div class="card__body"><ul class="mb-0">
                <li>Une récurrence n’est pas une opération : elle alimente le prévisionnel et propose, à chaque échéance atteinte, de « poster » l’opération réelle (tableau de bord, badge du module).</li>
                <li>Le montant d’un salaire ou d’un loyer peut être ajusté ensuite sur l’opération postée sans toucher à la récurrence.</li>
                <li>Une fin de contrat ou de crédit se traduit par la date de fin : la récurrence se désactive d’elle-même.</li>
            </ul></div>
        </aside>
    </div>
</div>
