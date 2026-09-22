<?php
/**
 * Objectif d'épargne.
 * @var array<string, mixed> $goal
 * @var bool $isNew
 * @var list<array<string, mixed>> $accounts
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="module module-budget">
    <div class="budget__form-layout">
        <form class="card budget__form" data-action="goal-save" data-track-dirty data-save-shortcut autocomplete="off" novalidate>
            <div class="card__header"><h2 class="card__title"><?= $module->icon('archive') ?> <?= $isNew ? 'Nouvel objectif' : 'Objectif « ' . $e($goal['name']) . ' »' ?></h2></div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $goal['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="field__label" for="bg-name">Nom <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="bg-name" name="name" value="<?= $e($goal['name']) ?>" maxlength="150" required placeholder="Vacances, voiture, réserve de sécurité…"<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bg-target">Montant cible (€) <span class="required" aria-hidden="true">*</span></label>
                        <input class="input mono" id="bg-target" name="target" inputmode="decimal" value="<?= $e(\Atelier\Modules\Budget\Money::input($goal['target'])) ?>" required placeholder="3 000">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bg-due">Échéance souhaitée</label>
                        <input class="input" id="bg-due" name="due_at" type="date" value="<?= $e($goal['due_at'] ?? '') ?>">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bg-account">Compte d’épargne associé</label>
                        <select class="select" id="bg-account" name="account_id"><option value="">— Aucun (montant saisi à la main) —</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) ($goal['account_id'] ?? 0) === (int) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?> (<?= $e($module->money($a['balance'])) ?>)</option><?php endforeach; ?></select>
                        <span class="field__help">Si un compte est associé, sa progression est son solde réel.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bg-current">Montant déjà épargné (€)</label>
                        <input class="input mono" id="bg-current" name="current" inputmode="decimal" value="<?= $e(\Atelier\Modules\Budget\Money::input($goal['current'])) ?>" placeholder="0,00">
                        <span class="field__help">Utilisé seulement sans compte associé.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="bg-notes">Notes</label>
                        <textarea class="textarea" id="bg-notes" name="notes" rows="3" maxlength="20000"><?= $e($goal['notes'] ?? '') ?></textarea>
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
