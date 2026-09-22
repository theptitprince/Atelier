<?php
/**
 * Création / modification d'un compte.
 * @var array<string, mixed> $account
 * @var bool $isNew
 * @var array<string, string> $kinds
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="module module-budget">
    <div class="budget__form-layout">
        <form class="card budget__form" data-action="account-save" data-track-dirty data-save-shortcut autocomplete="off" novalidate>
            <div class="card__header"><h2 class="card__title"><?= $module->icon('database') ?> <?= $isNew ? 'Nouveau compte' : 'Compte « ' . $e($account['name']) . ' »' ?></h2></div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="field__label" for="ba-name">Nom <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="ba-name" name="name" value="<?= $e($account['name']) ?>" maxlength="100" required placeholder="Compte courant, Livret A, Espèces…"<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ba-kind">Nature <span class="required" aria-hidden="true">*</span></label>
                        <select class="select" id="ba-kind" name="kind"><?php foreach ($kinds as $code => $label): ?><option value="<?= $e($code) ?>"<?= $account['kind'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ba-order">Ordre d’affichage</label>
                        <input class="input mono" id="ba-order" name="sort_order" inputmode="numeric" value="<?= (int) $account['sort_order'] ?>">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ba-initial">Solde initial (€)</label>
                        <input class="input mono" id="ba-initial" name="initial_balance" inputmode="decimal" value="<?= $e(\Atelier\Modules\Budget\Money::input($account['initial_balance'])) ?>" placeholder="0,00">
                        <span class="field__help">Solde au jour de départ du suivi ; un découvert se saisit en négatif (-80).</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="ba-opened">Date de départ</label>
                        <input class="input" id="ba-opened" name="opened_at" type="date" value="<?= $e($account['opened_at'] ?? '') ?>">
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="ba-notes">Notes</label>
                        <input class="input" id="ba-notes" name="notes" value="<?= $e($account['notes'] ?? '') ?>" maxlength="300" placeholder="Banque, IBAN partiel, usage…">
                        <span class="field__error"></span>
                    </div>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="accounts">Annuler</a>
                    <button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button>
                </div>
            </div>
        </form>
    </div>
</div>
