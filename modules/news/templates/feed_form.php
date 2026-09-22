<?php
/**
 * Création / modification d'un flux.
 * @var array<string, mixed> $feed @var bool $isNew @var list<array<string, mixed>> $categories @var array<int, string> $refreshChoices
 */
?>
<div class="module module-news">
    <div class="news__form-layout">
        <form class="card" data-action="feed-save" data-track-dirty data-save-shortcut autocomplete="off" novalidate>
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-globe"></use></svg> <?= $isNew ? 'Nouveau flux' : 'Flux « ' . $e($feed['title']) . ' »' ?></h2></div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $feed['id'] ?>"><?php endif; ?>
                <div class="field">
                    <label class="field__label" for="feed-url">Adresse du flux <span class="required" aria-hidden="true">*</span></label>
                    <input class="input mono" id="feed-url" name="url" type="url" value="<?= $e($feed['url']) ?>" required maxlength="500" placeholder="https://exemple.fr/rss.xml"<?= $isNew ? ' autofocus' : '' ?>>
                    <span class="field__help">RSS 2.0, RSS 1.0, Atom ou JSON Feed. Le flux est vérifié et récupéré à l’enregistrement.</span>
                    <span class="field__error"></span>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="feed-title">Titre</label>
                        <input class="input" id="feed-title" name="title" value="<?= $e($feed['title']) ?>" maxlength="250" placeholder="lu depuis le flux si vide">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="feed-category">Catégorie</label>
                        <select class="select" id="feed-category" name="category_id">
                            <option value="">Sans catégorie</option>
                            <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"<?= (int) ($feed['category_id'] ?? 0) === (int) $category['id'] ? ' selected' : '' ?>><?= $e($category['name']) ?></option><?php endforeach; ?>
                        </select>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="feed-refresh">Fréquence de récupération</label>
                        <select class="select" id="feed-refresh" name="refresh_minutes">
                            <?php $current = (int) $feed['refresh_minutes']; $listed = false; ?>
                            <?php foreach ($refreshChoices as $minutes => $label): ?>
                                <?php $listed = $listed || $current === $minutes; ?>
                                <option value="<?= (int) $minutes ?>"<?= $current === $minutes ? ' selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                            <?php if (!$listed): ?><option value="<?= $current ?>" selected><?= $current ?> minutes</option><?php endif; ?>
                        </select>
                        <span class="field__help">Appliquée par la tâche de fond (<code>cron:run</code>) ; à défaut, à l’ouverture du fil.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="feed-retention">Rétention des entrées (jours)</label>
                        <input class="input" id="feed-retention" name="retention_days" type="number" min="1" max="3650" value="<?= (int) $feed['retention_days'] ?>">
                        <span class="field__help">Les faits archivés ne sont jamais purgés.</span>
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="field__label" for="feed-description">Description</label>
                        <input class="input" id="feed-description" name="description" value="<?= $e($feed['description'] ?? '') ?>" maxlength="1000" placeholder="lue depuis le flux si vide">
                        <span class="field__error"></span>
                    </div>
                    <div class="field field--full">
                        <label class="checkbox"><input type="checkbox" name="is_active" value="1"<?= (int) ($feed['is_active'] ?? 1) === 1 ? ' checked' : '' ?>> <span>Flux actif (récupéré automatiquement)</span></label>
                    </div>
                    <div class="field field--full">
                        <label class="checkbox"><input type="checkbox" name="fetch_content" value="1"<?= (int) ($feed['fetch_content'] ?? 1) === 1 ? ' checked' : '' ?>> <span>Télécharger les articles en local (texte principal conservé même si la source disparaît)</span></label>
                    </div>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="feeds">Annuler</a>
                    <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer et récupérer</button>
                </div>
            </div>
        </form>
        <aside class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg> Repères</h2></div>
            <div class="card__body">
                <p>Les adresses internes (localhost, réseau privé) sont refusées. Les réponses sont limitées à 5 Mo et 10 secondes.</p>
                <p>Le module respecte les en-têtes <code>ETag</code> et <code>Last-Modified</code> : un flux inchangé n’est pas retéléchargé.</p>
                <p class="mb-0">Les centres d’intérêt sont appliqués à chaque nouvelle entrée (titre et résumé).</p>
                <?php if (!$isNew && !empty($feed['site_url'])): ?><p class="mt-3 mb-0"><a href="<?= $e($feed['site_url']) ?>" target="_blank" rel="noopener noreferrer">Site du flux <svg class="icon icon--sm" aria-hidden="true"><use href="#i-external"></use></svg></a></p><?php endif; ?>
            </div>
        </aside>
    </div>
</div>
