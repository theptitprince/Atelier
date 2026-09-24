<?php
/**
 * Saisie / modification d'une opération.
 * @var array<string, mixed> $transaction
 * @var bool $isNew
 * @var string $kind expense|income
 * @var list<array<string, mixed>> $accounts
 * @var list<array<string, mixed>> $categories arbre
 * @var array<string, string> $sources
 * @var string $today
 * @var list<string> $tags tags partagés (modification)
 * @var string|null $infoId (modification)
 * @var list<array<string, mixed>> $attachments (modification)
 * @var bool $attachmentsModule (modification)
 * @var array<string, bool> $rights (modification)
 * @var bool $readonly (modification)
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
$infoId ??= null;
$tags ??= [];
$attachments ??= [];
$attachmentsModule ??= false;
$rights ??= ['update' => true, 'delete' => false];
$readonly ??= false;
$ro = $readonly ? ' readonly' : '';
?>
<div class="module module-budget">
    <div class="budget__form-layout">
        <form class="card budget__form" data-action="transaction-save"<?= $readonly ? '' : ' data-track-dirty data-save-shortcut' ?> autocomplete="off" novalidate data-budget-transaction-form>
            <div class="card__header">
                <h2 class="card__title"><?= $module->icon($isNew ? 'plus' : 'edit') ?> <?= $isNew ? 'Nouvelle opération' : 'Opération n° ' . (int) $transaction['id'] ?></h2>
                <?php if (!$isNew && $transaction['source'] !== 'manual'): ?><span class="badge badge--info">Origine : <?= $e($sources[$transaction['source']] ?? $transaction['source']) ?><?= $transaction['source_ref'] !== null ? ' (' . $e($transaction['source_ref']) . ')' : '' ?></span><?php endif; ?>
            </div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $transaction['id'] ?>"><?php endif; ?>
                <?php if ($readonly): ?><div class="alert alert--info" role="status"><?= $module->icon('lock') ?><div>Lecture seule : vous n’avez pas le droit de modifier les opérations.</div></div><?php endif; ?>
                <div class="form-grid">
                    <div class="field">
                        <span class="field__label">Nature <span class="required" aria-hidden="true">*</span></span>
                        <div class="flex gap-1">
                            <label class="radio"><input type="radio" name="type" value="expense"<?= $kind === 'expense' ? ' checked' : '' ?><?= $readonly ? ' disabled' : '' ?>> Dépense</label>
                            <label class="radio"><input type="radio" name="type" value="income"<?= $kind === 'income' ? ' checked' : '' ?><?= $readonly ? ' disabled' : '' ?>> Recette</label>
                        </div>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bt-amount">Montant (€) <span class="required" aria-hidden="true">*</span></label>
                        <input class="input mono" id="bt-amount" name="amount" inputmode="decimal" value="<?= $e(\Atelier\Modules\Budget\Money::input($transaction['amount'], true)) ?>" placeholder="45,90" required<?= $ro ?><?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bt-date">Date <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="bt-date" name="done_at" type="date" value="<?= $e($transaction['done_at']) ?>" required<?= $ro ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bt-account">Compte <span class="required" aria-hidden="true">*</span></label>
                        <select class="select" id="bt-account" name="account_id" required<?= $readonly ? ' disabled' : '' ?>>
                            <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $transaction['account_id'] === (int) $a['id'] ? ' selected' : '' ?>><?= $e($a['name']) ?><?= $a['archived'] ? ' (archivé)' : '' ?></option><?php endforeach; ?>
                        </select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="bt-label">Libellé <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" id="bt-label" name="label" value="<?= $e($transaction['label']) ?>" maxlength="200" placeholder="Courses, loyer, salaire…"<?= $ro ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bt-payee">Tiers</label>
                        <input class="input" id="bt-payee" name="payee" value="<?= $e($transaction['payee'] ?? '') ?>" maxlength="150" placeholder="Commerçant, employeur…"<?= $ro ?>>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="bt-category">Catégorie</label>
                        <select class="select" id="bt-category" name="category_id"<?= $readonly ? ' disabled' : '' ?>><?= $module->categoryOptions($categories, $transaction['category_id']) ?></select>
                        <span class="field__help">Sans catégorie, l’opération n’entre dans aucun budget.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="checkbox"><input type="checkbox" name="cleared" value="1"<?= !empty($transaction['cleared']) ? ' checked' : '' ?><?= $readonly ? ' disabled' : '' ?>> Pointée (apparaît sur le relevé bancaire)</label>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="bt-tags"><?= $module->icon('tag', 'icon--sm') ?> Tags partagés</label>
                        <input class="input" type="text" id="bt-tags" name="tags" value="<?= $e(implode(', ', $tags)) ?>" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20"<?= $ro ?>>
                        <span class="field__help">Les tags existants sont proposés pendant la saisie ; Entrée ou virgule ajoute le tag. Ils sont communs à toute l’application.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="bt-notes">Notes</label>
                        <textarea class="textarea" id="bt-notes" name="notes" rows="4" maxlength="20000" data-editor="bbcode"<?= $ro ?>><?= $e($transaction['notes'] ?? '') ?></textarea>
                        <span class="field__error"></span>
                    </div>
                </div>
                <?php if (!$readonly): ?>
                    <div class="form-actions form-actions--end">
                        <a class="btn btn--ghost" href="#" data-route="transactions">Annuler</a>
                        <?php if ($isNew): ?><button type="submit" class="btn" data-budget-again><?= $module->icon('plus') ?> Enregistrer et saisir une autre</button><?php endif; ?>
                        <button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <aside class="budget__form-side">
            <?php if (!$isNew): ?>
                <section class="card">
                    <div class="card__header"><h2 class="card__title"><?= $module->icon('paperclip') ?> Justificatifs</h2><span class="badge badge--muted"><?= count($attachments) ?></span></div>
                    <div class="card__body"><?= $module->partial('_attachments', ['id' => (int) $transaction['id'], 'infoId' => $infoId, 'attachments' => $attachments, 'canUpdate' => $rights['update'], 'attachmentsModule' => $attachmentsModule]) ?></div>
                </section>
            <?php else: ?>
                <section class="card">
                    <div class="card__header"><h2 class="card__title"><?= $module->icon('info') ?> Conseils</h2></div>
                    <div class="card__body"><ul class="mb-0">
                        <li>Le montant se saisit toujours en positif ; la nature (dépense ou recette) donne le signe.</li>
                        <li>« Enregistrer et saisir une autre » garde le compte et la nature pour enchaîner les saisies.</li>
                        <li>Les justificatifs (factures, tickets) se joignent après enregistrement, depuis la fiche de l’opération.</li>
                    </ul></div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>
