<?php
/**
 * Création / modification d'une catégorie.
 * @var array<string, mixed> $category
 * @var bool $isNew
 * @var array<string, string> $kinds
 * @var list<array<string, mixed>> $parents arbre complet
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="module module-budget">
    <div class="budget__form-layout">
        <form class="card budget__form" data-action="category-save" data-track-dirty data-save-shortcut autocomplete="off" novalidate>
            <div class="card__header"><h2 class="card__title"><?= $module->icon('tag') ?> <?= $isNew ? 'Nouvelle catégorie' : 'Catégorie « ' . $e($category['name']) . ' »' ?></h2></div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $category['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field field--full">
                        <label class="field__label" for="bc-name">Nom <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="bc-name" name="name" value="<?= $e($category['name']) ?>" maxlength="100" required<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <span class="field__label">Nature <span class="required" aria-hidden="true">*</span></span>
                        <div class="flex gap-1"><?php foreach ($kinds as $code => $label): ?><label class="radio"><input type="radio" name="kind" value="<?= $e($code) ?>"<?= $category['kind'] === $code ? ' checked' : '' ?>> <?= $e($label) ?></label><?php endforeach; ?></div>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bc-parent">Catégorie parente</label>
                        <select class="select" id="bc-parent" name="parent_id">
                            <option value="">— Aucune (premier niveau) —</option>
                            <?php foreach ($parents as $p): if (!$isNew && $p['id'] === $category['id']) { continue; } ?><option value="<?= (int) $p['id'] ?>"<?= (int) ($category['parent_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= $e($p['name']) ?> (<?= $e(strtolower($kinds[$p['kind']] ?? '')) ?>)</option><?php endforeach; ?>
                        </select>
                        <span class="field__help">Même nature que la parente ; deux niveaux au plus.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bc-order">Ordre d’affichage</label>
                        <input class="input mono" id="bc-order" name="sort_order" inputmode="numeric" value="<?= (int) $category['sort_order'] ?>">
                        <span class="field__error"></span>
                    </div>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="categories">Annuler</a>
                    <button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button>
                </div>
            </div>
        </form>
    </div>
</div>
