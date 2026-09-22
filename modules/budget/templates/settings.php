<?php
/**
 * Réglages : report des coûts du module Entretien.
 * @var list<array<string, mixed>> $accounts
 * @var list<array<string, mixed>> $categories arbre (dépenses)
 * @var int $accountId
 * @var int $categoryId
 * @var bool $auto
 * @var string $fallbackCategory
 * @var bool $maintenanceAvailable
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="module module-budget">
    <div class="budget__form-layout">
        <form class="card budget__form" data-action="settings-save" data-track-dirty data-save-shortcut novalidate>
            <div class="card__header"><h2 class="card__title"><?= $module->icon('tool') ?> Coûts venant du module Entretien</h2><?= $maintenanceAvailable ? '<span class="badge badge--success">module présent</span>' : '<span class="badge badge--muted">module absent</span>' ?></div>
            <div class="card__body">
                <div class="form-grid">
                    <div class="field field--full"><label class="checkbox"><input type="hidden" name="auto" value="0"><input type="checkbox" name="auto" value="1"<?= $auto ? ' checked' : '' ?>> Reporter automatiquement le coût réel de chaque intervention enregistrée dans l’Entretien comme une dépense du budget</label></div>
                    <div class="field">
                        <label class="field__label" for="bset-account">Compte débité</label>
                        <select class="select" id="bset-account" name="account_id"><option value="">— Premier compte courant actif —</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $accountId === (int) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?></option><?php endforeach; ?></select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bset-category">Catégorie de dépense</label>
                        <select class="select" id="bset-category" name="category_id"><option value="">— « <?= $e($fallbackCategory) ?> » (créée au besoin) —</option><?= $module->categoryOptions($categories, $categoryId > 0 ? $categoryId : null, 'expense', false) ?></select>
                        <span class="field__error"></span>
                    </div>
                </div>
                <div class="form-actions form-actions--end"><button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button></div>
            </div>
        </form>
        <aside class="card">
            <div class="card__header"><h2 class="card__title"><?= $module->icon('info') ?> Fonctionnement</h2></div>
            <div class="card__body"><ul class="mb-0">
                <li>Chaque intervention d’entretien avec un coût crée (ou met à jour) une opération d’origine « Entretien », identifiée par sa référence ; la supprimer dans l’Entretien la retire du budget.</li>
                <li>Les coûts estimés des entretiens à venir alimentent le prévisionnel sans créer d’opération.</li>
                <li>L’utilisateur qui enregistre l’intervention doit disposer du droit de création sur le jeu « Opérations » du budget ; sinon rien n’est reporté.</li>
            </ul></div>
        </aside>
    </div>
</div>
