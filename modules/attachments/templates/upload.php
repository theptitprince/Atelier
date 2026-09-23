<?php
/**
 * Téléversement de fichiers (un ou plusieurs) : nom d'affichage, tags, dossier, description et rattachement facultatifs.
 * @var array{id: string, label: string, dataset: string, module: string}|null $target
 * @var int $maxFileSize
 * @var list<string> $allowedMimes
 * @var int $remaining
 * @var list<array{id: int, name: string, path: string, depth: int}> $folders
 * @var int|null $currentFolderId
 * @var \Atelier\Modules\Attachments\AttachmentsModule $module
 */
$cancelRoute = $currentFolderId !== null ? 'list?folder=' . $currentFolderId : 'list';
?>
<div class="module module-attachments">
    <div class="attachments__upload-layout">
        <form class="card" data-action="upload" enctype="multipart/form-data" novalidate data-attachments-upload data-attachments-cancel="<?= $e($cancelRoute) ?>">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-upload"></use></svg> Fichiers à téléverser</h2></div>
            <div class="card__body">
                <div class="field">
                    <label class="field__label" for="att-files">Fichiers <span class="required" aria-hidden="true">*</span></label>
                    <div class="dropzone" data-attachments-dropzone tabindex="0" role="button" aria-label="Déposer des fichiers ici ou cliquer pour choisir">
                        <svg class="icon icon--xl" aria-hidden="true"><use href="#i-upload"></use></svg>
                        <p class="mb-0">Glissez-déposez vos fichiers ici, ou <span class="btn btn--link">choisissez-les</span>.</p>
                        <input class="sr-only" type="file" id="att-files" name="files[]" multiple data-attachments-input>
                    </div>
                    <span class="field__help"><?= $e($module->humanSize($maxFileSize)) ?> au plus par fichier · espace restant : <?= $e($module->humanSize($remaining)) ?>. Exécutables, scripts et archives sont refusés.</span>
                    <span class="field__error"></span>
                </div>
                <ul class="list attachments__queue" data-attachments-queue hidden></ul>

                <div class="attachments__upload-grid">
                    <div class="field">
                        <label class="field__label" for="att-label">Nom d’affichage</label>
                        <input class="input" id="att-label" name="label" maxlength="200" placeholder="facultatif : nom lisible affiché dans les listes">
                        <span class="field__help">Le nom du fichier d’origine reste celui du téléchargement. Pour plusieurs fichiers, le nom est suffixé d’un numéro d’ordre : « Nom (1) », « Nom (2) »…</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="att-folder">Dossier</label>
                        <select class="select" id="att-folder" name="folder_id">
                            <option value=""<?= $currentFolderId === null ? ' selected' : '' ?>>Racine (non rangés)</option>
                            <?php foreach ($folders as $option): ?>
                                <option value="<?= (int) $option['id'] ?>"<?= $option['id'] === $currentFolderId ? ' selected' : '' ?>><?= $e(str_repeat('— ', $option['depth']) . $option['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field__help">Dossier virtuel de classement ; créez-en depuis la liste des fichiers.</span>
                        <span class="field__error"></span>
                    </div>
                </div>

                <div class="field">
                    <label class="field__label" for="att-tags">Tags</label>
                    <input class="input" type="text" id="att-tags" name="tags" value="" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20">
                    <span class="field__help">Appliqués à tous les fichiers de cet envoi ; les tags existants sont proposés pendant la saisie.</span>
                    <span class="field__error"></span>
                </div>

                <div class="field">
                    <label class="field__label" for="att-description">Description</label>
                    <input class="input" id="att-description" name="description" maxlength="500" placeholder="facultatif, appliquée à tous les fichiers de cet envoi">
                    <span class="field__error"></span>
                </div>

                <div class="field">
                    <label class="field__label" for="att-link-search">Rattacher à une information</label>
                    <input type="hidden" name="info_id" value="<?= $e($target['id'] ?? '') ?>" data-attachments-link-id>
                    <div class="attachments__target" data-attachments-target<?= $target === null ? ' hidden' : '' ?>>
                        <span class="chip">
                            <svg class="icon icon--sm" aria-hidden="true"><use href="#i-link"></use></svg>
                            <span data-attachments-target-label><?= $target !== null ? $e($target['label']) . ' · ' . $e($target['module']) : '' ?></span>
                            <button type="button" class="chip__remove" data-attachments-target-clear title="Retirer le rattachement" aria-label="Retirer le rattachement"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-close"></use></svg></button>
                        </span>
                    </div>
                    <div class="input-icon" data-attachments-search-wrap<?= $target !== null ? ' hidden' : '' ?>>
                        <svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg>
                        <input class="input" type="search" id="att-link-search" placeholder="Rechercher une information (point GPS, fiche…) — facultatif" autocomplete="off" data-attachments-link-search>
                    </div>
                    <ul class="list attachments__link-results" data-attachments-link-results hidden></ul>
                    <span class="field__help">Facultatif : un fichier non rattaché reste consultable par son auteur et l’assistance, et pourra être rattaché plus tard depuis sa fiche. Un fichier rattaché devient consultable par toute personne autorisée à lire l’information.</span>
                    <span class="field__error"></span>
                </div>

                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="<?= $e($cancelRoute) ?>">Annuler</a>
                    <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-upload"></use></svg> Téléverser</button>
                </div>
            </div>
        </form>

        <aside class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg> Sécurité des fichiers</h2></div>
            <div class="card__body">
                <ul class="mb-3">
                    <li>Type réel contrôlé (pas seulement l’extension), taille et quotas vérifiés.</li>
                    <li>Stockage hors du répertoire Web, sous un nom interne imprévisible, <strong>chiffré</strong> (AES-256-GCM, clé locale du serveur).</li>
                    <li>Téléchargement uniquement par l’application, après contrôle des droits, sans mise en cache ; chaque téléchargement est journalisé.</li>
                    <li>Empreinte SHA-256 conservée : l’intégrité peut être vérifiée à tout moment.</li>
                    <li>Les dossiers et les tags sont des métadonnées : ils n’influent ni sur l’emplacement ni sur le nom du fichier stocké.</li>
                </ul>
                <h4>Types acceptés</h4>
                <p class="text-small text-muted mb-0"><?= $e(implode(', ', array_map(static fn (string $m): string => str_replace(['application/vnd.openxmlformats-officedocument.', 'application/vnd.oasis.opendocument.', 'application/', 'image/', 'text/'], ['MS Office ', 'OpenDocument ', '', 'image ', 'texte '], $m), $allowedMimes))) ?></p>
            </div>
        </aside>
    </div>
</div>
