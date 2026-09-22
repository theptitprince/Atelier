<?php
/**
 * Paramètres du module Pages : page d'arrivée (liste ou page d'accueil) et choix de la page d'accueil.
 * @var string $landing @var string $homeSlug @var bool $homeMissing @var list<array<string, mixed>> $pages
 */
?>
<div class="module module-wiki">
    <div class="card">
        <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-home"></use></svg> Page d’arrivée du module</h2></div>
        <div class="card__body">
            <p class="text-muted">En cliquant sur « Pages » dans la colonne de gauche (ou sur son onglet), l’utilisateur arrive soit sur la liste de toutes les pages, soit sur une page d’accueil choisie ici. La liste reste toujours accessible par l’entrée « Toutes les pages » du menu et par le bouton « Pages » du bandeau.</p>
            <?php if ($homeMissing): ?>
                <div class="alert alert--warning"><svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg><div>La page d’accueil configurée n’existe plus : la liste est affichée en attendant.</div></div>
            <?php endif; ?>
            <form data-action="save-settings" data-track-dirty novalidate>
                <fieldset>
                    <legend>Arriver sur</legend>
                    <div class="field">
                        <label class="radio"><input type="radio" name="landing" value="home"<?= $landing === 'home' ? ' checked' : '' ?>> La page d’accueil choisie ci-dessous</label>
                    </div>
                    <div class="field">
                        <label class="radio"><input type="radio" name="landing" value="list"<?= $landing === 'list' ? ' checked' : '' ?>> La liste de toutes les pages (classées par catégories)</label>
                        <span class="field__error"></span>
                    </div>
                </fieldset>
                <div class="field">
                    <label class="field__label" for="wiki-home-slug">Page d’accueil</label>
                    <select class="select" id="wiki-home-slug" name="home_slug">
                        <option value="">— Aucune —</option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?= $e($page['slug']) ?>"<?= $homeSlug === $page['slug'] ? ' selected' : '' ?>><?= $e($page['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__help">Depuis une page, le bouton « Définir comme accueil » du bandeau fait la même chose.</span>
                    <span class="field__error"></span>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer</button>
                    <a class="btn btn--ghost" href="#" data-route="index">Voir le résultat</a>
                </div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-tag"></use></svg> Catégories</h2></div>
        <div class="card__body">
            <p class="mb-0 text-muted">Les catégories sont les tags partagés portés par les pages : ajoutez un tag à une page pour la classer, la barre de catégories de la liste se met à jour d’elle-même. Le module « Tags partagés » permet de renommer ou fusionner les catégories.</p>
        </div>
    </div>
</div>
