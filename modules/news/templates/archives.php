<?php
/**
 * Faits archivés.
 * @var list<array<string, mixed>> $rows @var int $total
 * @var array{q: string, category: int, interest: int, feed: int, unread: bool, page: int, per_page: int} $query
 * @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $interests
 * @var array<string, bool> $rights @var list<int> $perPageChoices
 * @var \Atelier\Modules\News\NewsModule $module
 */
$pageQuery = array_filter(['q' => $query['q'], 'category' => $query['category'] ?: null, 'interest' => $query['interest'] ?: null, 'per_page' => $query['per_page'] !== 20 ? $query['per_page'] : null], static fn ($v): bool => $v !== null && $v !== '');
?>
<div class="module module-news">
    <form class="card card--compact" data-action="filter" data-auto-submit novalidate>
        <input type="hidden" name="view" value="archives">
        <div class="card__body toolbar mb-0">
            <div class="field grow mb-0">
                <label class="sr-only" for="news-aq">Recherche</label>
                <div class="input-icon"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg><input class="input input--sm" type="search" id="news-aq" name="q" value="<?= $e($query['q']) ?>" placeholder="Titre, résumé ou note" autocomplete="off"></div>
            </div>
            <label class="field field--inline"><span class="field__label">Catégorie</span>
                <select class="select select--sm" name="category"><option value="">Toutes</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $query['category'] === (int) $c['id'] ? ' selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label class="field field--inline"><span class="field__label">Intérêt</span>
                <select class="select select--sm" name="interest"><option value="">Tous</option><?php foreach ($interests as $i): ?><option value="<?= (int) $i['id'] ?>"<?= $query['interest'] === (int) $i['id'] ? ' selected' : '' ?>><?= $e($i['name']) ?></option><?php endforeach; ?></select>
            </label>
            <a class="btn btn--sm btn--ghost" href="#" data-route="archives">Réinitialiser</a>
        </div>
    </form>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun fait archivé', 'message' => 'Depuis le fil d’actualité, « Archiver » conserve un fait hors rétention, avec une note, des tags et des liens vers les autres modules.']) ?>
    <?php else: ?>
        <div class="news__items">
            <?php foreach ($rows as $row): ?>
                <article class="card news__item is-read">
                    <div class="card__body">
                        <div class="news__item-head">
                            <h3 class="news__title"><a href="#" data-route="archive/<?= (int) $row['id'] ?>"><?= $e($row['title']) ?></a></h3>
                            <span class="news__item-actions">
                                <?php if ($row['url'] !== null): ?><a class="btn btn--sm btn--icon btn--ghost" href="<?= $e($row['url']) ?>" target="_blank" rel="noopener noreferrer" title="Ouvrir la source"><svg class="icon" aria-hidden="true"><use href="#i-external"></use></svg></a><?php endif; ?>
                                <a class="btn btn--sm btn--ghost" href="#" data-route="archive/<?= (int) $row['id'] ?>"><svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg> Ouvrir</a>
                            </span>
                        </div>
                        <p class="news__meta text-muted text-small">
                            <span><?= $e($row['feed_title']) ?></span>
                            <?php if ($row['category_name'] !== null): ?><span class="badge"<?= $module->colorStyle($row['category_color']) ?>><?= $e($row['category_name']) ?></span><?php endif; ?>
                            <?php if ($row['published_at'] !== null): ?><span>· publié le <?= $e($datetime($row['published_at'])) ?></span><?php endif; ?>
                            <span>· archivé le <?= $e($datetime($row['archived_at'])) ?><?= $row['archiver'] !== null ? ' par ' . $e($row['archiver']['display_name']) : '' ?></span>
                            <?php foreach ($row['tags'] as $tag): ?><span class="chip"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg> <?= $e($tag['name']) ?></span><?php endforeach; ?>
                        </p>
                        <?php if ($row['note_excerpt'] !== ''): ?><p class="news__note mb-0"><svg class="icon icon--sm text-muted" aria-hidden="true"><use href="#i-note"></use></svg> <?= $e($row['note_excerpt']) ?></p><?php elseif ($row['summary'] !== null): ?><p class="news__summary mb-0"><?= $e($row['summary']) ?></p><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => 'archives', 'query' => $pageQuery]) ?>
    <?php endif; ?>
</div>
