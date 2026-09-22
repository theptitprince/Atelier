<?php
/**
 * Lecture d'une page : contenu rendu, tags, lieux, pièces jointes, rétroliens, pages à créer, relations.
 * @var array<string, mixed> $page @var string $html @var list<string> $tags @var list<array<string, mixed>> $points
 * @var list<array<string, mixed>> $backlinks @var list<string> $missing @var list<array<string, mixed>> $attachments
 * @var list<array<string, mixed>> $relations @var array<string, bool> $rights @var string|null $infoId
 * @var bool $attachmentsModule @var bool $mapModule @var string $baseUrl
 * @var \Atelier\Modules\Wiki\WikiModule $module
 */
$id = (int) $page['id'];
?>
<div class="module module-wiki">
    <div class="wiki__layout">
        <article class="card wiki__article">
            <div class="card__body prose wiki__content">
                <?= $html !== '' ? $html : '<p class="text-muted">Page vide.' . ($rights['update'] ? ' <a href="#" data-route="edit/' . $id . '">Rédiger</a>' : '') . '</p>' ?>
            </div>
            <div class="card__footer text-small text-muted">
                Créée le <?= $e($datetime($page['created_at'])) ?><?= $page['created_by_name'] !== null ? ' par ' . $e($page['created_by_name']) : '' ?> · version <?= (int) $page['revision'] ?> du <?= $e($datetime($page['updated_at'])) ?> · identifiant <code><?= $e($page['slug']) ?></code>
            </div>
        </article>

        <aside class="wiki__side">
            <section class="card card--compact">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-tag"></use></svg> Tags</h2></div>
                <div class="card__body"><?php if ($tags === []): ?><p class="text-muted mb-0">Aucun tag.</p><?php else: ?><div class="chips"><?php foreach ($tags as $tag): ?><a class="chip" href="#" data-route="list?tag=<?= $e(rawurlencode(\Atelier\Support\Str::normalizeTag($tag))) ?>" title="Toutes les pages de cette catégorie"><?= $e($tag) ?></a><?php endforeach; ?></div><?php endif; ?></div>
            </section>

            <section class="card card--compact">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-map-pin"></use></svg> Lieux</h2><span class="badge badge--muted"><?= count($points) ?></span></div>
                <div class="card__body">
                    <?php if ($points === []): ?><p class="text-muted mb-0">Aucun lieu rattaché.</p><?php else: ?>
                        <ul class="list">
                            <?php foreach ($points as $point): ?>
                                <li class="list__item">
                                    <span class="grow"><a href="#" data-open-module="geo" data-open-route="show/<?= (int) $point['id'] ?>"><?= $e($point['label']) ?></a><br><span class="mono text-muted text-small"><?= $e($point['dms']) ?></span></span>
                                    <?php if ($mapModule): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-open-module="map" data-open-route="index?point=<?= (int) $point['id'] ?>" title="Voir sur la carte"><svg class="icon" aria-hidden="true"><use href="#i-map"></use></svg></a><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card card--compact">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-paperclip"></use></svg> Pièces jointes</h2><span class="badge badge--muted"><?= count($attachments) ?></span></div>
                <div class="card__body">
                    <?php if ($attachments === []): ?><p class="text-muted<?= $attachmentsModule && $rights['update'] && $infoId !== null ? '' : ' mb-0' ?>">Aucune pièce jointe.</p><?php else: ?>
                        <ul class="list<?= $attachmentsModule && $rights['update'] ? ' mb-3' : '' ?>"><?php foreach ($attachments as $file): ?><li class="list__item"><svg class="icon text-muted" aria-hidden="true"><use href="#i-<?= str_starts_with((string) $file['mime'], 'image/') ? 'image' : 'file' ?>"></use></svg><a class="grow truncate" href="<?= $e($baseUrl . '/files/' . $file['id']) ?>" download title="<?= $e($file['original_name']) ?>"><?= $e($file['original_name']) ?></a><span class="text-muted text-small text-nowrap"><?= $e(\Atelier\Support\Str::humanSize((int) $file['size'])) ?></span></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if ($attachmentsModule && $rights['update'] && $infoId !== null): ?><a class="btn btn--sm" href="#" data-open-module="attachments" data-open-route="upload?info=<?= $e($infoId) ?>"><svg class="icon" aria-hidden="true"><use href="#i-upload"></use></svg> Joindre un fichier</a><?php endif; ?>
                </div>
            </section>

            <section class="card card--compact">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-link"></use></svg> Pages liées</h2></div>
                <div class="card__body">
                    <h4>Pointent vers cette page</h4>
                    <?php if ($backlinks === []): ?><p class="text-muted">Aucune.</p><?php else: ?><ul class="list mb-3"><?php foreach ($backlinks as $link): ?><li class="list__item"><a href="#" data-route="show/<?= $e($link['slug']) ?>"><?= $e($link['title']) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
                    <?php if ($missing !== []): ?>
                        <h4>Pages à créer</h4>
                        <ul class="list mb-0"><?php foreach ($missing as $slug): ?><li class="list__item"><a class="wiki__link wiki__link--missing" href="#" data-route="new?title=<?= $e(rawurlencode(str_replace('-', ' ', $slug))) ?>"><?= $e($slug) ?></a></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if ($relations !== []): ?>
                        <h4 class="mt-3">Autres relations</h4>
                        <ul class="list mb-0"><?php foreach ($relations as $relation): ?><li class="list__item"><span class="grow"><strong><?= $e($relation['other_label'] ?? $relation['other_key']) ?></strong> <span class="text-muted text-small">· <?= $e($relation['type_label']) ?> · <?= $e($relation['other_module']) ?></span></span><a class="btn btn--sm btn--icon btn--ghost" href="#" data-open-module="<?= $e($relation['other_module']) ?>"><svg class="icon" aria-hidden="true"><use href="#i-external"></use></svg></a></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>
