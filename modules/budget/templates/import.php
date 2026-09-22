<?php
/**
 * Import CSV d'un relevé bancaire.
 * @var list<array<string, mixed>> $accounts
 * @var list<array<string, mixed>> $categories arbre
 * @var array<string, list<string>> $headers
 * @var int $maxRows
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="module module-budget">
    <div class="budget__form-layout">
        <form class="card" data-action="import" enctype="multipart/form-data" novalidate data-budget-import>
            <div class="card__header"><h2 class="card__title"><?= $module->icon('upload') ?> Relevé à importer</h2></div>
            <div class="card__body">
                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="bi-account">Compte <span class="required" aria-hidden="true">*</span></label>
                        <select class="select" id="bi-account" name="account_id" required><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= $e($a['name']) ?></option><?php endforeach; ?></select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bi-category">Catégorie par défaut</label>
                        <select class="select" id="bi-category" name="category_id"><?= $module->categoryOptions($categories, null) ?></select>
                        <span class="field__help">Appliquée quand ni la colonne « catégorie » ni un libellé déjà connu ne permettent de deviner.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="bi-file">Fichier CSV <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" type="file" id="bi-file" name="file" accept=".csv,text/csv,text/plain" required>
                        <span class="field__help">Colonnes reconnues : date ; libellé ; montant (signé) ou débit / crédit ; tiers ; catégorie. <?= (int) $maxRows ?> lignes au plus. Les lignes déjà importées (même compte, date, montant, libellé) sont ignorées.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field"><label class="checkbox"><input type="checkbox" name="invert" value="1"> Inverser le signe des montants (relevés où les débits sont positifs)</label></div>
                    <div class="field"><label class="checkbox"><input type="checkbox" name="cleared" value="1" checked> Marquer les opérations comme pointées</label></div>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="transactions">Annuler</a>
                    <button type="submit" class="btn btn--primary"><?= $module->icon('upload') ?> Importer</button>
                </div>
            </div>
        </form>
        <aside class="budget__form-side">
            <section class="card" data-budget-import-report hidden aria-live="polite">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('info') ?> Rapport d’import</h2></div>
                <div class="card__body">
                    <p data-budget-import-summary></p>
                    <div data-budget-import-errors hidden>
                        <h4>Lignes rejetées</h4>
                        <div class="table-wrap"><table class="table table--compact"><thead><tr><th>Ligne</th><th>Libellé</th><th>Motif</th></tr></thead><tbody data-budget-import-errors-body></tbody></table></div>
                    </div>
                    <a class="btn btn--sm mt-2" href="#" data-route="transactions?source=import">Voir les opérations importées</a>
                </div>
            </section>
            <section class="card">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('book') ?> En-têtes acceptés</h2></div>
                <div class="card__body">
                    <dl class="dl">
                        <?php foreach ($headers as $field => $aliases): ?><dt><?= $e($field) ?></dt><dd class="text-small text-muted"><?= $e(implode(', ', $aliases)) ?></dd><?php endforeach; ?>
                    </dl>
                    <p class="text-small text-muted mb-0">Dates au format JJ/MM/AAAA ou AAAA-MM-JJ ; montants « 1 234,56 » ou « 1234.56 ». Encodage UTF-8 ou Windows-1252.</p>
                </div>
            </section>
        </aside>
    </div>
</div>
