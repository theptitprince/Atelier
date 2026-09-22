<?php
/**
 * Éditeur de page : titre, contenu (BBCode + syntaxe wiki), tags, lieux GPS, fichiers à insérer.
 * @var array<string, mixed> $page @var bool $isNew @var list<string> $tags @var list<array<string, mixed>> $points
 * @var list<array<string, mixed>> $attachments @var string|null $infoId @var int $titleMax @var int $contentMax
 * @var bool $geoModule @var bool $attachmentsModule @var bool $canDelete
 * @var \Atelier\Modules\Wiki\WikiModule $module
 */
$contentLength = mb_strlen((string) ($page['content'] ?? ''), 'UTF-8');
?>
<div class="module module-wiki">
    <form class="wiki__editor" data-action="save" data-track-dirty data-save-shortcut novalidate>
        <input type="hidden" name="id" value="<?= $isNew ? '' : (int) $page['id'] ?>">
        <div class="wiki__layout">
            <div class="card">
                <div class="card__body">
                    <div class="field">
                        <label class="field__label" for="wiki-title">Titre <span class="required" aria-hidden="true">*</span></label>
                        <input class="input wiki__title-input" type="text" id="wiki-title" name="title" value="<?= $e($page['title']) ?>" maxlength="<?= (int) $titleMax ?>" required autocomplete="off" placeholder="Titre de la page"<?= $isNew ? ' autofocus' : '' ?>>
                        <span class="field__help">Le titre sert de cible aux liens [[Titre]] des autres pages<?= $isNew ? ' ; l’identifiant d’adresse en est dérivé' : ' ; l’identifiant <code>' . $e($page['slug']) . '</code> ne change pas' ?>.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="wiki-content">Contenu</label>
                        <textarea class="textarea wiki__content-input" id="wiki-content" name="content" rows="22" maxlength="<?= (int) $contentMax ?>" data-counted data-editor="bbcode" placeholder="Rédigez… [[Autre page]] pour un lien interne, [file=identifiant] pour une image ou un fichier joint, [point=numéro] pour un lieu."><?= $e($page['content'] ?? '') ?></textarea>
                        <span class="field__help" data-counter aria-live="polite"><?= number_format($contentLength, 0, ',', ' ') ?> / <?= number_format((int) $contentMax, 0, ',', ' ') ?> caractères</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="wiki-tags"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg> Tags partagés</label>
                        <input class="input" type="text" id="wiki-tags" name="tags" value="<?= $e(implode(', ', $tags)) ?>" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20">
                        <span class="field__help">Les tags existants sont proposés pendant la saisie (Entrée ou virgule pour ajouter).</span>
                        <span class="field__error"></span>
                    </div>
                </div>
                <div class="card__footer form-actions">
                    <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer</button>
                    <span class="text-muted text-small">Ctrl+S · chaque enregistrement crée une version</span>
                    <a class="btn btn--ghost" href="#" data-route="<?= $isNew ? 'list' : 'show/' . $e($page['slug']) ?>">Annuler</a>
                    <span class="toolbar__spacer grow"></span>
                    <?php if ($canDelete): ?><button type="button" class="btn btn--outline-danger" data-action="delete" data-params='{"id":<?= (int) $page['id'] ?>}' data-confirm="Mettre cette page à la corbeille ?" data-danger><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer</button><?php endif; ?>
                </div>
            </div>

            <aside class="wiki__side">
                <section class="card card--compact">
                    <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-link"></use></svg> Lier une page</h2></div>
                    <div class="card__body">
                        <div class="field mb-0">
                            <label class="sr-only" for="wiki-page-search">Rechercher une page</label>
                            <div class="input-icon"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg><input class="input input--sm" type="search" id="wiki-page-search" placeholder="Rechercher une page à insérer" autocomplete="off" data-wiki-page-search></div>
                            <span class="field__help">Un clic insère <code>[[Titre]]</code> à la position du curseur.</span>
                        </div>
                        <ul class="list wiki__picker" data-wiki-page-results hidden></ul>
                    </div>
                </section>

                <?php if ($geoModule): ?>
                    <section class="card card--compact">
                        <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-map-pin"></use></svg> Lieux</h2></div>
                        <div class="card__body">
                            <ul class="list wiki__points mb-2" data-wiki-points>
                                <?php foreach ($points as $point): ?>
                                    <li class="list__item" data-wiki-point="<?= (int) $point['id'] ?>"><input type="hidden" name="points[]" value="<?= (int) $point['id'] ?>"><span class="grow"><?= $e($point['label']) ?><br><span class="mono text-muted text-small"><?= $e($point['dms']) ?></span></span><button type="button" class="btn btn--sm btn--icon btn--ghost" data-wiki-insert="[point=<?= (int) $point['id'] ?>]" title="Insérer dans le texte"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg></button><button type="button" class="btn btn--sm btn--icon btn--ghost" data-wiki-point-remove title="Retirer"><svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg></button></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="field mb-0">
                                <label class="sr-only" for="wiki-point-search">Rechercher un lieu</label>
                                <div class="input-icon"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg><input class="input input--sm" type="search" id="wiki-point-search" placeholder="Rechercher un point GPS (nom, code, coordonnées)" autocomplete="off" data-wiki-point-search></div>
                                <span class="field__help">Rattache la page au lieu (relation « localisé à ») ; visible sur la fiche du point et sur la carte.</span>
                                <span class="field__error"></span>
                            </div>
                            <ul class="list wiki__picker" data-wiki-point-results hidden></ul>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="card card--compact">
                    <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-paperclip"></use></svg> Images et fichiers</h2></div>
                    <div class="card__body">
                        <?php if ($isNew): ?>
                            <p class="text-muted mb-0">Enregistrez d’abord la page pour lui joindre des fichiers.</p>
                        <?php else: ?>
                            <?php if ($attachments === []): ?><p class="text-muted">Aucun fichier joint à cette page.</p><?php else: ?>
                                <ul class="list mb-3">
                                    <?php foreach ($attachments as $file): ?>
                                        <?php $isImage = str_starts_with((string) $file['mime'], 'image/'); ?>
                                        <li class="list__item"><svg class="icon text-muted" aria-hidden="true"><use href="#i-<?= $isImage ? 'image' : 'file' ?>"></use></svg><span class="grow truncate" title="<?= $e($file['original_name']) ?>"><?= $e($file['original_name']) ?></span><button type="button" class="btn btn--sm btn--ghost" data-wiki-insert="[file=<?= $e($file['id']) ?>]" title="Insérer dans le texte"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg> <?= $isImage ? 'Image' : 'Lien' ?></button></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($attachmentsModule && $infoId !== null): ?><a class="btn btn--sm" href="#" data-open-module="attachments" data-open-route="upload?info=<?= $e($infoId) ?>"><svg class="icon" aria-hidden="true"><use href="#i-upload"></use></svg> Joindre un fichier</a><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="card card--compact">
                    <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg> Syntaxe</h2></div>
                    <div class="card__body text-small">
                        <p><code>[[Titre]]</code> ou <code>[[Titre|libellé]]</code> : lien interne (rouge si la page n’existe pas).</p>
                        <p><code>[file=id]</code>, <code>[file=id|légende|400]</code> : image (largeur en px) ou fichier joint.</p>
                        <p class="mb-0"><code>[point=n]</code> : lieu GPS. Le reste : BBCode commun (barre d’outils).</p>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>
