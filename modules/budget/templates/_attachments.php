<?php
/**
 * Partiel : justificatifs joints à une opération (téléversement direct, lien vers le module Fichiers joints).
 * @var int $id
 * @var string|null $infoId
 * @var list<array<string, mixed>> $attachments
 * @var bool $canUpdate
 * @var bool $attachmentsModule
 * @var \Atelier\Modules\Budget\BudgetModule $module
 */
?>
<div class="budget__attachments">
    <?php if ($attachments === []): ?>
        <p class="text-muted<?= $canUpdate ? '' : ' mb-0' ?>">Aucun justificatif.</p>
    <?php else: ?>
        <ul class="list<?= $canUpdate ? ' mb-3' : '' ?>">
            <?php foreach ($attachments as $file): ?>
                <?php $isImage = str_starts_with((string) $file['mime'], 'image/'); $isPdf = $file['mime'] === 'application/pdf'; ?>
                <li class="list__item">
                    <?= $module->icon($isImage ? 'image' : 'file', 'text-muted') ?>
                    <span class="grow truncate"><a href="<?= $e($module->attachmentUrl((string) $file['id'])) ?>" download><?= $e($file['original_name']) ?></a><?= !empty($file['description']) ? ' <span class="text-muted text-small">· ' . $e($file['description']) . '</span>' : '' ?></span>
                    <span class="text-muted text-small text-nowrap"><?= $e(\Atelier\Support\Str::humanSize((int) $file['size'])) ?></span>
                    <?php if ($isImage || $isPdf): ?><a class="btn btn--sm btn--icon btn--ghost" href="<?= $e($module->attachmentUrl((string) $file['id'], true)) ?>" target="_blank" rel="noopener" title="Ouvrir"><?= $module->icon('eye') ?></a><?php endif; ?>
                    <?php if ($canUpdate): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="attachment-delete" data-params='{"id":<?= json_encode((string) $file['id']) ?>}' data-confirm="Retirer « <?= $e($file['original_name']) ?> » ?" title="Retirer"><?= $module->icon('close') ?></button><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canUpdate): ?>
        <form data-action="attach" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="field mb-2">
                <label class="sr-only" for="budget-attach-<?= (int) $id ?>">Fichiers</label>
                <div class="input-group">
                    <input class="input input--sm" type="file" id="budget-attach-<?= (int) $id ?>" name="files[]" multiple>
                    <button type="submit" class="btn btn--sm"><?= $module->icon('upload') ?> Joindre</button>
                </div>
                <span class="field__error"></span>
            </div>
            <div class="field mb-0">
                <input class="input input--sm" type="text" name="description" maxlength="500" placeholder="Description (facture, ticket…) — facultatif" aria-label="Description">
                <span class="field__error"></span>
            </div>
        </form>
        <?php if ($attachmentsModule && $infoId !== null): ?>
            <p class="text-small text-muted mt-2 mb-0"><a href="#" data-open-module="attachments" data-open-route="upload?info=<?= $e(rawurlencode($infoId)) ?>"><?= $module->icon('paperclip', 'icon--sm') ?> Glisser-déposer dans le module Fichiers joints</a></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
