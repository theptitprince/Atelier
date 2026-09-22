<?php
/**
 * Téléversement de fichiers (un ou plusieurs), description et rattachement facultatifs.
 * @var array{id: string, label: string, dataset: string, module: string}|null $target
 * @var int $maxFileSize
 * @var list<string> $allowedMimes
 * @var int $remaining
 * @var \Atelier\Modules\Attachments\AttachmentsModule $module
 */
?>
<div class="module module-attachments">
    <div class="attachments__upload-layout">
        <form class="card" data-action="upload" enctype="multipart/form-data" novalidate data-attachments-upload>
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
                    <span class="field__help">Le fichier devient consultable par toute personne autorisée à lire l’information rattachée.</span>
                    <span class="field__error"></span>
                </div>

                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="list">Annuler</a>
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
                </ul>
                <h4>Types acceptés</h4>
                <p class="text-small text-muted mb-0"><?= $e(implode(', ', array_map(static fn (string $m): string => str_replace(['application/vnd.openxmlformats-officedocument.', 'application/vnd.oasis.opendocument.', 'application/', 'image/', 'text/'], ['MS Office ', 'OpenDocument ', '', 'image ', 'texte '], $m), $allowedMimes))) ?></p>
            </div>
        </aside>
    </div>
</div>
