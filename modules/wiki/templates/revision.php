<?php
/**
 * Lecture d'une version antérieure.
 * @var array<string, mixed> $page @var array<string, mixed> $revision @var string $html @var bool $canUpdate
 */
$current = (int) $revision['revision'] === (int) $page['revision'];
?>
<div class="module module-wiki">
    <?php if (!$current): ?>
        <div class="alert alert--info" role="status"><svg class="icon" aria-hidden="true"><use href="#i-clock"></use></svg><div>Version <?= (int) $revision['revision'] ?> (la version courante est la <?= (int) $page['revision'] ?>).
            <?php if ($canUpdate): ?><button type="button" class="btn btn--sm mt-2" data-action="restore-revision" data-params='{"id":<?= (int) $page['id'] ?>,"revision":<?= (int) $revision['revision'] ?>}' data-confirm="Restaurer cette version ? Une nouvelle version sera créée."><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Restaurer cette version</button><?php endif; ?></div></div>
    <?php endif; ?>
    <article class="card wiki__article"><div class="card__body prose wiki__content"><?= $html !== '' ? $html : '<p class="text-muted mb-0">Version vide.</p>' ?></div></article>
</div>
