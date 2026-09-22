<?php
/**
 * Fil d'actualité : filtres (catégorie, centre d'intérêt, flux, non lues, recherche) et entrées.
 * @var list<array<string, mixed>> $rows @var int $total
 * @var array{q: string, category: int, interest: int, feed: int, unread: bool, page: int, per_page: int} $query
 * @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $interests @var list<array<string, mixed>> $feeds
 * @var array<int, int> $counts @var int $unread @var array<string, bool> $rights @var list<int> $perPageChoices
 * @var string $route @var list<array<string, mixed>> $errors @var bool $hasFeeds
 * @var \Atelier\Modules\News\NewsModule $module
 */
$link = static function (array $overrides) use ($query): string {
    $params = array_filter($overrides + ['q' => $query['q'], 'category' => $query['category'] ?: null, 'interest' => $query['interest'] ?: null, 'feed' => $query['feed'] ?: null, 'unread' => $query['unread'] ? 1 : null, 'per_page' => $query['per_page'] !== 20 ? $query['per_page'] : null], static fn ($v): bool => $v !== null && $v !== '' && $v !== 0);
    return $params === [] ? 'list' : 'list?' . http_build_query($params);
};
$pageQuery = array_filter(['q' => $query['q'], 'category' => $query['category'] ?: null, 'interest' => $query['interest'] ?: null, 'feed' => $query['feed'] ?: null, 'unread' => $query['unread'] ? 1 : null, 'per_page' => $query['per_page'] !== 20 ? $query['per_page'] : null], static fn ($v): bool => $v !== null && $v !== '');
$totalItems = array_sum($counts);
?>
<div class="module module-news">
    <?php foreach ($errors as $error): ?>
        <div class="alert alert--warning" role="status"><svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg><div>Flux « <?= $e($error['feed']['title']) ?> » : <?= $e($error['message']) ?></div></div>
    <?php endforeach; ?>
    <div class="news__layout">
        <aside class="news__side">
            <form class="card card--compact" data-action="filter" data-auto-submit novalidate>
                <div class="card__body">
                    <div class="field mb-0">
                        <label class="sr-only" for="news-q">Recherche</label>
                        <div class="input-icon">
                            <svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg>
                            <input class="input input--sm" type="search" id="news-q" name="q" value="<?= $e($query['q']) ?>" placeholder="Rechercher dans les titres et résumés" autocomplete="off">
                        </div>
                    </div>
                    <?php foreach (['category', 'interest', 'feed'] as $key): ?><?php if ($query[$key] > 0): ?><input type="hidden" name="<?= $key ?>" value="<?= (int) $query[$key] ?>"><?php endif; ?><?php endforeach; ?>
                    <?php if ($query['unread']): ?><input type="hidden" name="unread" value="1"><?php endif; ?>
                </div>
            </form>

            <nav class="card card--compact" aria-label="Filtres">
                <div class="card__body">
                    <h4>Lecture</h4>
                    <ul class="list news__filters">
                        <li class="list__item<?= !$query['unread'] ? ' is-selected' : '' ?>"><a class="grow" href="#" data-route="<?= $e($link(['unread' => null, 'page' => null])) ?>">Toutes les entrées</a><span class="badge badge--muted"><?= (int) $totalItems ?></span></li>
                        <li class="list__item<?= $query['unread'] ? ' is-selected' : '' ?>"><a class="grow" href="#" data-route="<?= $e($link(['unread' => 1, 'page' => null])) ?>">Non lues</a><span class="badge<?= $unread > 0 ? ' badge--info' : ' badge--muted' ?>"><?= (int) $unread ?></span></li>
                    </ul>
                    <h4 class="mt-3">Catégories</h4>
                    <ul class="list news__filters">
                        <li class="list__item<?= $query['category'] === 0 ? ' is-selected' : '' ?>"><a class="grow" href="#" data-route="<?= $e($link(['category' => null, 'page' => null])) ?>">Toutes</a></li>
                        <?php foreach ($categories as $category): ?>
                            <li class="list__item<?= $query['category'] === (int) $category['id'] ? ' is-selected' : '' ?>"<?= $module->colorStyle($category['color']) ?>><span class="news__dot" aria-hidden="true"></span><a class="grow" href="#" data-route="<?= $e($link(['category' => (int) $category['id'], 'page' => null])) ?>"><?= $e($category['name']) ?></a><span class="badge badge--muted"><?= (int) ($counts[(int) $category['id']] ?? 0) ?></span></li>
                        <?php endforeach; ?>
                        <?php if (($counts[0] ?? 0) > 0): ?><li class="list__item"><span class="grow text-muted">Sans catégorie</span><span class="badge badge--muted"><?= (int) $counts[0] ?></span></li><?php endif; ?>
                    </ul>
                    <?php if ($interests !== []): ?>
                        <h4 class="mt-3">Centres d’intérêt</h4>
                        <ul class="list news__filters">
                            <li class="list__item<?= $query['interest'] === 0 ? ' is-selected' : '' ?>"><a class="grow" href="#" data-route="<?= $e($link(['interest' => null, 'page' => null])) ?>">Tous</a></li>
                            <?php foreach ($interests as $interest): ?>
                                <li class="list__item<?= $query['interest'] === (int) $interest['id'] ? ' is-selected' : '' ?>"<?= $module->colorStyle($interest['color']) ?>><span class="news__dot" aria-hidden="true"></span><a class="grow" href="#" data-route="<?= $e($link(['interest' => (int) $interest['id'], 'page' => null])) ?>" title="<?= $e($interest['keywords']) ?>"><?= $e($interest['name']) ?></a><span class="badge badge--muted"><?= (int) $interest['item_count'] ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <h4 class="mt-3">Flux</h4>
                    <ul class="list news__filters">
                        <li class="list__item<?= $query['feed'] === 0 ? ' is-selected' : '' ?>"><a class="grow" href="#" data-route="<?= $e($link(['feed' => null, 'page' => null])) ?>">Tous</a></li>
                        <?php foreach ($feeds as $feed): ?>
                            <li class="list__item<?= $query['feed'] === (int) $feed['id'] ? ' is-selected' : '' ?>"><a class="grow truncate" href="#" data-route="<?= $e($link(['feed' => (int) $feed['id'], 'page' => null])) ?>" title="<?= $e($feed['url']) ?>"><?= $e($feed['title']) ?></a><?= $feed['last_status'] === 'error' ? '<span class="dot dot--error" title="' . $e($feed['last_error'] ?? 'En erreur') . '"></span>' : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </nav>
        </aside>

        <div class="news__main">
            <?php if (!$hasFeeds): ?>
                <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun flux suivi', 'message' => 'Ajoutez des flux RSS, Atom ou JSON Feed pour alimenter le fil d’actualité.', 'actions' => $rights['update'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="feeds/new">Ajouter un flux</a>' : '']) ?>
            <?php elseif ($rows === []): ?>
                <?= $module->renderCore('state', ['type' => 'empty', 'title' => $query['unread'] ? 'Tout est lu' : 'Aucune entrée', 'message' => $query['unread'] ? 'Aucune actualité non lue pour ces filtres.' : 'Aucune actualité ne correspond aux filtres, ou les flux n’ont pas encore été récupérés.', 'actions' => '<button type="button" class="btn btn--sm" data-action="refresh">Actualiser les flux</button>']) ?>
            <?php else: ?>
                <div class="toolbar">
                    <span class="text-muted text-small"><?= (int) $total ?> entrée(s)</span>
                    <span class="toolbar__spacer"></span>
                    <label class="field field--inline"><span class="field__label">Par page</span>
                        <select class="select select--sm" data-route-select aria-label="Entrées par page">
                            <?php foreach ($perPageChoices as $option): ?><option value="<?= $e($link(['per_page' => $option, 'page' => null])) ?>"<?= $query['per_page'] === $option ? ' selected' : '' ?>><?= (int) $option ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="news__items">
                    <?php foreach ($rows as $row): ?>
                        <article class="card news__item<?= (int) $row['is_read'] === 1 ? ' is-read' : ' is-unread' ?>" data-news-item="<?= (int) $row['id'] ?>">
                            <div class="card__body">
                                <div class="news__item-head">
                                    <h3 class="news__title">
                                        <?php if ($row['url'] !== null): ?>
                                            <a href="<?= $e($row['url']) ?>" target="_blank" rel="noopener noreferrer" data-news-open="<?= (int) $row['id'] ?>"><?= $e($row['title']) ?></a>
                                        <?php else: ?>
                                            <?= $e($row['title']) ?>
                                        <?php endif; ?>
                                    </h3>
                                    <span class="news__item-actions">
                                        <a class="btn btn--sm btn--ghost" href="#" data-route="read/<?= (int) $row['id'] ?>" title="<?= $row['content_status'] === 'ok' ? 'Lire la copie locale' : 'Ouvrir (copie locale ' . ($row['content_status'] === 'error' ? 'en échec' : 'en attente') . ')' ?>"><svg class="icon" aria-hidden="true"><use href="#i-<?= $row['content_status'] === 'ok' ? 'book' : 'eye' ?>"></use></svg> Lire</a>
                                        <button type="button" class="btn btn--sm btn--icon btn--ghost" data-news-toggle="<?= (int) $row['id'] ?>" title="<?= (int) $row['is_read'] === 1 ? 'Marquer non lue' : 'Marquer lue' ?>" aria-label="<?= (int) $row['is_read'] === 1 ? 'Marquer non lue' : 'Marquer lue' ?>"><svg class="icon" aria-hidden="true"><use href="#i-<?= (int) $row['is_read'] === 1 ? 'eye-off' : 'check' ?>"></use></svg></button>
                                        <?php if ($rights['archive']): ?>
                                            <button type="button" class="btn btn--sm btn--ghost" data-action="archive" data-params='{"id":<?= (int) $row['id'] ?>}' data-prompt="Note d’archivage (facultative, modifiable ensuite avec mise en forme et tags)" data-prompt-field="note" data-confirm-title="Archiver ce fait" title="Conserver ce fait dans les archives"><svg class="icon" aria-hidden="true"><use href="#i-archive"></use></svg> Archiver</button>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <p class="news__meta text-muted text-small">
                                    <span class="news__feed"><?= $e($row['feed_title']) ?></span>
                                    <?php if ($row['category_name'] !== null): ?><span class="badge"<?= $module->colorStyle($row['category_color']) ?>><?= $e($row['category_name']) ?></span><?php endif; ?>
                                    <?php if ($row['published_at'] !== null): ?><span>· <?= $e($datetime($row['published_at'])) ?></span><?php endif; ?>
                                    <?php if ($row['author'] !== null): ?><span>· <?= $e($row['author']) ?></span><?php endif; ?>
                                    <?php foreach ($row['interests'] as $interest): ?><span class="chip"<?= $module->colorStyle($interest['color']) ?>><?= $e($interest['name']) ?></span><?php endforeach; ?>
                                </p>
                                <?php if ($row['summary'] !== null): ?><p class="news__summary mb-0"><?= $e($row['summary']) ?></p><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => 'list', 'query' => $pageQuery]) ?>
            <?php endif; ?>
        </div>
    </div>
</div>
