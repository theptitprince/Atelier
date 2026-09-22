<?php
/**
 * Fiche d'un fait archivé : source, note (BBCode), tags, relations et pièces jointes.
 * @var array<string, mixed> $item @var string $noteHtml @var string $articleHtml @var list<string> $tags
 * @var list<array<string, mixed>> $relations @var list<array<string, mixed>> $attachments
 * @var array<string, mixed>|null $archiver @var bool $canArchive @var bool $attachmentsModule @var int $noteMax @var string|null $infoId
 * @var string $baseUrl @var \Atelier\Modules\News\NewsModule $module
 */
$id = (int) $item['id'];
?>
<div class="module module-news">
    <div class="news__archive-layout">
        <div>
            <section class="card">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-rss"></use></svg> Fait archivé</h2><?php if ($item['category_name'] !== null): ?><span class="badge"<?= $module->colorStyle($item['category_color']) ?>><?= $e($item['category_name']) ?></span><?php endif; ?></div>
                <div class="card__body">
                    <dl class="dl news__detail">
                        <dt>Titre</dt><dd><?= $e($item['title']) ?></dd>
                        <dt>Source</dt><dd><?= $e($item['feed_title']) ?><?= $item['url'] !== null ? ' · <a href="' . $e($item['url']) . '" target="_blank" rel="noopener noreferrer">' . $e(mb_substr($item['url'], 0, 90, 'UTF-8')) . ' <svg class="icon icon--sm" aria-hidden="true"><use href="#i-external"></use></svg></a>' : '' ?></dd>
                        <dt>Publié</dt><dd><?= $item['published_at'] !== null ? $e($datetime($item['published_at'])) : '<span class="text-muted">—</span>' ?><?= $item['author'] !== null ? ' · ' . $e($item['author']) : '' ?></dd>
                        <dt>Archivé</dt><dd><?= $e($datetime($item['archived_at'])) ?><?= $archiver !== null ? ' par ' . $e($archiver['display_name']) : '' ?></dd>
                        <?php if ($item['interests'] !== []): ?><dt>Intérêts</dt><dd class="chips"><?php foreach ($item['interests'] as $interest): ?><span class="chip"<?= $module->colorStyle($interest['color']) ?>><?= $e($interest['name']) ?></span><?php endforeach; ?></dd><?php endif; ?>
                    </dl>
                    <?php if ($item['summary'] !== null): ?><p class="news__summary mt-3 mb-0"><?= $e($item['summary']) ?></p><?php endif; ?>
                </div>
            </section>

            <section class="card">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-book"></use></svg> Copie locale de l’article</h2><?= $item['content_status'] === 'ok' ? '<span class="badge badge--success">conservée</span>' : '<span class="badge badge--warning">absente</span>' ?></div>
                <div class="card__body">
                    <?php if ($item['content_status'] === 'ok'): ?>
                        <div class="prose news__body"><?= $articleHtml ?></div>
                        <p class="text-muted text-small mt-3 mb-0">Texte principal enregistré le <?= $e($datetime($item['content_fetched_at'])) ?> ; reste consultable même si la source disparaît.</p>
                    <?php else: ?>
                        <p class="text-muted"><?= $item['content_status'] === 'error' ? 'Téléchargement impossible : ' . $e($item['content_error'] ?? '') : 'Pas encore téléchargée.' ?></p>
                        <?php if ($item['url'] !== null): ?><button type="button" class="btn btn--sm" data-action="content-fetch" data-params='{"id":<?= $id ?>}'><svg class="icon" aria-hidden="true"><use href="#i-download"></use></svg> Télécharger maintenant</button><?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-note"></use></svg> Note et tags</h2></div>
                <?php if ($canArchive): ?>
                    <form class="card__body" data-action="archive-save" data-track-dirty data-save-shortcut novalidate>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="field">
                            <label class="field__label" for="news-note">Note</label>
                            <textarea class="textarea" id="news-note" name="note" rows="10" maxlength="<?= (int) $noteMax ?>" data-editor="bbcode" placeholder="Pourquoi ce fait est conservé, contexte, suites… (BBCode)"><?= $e($item['archive_note'] ?? '') ?></textarea>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="news-tags"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg> Tags partagés</label>
                            <input class="input" type="text" id="news-tags" name="tags" value="<?= $e(implode(', ', $tags)) ?>" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20">
                            <span class="field__help">Tags communs à toute l’application ; les tags existants sont proposés pendant la saisie.</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg> Enregistrer</button>
                            <span class="text-muted text-small">Ctrl+S</span>
                            <span class="toolbar__spacer grow"></span>
                            <button type="button" class="btn btn--outline-danger" data-action="unarchive" data-params='{"id":<?= $id ?>}' data-confirm="Retirer ce fait des archives ? Sa note, ses tags et ses relations seront supprimés ; l’entrée suivra ensuite la rétention de son flux." data-danger><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Désarchiver</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="card__body">
                        <div class="prose"><?= $noteHtml !== '' ? $noteHtml : '<p class="text-muted mb-0">Aucune note.</p>' ?></div>
                        <?php if ($tags !== []): ?><div class="chips mt-3"><?php foreach ($tags as $tag): ?><span class="chip"><?= $e($tag) ?></span><?php endforeach; ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div>
            <section class="card">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-link"></use></svg> Relations</h2><span class="badge badge--muted"><?= count($relations) ?></span></div>
                <div class="card__body">
                    <?php if ($relations === []): ?>
                        <p class="text-muted mb-0">Aucune relation. Depuis un point GPS (« Rattacher une information ») ou une page, ce fait peut être relié comme toute information du registre commun.</p>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($relations as $relation): ?>
                                <li class="list__item"><svg class="icon text-muted" aria-hidden="true"><use href="#i-puzzle"></use></svg><span class="grow"><strong><?= $e($relation['other_label'] ?? $relation['other_key']) ?></strong> <span class="text-muted text-small">· <?= $e($relation['type_label']) ?> · <?= $e($relation['other_module']) ?></span></span><a class="btn btn--sm btn--ghost" href="#" data-open-module="<?= $e($relation['other_module']) ?>"<?= $relation['other_dataset'] === 'geo.point' ? ' data-open-route="show/' . $e($relation['other_key']) . '"' : '' ?>><svg class="icon" aria-hidden="true"><use href="#i-external"></use></svg></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
            <section class="card">
                <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-paperclip"></use></svg> Pièces jointes</h2><span class="badge badge--muted"><?= count($attachments) ?></span></div>
                <div class="card__body">
                    <?php if ($attachments === []): ?><p class="text-muted<?= $attachmentsModule && $canArchive ? '' : ' mb-0' ?>">Aucune pièce jointe.</p><?php else: ?>
                        <ul class="list<?= $attachmentsModule && $canArchive ? ' mb-3' : '' ?>"><?php foreach ($attachments as $file): ?><li class="list__item"><svg class="icon text-muted" aria-hidden="true"><use href="#i-file"></use></svg><a class="grow truncate" href="<?= $e($baseUrl . '/files/' . $file['id']) ?>" download><?= $e($file['original_name']) ?></a><span class="text-muted text-small text-nowrap"><?= $e(\Atelier\Support\Str::humanSize((int) $file['size'])) ?></span></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if ($attachmentsModule && $canArchive && $infoId !== null): ?>
                        <a class="btn btn--sm" href="#" data-open-module="attachments" data-open-route="upload?info=<?= $e($infoId) ?>"><svg class="icon" aria-hidden="true"><use href="#i-upload"></use></svg> Joindre un fichier</a>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
