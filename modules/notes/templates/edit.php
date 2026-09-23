<?php
/**
 * Éditeur de note (création et modification).
 * Variables : $note (array : id, title, content, created_at, updated_at), $tags (list<string>), $isNew,
 *             $version (jeton de concurrence), $canUpdate, $canDelete, $titleMax, $contentMax, $module, $e, $datetime.
 */
$readonly = !$canUpdate;
$contentLength = mb_strlen((string) ($note['content'] ?? ''), 'UTF-8');
?>
<div class="module module-notes">
    <form class="notes__editor card" data-action="save"<?= $readonly ? '' : ' data-track-dirty data-save-shortcut' ?> novalidate>
        <input type="hidden" name="id" value="<?= $isNew ? '' : (int) $note['id'] ?>">
        <input type="hidden" name="version" value="<?= $e($version) ?>">

        <div class="card__body">
            <?php if ($readonly): ?>
                <div class="alert alert--info" role="status">
                    <svg class="icon" aria-hidden="true"><use href="#i-lock"></use></svg>
                    <div>Vous ne disposez pas du droit de modification : cette note est affichée en lecture seule.</div>
                </div>
            <?php endif; ?>

            <div class="field">
                <label class="field__label" for="note-title">Titre <span class="required" aria-hidden="true">*</span></label>
                <input class="input notes__title-input" type="text" id="note-title" name="title" value="<?= $e($note['title']) ?>" maxlength="<?= (int) $titleMax ?>" required autocomplete="off" placeholder="Titre de la note"<?= $readonly ? ' readonly' : ' autofocus' ?>>
                <span class="field__help">Obligatoire, <?= (int) $titleMax ?> caractères au maximum.</span>
            </div>

            <div class="field">
                <label class="field__label" for="note-content">Contenu</label>
                <textarea class="textarea notes__content" id="note-content" name="content" rows="16" maxlength="<?= (int) $contentMax ?>" data-counted data-editor="bbcode" placeholder="Rédigez votre note… (mise en forme BBCode : [b]gras[/b], [i]italique[/i], listes, liens)"<?= $readonly ? ' readonly' : '' ?>><?= $e($note['content'] ?? '') ?></textarea>
                <span class="field__help notes__counter" data-counter aria-live="polite"><?= number_format($contentLength, 0, ',', ' ') ?> / <?= number_format((int) $contentMax, 0, ',', ' ') ?> caractères</span>
            </div>

            <div class="field">
                <label class="field__label" for="note-tags">
                    <svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg> Tags partagés
                </label>
                <input class="input" type="text" id="note-tags" name="tags" value="<?= $e(implode(', ', $tags)) ?>" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20"<?= $readonly ? ' readonly' : '' ?>>
                <span class="field__help">Les tags existants sont proposés pendant la saisie ; Entrée ou virgule ajoute le tag. Ils sont communs à toute l’application (60 caractères maximum chacun).</span>
            </div>

            <?php if (!$isNew): ?>
                <dl class="dl notes__meta">
                    <dt>Créée le</dt><dd><?= $e($datetime($note['created_at'])) ?></dd>
                    <dt>Modifiée le</dt><dd><?= $e($datetime($note['updated_at'])) ?></dd>
                </dl>
            <?php endif; ?>
        </div>

        <div class="card__footer form-actions">
            <?php if ($canUpdate): ?>
                <button type="submit" class="btn btn--primary">
                    <svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer
                </button>
                <span class="text-muted text-small">Ctrl+S</span>
            <?php endif; ?>
            <a class="btn btn--ghost" href="#" data-route="list">Retour à la liste</a>
            <span class="toolbar__spacer grow"></span>
            <?php if ($canDelete): ?>
                <button type="button" class="btn btn--outline-danger" data-action="delete" data-params='{"id":<?= (int) $note['id'] ?>}' data-confirm="Placer cette note dans la corbeille ? Vous pourrez la restaurer pendant 30 jours." data-confirm-title="Supprimer la note" data-confirm-label="Supprimer" data-danger>
                    <svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>
