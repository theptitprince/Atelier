<?php
/**
 * Partiel : pièces jointes d'un équipement, d'une tâche ou d'une intervention, avec téléversement direct
 * et lien vers le module Fichiers joints (rattachement par identifiant de registre).
 * @var string $target asset|job|log
 * @var int $id
 * @var string|null $infoId
 * @var list<array<string, mixed>> $attachments
 * @var bool $canUpdate
 * @var bool $attachmentsModule
 * @var string $baseUrl
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$formId = 'maintenance-attach-' . $target . '-' . $id;
?>
<div class="maintenance__attachments">
    <?php if ($attachments === []): ?>
        <p class="text-muted<?= $canUpdate ? '' : ' mb-0' ?>">Aucune pièce jointe.</p>
    <?php else: ?>
        <ul class="list<?= $canUpdate ? ' mb-3' : '' ?>">
            <?php foreach ($attachments as $file): ?>
                <?php $isImage = str_starts_with((string) $file['mime'], 'image/'); $isPdf = $file['mime'] === 'application/pdf'; ?>
                <li class="list__item">
                    <?= $module->icon($isImage ? 'image' : 'file', 'text-muted') ?>
                    <span class="grow truncate">
                        <a href="<?= $e($module->attachmentUrl((string) $file['id'])) ?>" download title="Télécharger <?= $e($file['original_name']) ?>"><?= $e($file['original_name']) ?></a>
                        <?php if (!empty($file['description'])): ?><span class="text-muted text-small">· <?= $e($file['description']) ?></span><?php endif; ?>
                    </span>
                    <span class="text-muted text-small text-nowrap"><?= $e(\Atelier\Support\Str::humanSize((int) $file['size'])) ?> · <?= $e($date($file['created_at'] ?? null)) ?></span>
                    <?php if ($isImage || $isPdf): ?>
                        <a class="btn btn--sm btn--icon btn--ghost" href="<?= $e($module->attachmentUrl((string) $file['id'], true)) ?>" target="_blank" rel="noopener" title="Ouvrir dans un nouvel onglet"><?= $module->icon('eye') ?></a>
                    <?php endif; ?>
                    <?php if ($canUpdate): ?>
                        <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="attachment-delete" data-params='{"id":<?= json_encode((string) $file['id']) ?>}' data-confirm="Retirer « <?= $e($file['original_name']) ?> » ? Le fichier passe dans la corbeille des fichiers joints." title="Retirer"><?= $module->icon('close') ?></button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canUpdate): ?>
        <form class="maintenance__attach-form" data-action="attach" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="target" value="<?= $e($target) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="field mb-2">
                <label class="sr-only" for="<?= $e($formId) ?>">Fichiers à joindre</label>
                <div class="input-group">
                    <input class="input input--sm" type="file" id="<?= $e($formId) ?>" name="files[]" multiple>
                    <button type="submit" class="btn btn--sm" title="Joindre les fichiers choisis"><?= $module->icon('upload') ?> Joindre</button>
                </div>
                <span class="field__error"></span>
            </div>
            <div class="field mb-0">
                <label class="sr-only" for="<?= $e($formId) ?>-desc">Description</label>
                <input class="input input--sm" type="text" id="<?= $e($formId) ?>-desc" name="description" maxlength="500" placeholder="Description (facture, notice, photo…) — facultatif">
                <span class="field__error"></span>
            </div>
        </form>
        <?php if ($attachmentsModule && $infoId !== null): ?>
            <p class="text-small text-muted mt-2 mb-0">
                <a href="#" data-open-module="attachments" data-open-route="upload?info=<?= $e(rawurlencode($infoId)) ?>"><?= $module->icon('paperclip', 'icon--sm') ?> Téléverser par glisser-déposer dans le module Fichiers joints</a>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
