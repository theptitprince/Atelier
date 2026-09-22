<?php
/**
 * Lecture de la copie locale d'une actualité.
 * @var array<string, mixed> $item @var string $articleHtml @var bool $canArchive
 * @var \Atelier\Modules\News\NewsModule $module
 */
$id = (int) $item['id'];
?>
<div class="module module-news">
    <div class="news__archive-layout">
        <article class="card news__article">
            <div class="card__body">
                <p class="news__meta text-muted text-small">
                    <span><?= $e($item['feed_title']) ?></span>
                    <?php if ($item['category_name'] !== null): ?><span class="badge"<?= $module->colorStyle($item['category_color']) ?>><?= $e($item['category_name']) ?></span><?php endif; ?>
                    <?php if ($item['published_at'] !== null): ?><span>· <?= $e($datetime($item['published_at'])) ?></span><?php endif; ?>
                    <?php if ($item['author'] !== null): ?><span>· <?= $e($item['author']) ?></span><?php endif; ?>
                    <?php foreach ($item['interests'] as $interest): ?><span class="chip"<?= $module->colorStyle($interest['color']) ?>><?= $e($interest['name']) ?></span><?php endforeach; ?>
                </p>
                <?php if ($item['content_status'] === 'ok'): ?>
                    <?php if ($item['image_url'] !== null): ?><img class="news__image" src="<?= $e($item['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
                    <div class="prose news__body"><?= $articleHtml ?></div>
                    <p class="text-muted text-small mt-3 mb-0">Copie locale enregistrée le <?= $e($datetime($item['content_fetched_at'])) ?> : texte principal de la page source, sans mise en page ni médias. <?php if ($item['url'] !== null): ?><a href="<?= $e($item['url']) ?>" target="_blank" rel="noopener noreferrer">Consulter la source</a>.<?php endif; ?></p>
                <?php else: ?>
                    <?php if ($item['summary'] !== null): ?><p class="news__summary"><?= $e($item['summary']) ?></p><?php endif; ?>
                    <div class="alert alert--info" role="status"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg>
                        <div>
                            <p class="alert__title"><?= $item['content_status'] === 'error' ? 'Copie locale impossible' : 'Copie locale pas encore téléchargée' ?></p>
                            <p class="mb-0"><?= $item['content_status'] === 'error' ? $e($item['content_error'] ?? '') : 'Le téléchargement est effectué par la tâche de fond (cron) ; vous pouvez le lancer maintenant.' ?></p>
                            <?php if ($item['url'] !== null): ?><button type="button" class="btn btn--sm mt-2" data-action="content-fetch" data-params='{"id":<?= $id ?>}'><svg class="icon" aria-hidden="true"><use href="#i-download"></use></svg> Télécharger maintenant</button><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($canArchive): ?>
                <div class="card__footer"><button type="button" class="btn" data-action="archive" data-params='{"id":<?= $id ?>}' data-prompt="Note d’archivage (facultative)" data-prompt-field="note" data-confirm-title="Archiver ce fait"><svg class="icon" aria-hidden="true"><use href="#i-archive"></use></svg> Archiver ce fait</button></div>
            <?php endif; ?>
        </article>
        <aside>
            <section class="card card--compact">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg> Source</h2></div>
                <div class="card__body">
                    <dl class="dl news__detail">
                        <dt>Flux</dt><dd><?= $e($item['feed_title']) ?></dd>
                        <dt>Lien</dt><dd><?= $item['url'] !== null ? '<a href="' . $e($item['url']) . '" target="_blank" rel="noopener noreferrer">' . $e(mb_substr($item['url'], 0, 80, 'UTF-8')) . '</a>' : '<span class="text-muted">—</span>' ?></dd>
                        <dt>Récupérée</dt><dd><?= $e($datetime($item['fetched_at'])) ?></dd>
                        <dt>Copie locale</dt><dd><?= $item['content_status'] === 'ok' ? '<span class="badge badge--success">conservée</span>' : ($item['content_status'] === 'error' ? '<span class="badge badge--danger">échec</span>' : '<span class="badge badge--muted">en attente</span>') ?></dd>
                    </dl>
                </div>
            </section>
        </aside>
    </div>
</div>
