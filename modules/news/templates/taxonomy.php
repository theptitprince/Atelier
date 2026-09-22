<?php
/**
 * Catégories de flux et centres d'intérêt.
 * @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $interests @var array<string, bool> $rights
 * @var \Atelier\Modules\News\NewsModule $module
 */
?>
<div class="module module-news">
    <div class="news__form-layout">
        <section class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg> Catégories</h2><span class="badge badge--muted"><?= count($categories) ?></span></div>
            <div class="card__body">
                <p class="text-muted text-small">Une catégorie regroupe des flux (un flux appartient à une catégorie au plus).</p>
                <?php foreach ($categories as $category): ?>
                    <form class="news__inline-form" data-action="category-save" novalidate>
                        <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                        <input class="input input--sm news__color" type="color" name="color" value="<?= $e($category['color'] ?? '#2c3e50') ?>" title="Couleur" aria-label="Couleur">
                        <input class="input input--sm grow" name="name" value="<?= $e($category['name']) ?>" maxlength="100" required aria-label="Nom de la catégorie">
                        <input class="input input--sm news__position" name="position" type="number" value="<?= (int) $category['position'] ?>" title="Ordre" aria-label="Ordre">
                        <span class="badge badge--muted" title="Flux dans cette catégorie"><?= (int) $category['feed_count'] ?></span>
                        <button type="submit" class="btn btn--sm btn--icon" title="Enregistrer"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg></button>
                        <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="category-delete" data-params='{"id":<?= (int) $category['id'] ?>}' data-confirm="Supprimer la catégorie « <?= $e($category['name']) ?> » ? Ses flux resteront sans catégorie." data-danger title="Supprimer"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button><?php endif; ?>
                    </form>
                <?php endforeach; ?>
                <form class="news__inline-form news__inline-form--new" data-action="category-save" novalidate>
                    <input class="input input--sm news__color" type="color" name="color" value="#2f6fdb" title="Couleur" aria-label="Couleur">
                    <input class="input input--sm grow" name="name" placeholder="Nouvelle catégorie" maxlength="100" required aria-label="Nom de la nouvelle catégorie">
                    <input class="input input--sm news__position" name="position" type="number" value="100" title="Ordre" aria-label="Ordre">
                    <button type="submit" class="btn btn--sm btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg> Ajouter</button>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-tag"></use></svg> Centres d’intérêt</h2><span class="badge badge--muted"><?= count($interests) ?></span></div>
            <div class="card__body">
                <p class="text-muted text-small">Un centre d’intérêt repère les entrées dont le titre ou le résumé contient l’un des mots-clés (virgules entre les mots-clés, accents et casse ignorés, mots entiers ; <code>-mot</code> exclut). Les correspondances sont recalculées à l’enregistrement.</p>
                <?php foreach ($interests as $interest): ?>
                    <form class="news__interest-form" data-action="interest-save" novalidate>
                        <input type="hidden" name="id" value="<?= (int) $interest['id'] ?>">
                        <div class="news__inline-form">
                            <input class="input input--sm news__color" type="color" name="color" value="<?= $e($interest['color'] ?? '#b7791f') ?>" title="Couleur" aria-label="Couleur">
                            <input class="input input--sm grow" name="name" value="<?= $e($interest['name']) ?>" maxlength="100" required aria-label="Nom du centre d’intérêt">
                            <input class="input input--sm news__position" name="position" type="number" value="<?= (int) $interest['position'] ?>" title="Ordre" aria-label="Ordre">
                            <span class="badge badge--muted" title="Entrées correspondantes"><?= (int) $interest['item_count'] ?></span>
                            <button type="submit" class="btn btn--sm btn--icon" title="Enregistrer"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg></button>
                            <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="interest-delete" data-params='{"id":<?= (int) $interest['id'] ?>}' data-confirm="Supprimer « <?= $e($interest['name']) ?> » ?" data-danger title="Supprimer"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button><?php endif; ?>
                        </div>
                        <div class="field mb-3"><textarea class="textarea news__keywords" name="keywords" rows="2" maxlength="2000" aria-label="Mots-clés" placeholder="mot-clé 1, mot-clé 2, -exclusion"><?= $e($interest['keywords']) ?></textarea><span class="field__error"></span></div>
                    </form>
                <?php endforeach; ?>
                <form class="news__interest-form news__inline-form--new" data-action="interest-save" novalidate>
                    <div class="news__inline-form">
                        <input class="input input--sm news__color" type="color" name="color" value="#b7791f" title="Couleur" aria-label="Couleur">
                        <input class="input input--sm grow" name="name" placeholder="Nouveau centre d’intérêt" maxlength="100" required aria-label="Nom du nouveau centre d’intérêt">
                        <input class="input input--sm news__position" name="position" type="number" value="100" title="Ordre" aria-label="Ordre">
                        <button type="submit" class="btn btn--sm btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg> Ajouter</button>
                    </div>
                    <div class="field mb-0"><textarea class="textarea news__keywords" name="keywords" rows="2" maxlength="2000" aria-label="Mots-clés" placeholder="mot-clé 1, mot-clé 2, -exclusion"></textarea><span class="field__error"></span></div>
                </form>
            </div>
        </section>
    </div>
</div>
