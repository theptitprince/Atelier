<?php
/**
 * Fiche d'un point GPS : coordonnées, tags, informations rattachées, pièces jointes, points proches.
 * @var array<string, mixed> $point (avec coords, decimal, dms)
 * @var string|null $infoId
 * @var list<array<string, mixed>> $tags
 * @var list<array<string, mixed>> $linked
 * @var list<array<string, mixed>> $attachments
 * @var list<array<string, mixed>> $nearby
 * @var float $radiusKm
 * @var array<string, bool> $rights
 * @var array<string, mixed>|null $author
 * @var bool $attachmentsModule
 * @var string $baseUrl
 * @var \Atelier\Modules\Geo\GeoModule $module
 */
/** @var \Atelier\Modules\Geo\Coordinates $coords */
$coords = $point['coords'];
$id = (int) $point['id'];
$dash = '<span class="text-muted">—</span>';
?>
<div class="module module-geo">
    <div class="geo__show-layout">
        <div class="geo__show-main">
            <section class="card" aria-labelledby="geo-coords-title">
                <div class="card__header">
                    <h2 class="card__title" id="geo-coords-title"><svg class="icon" aria-hidden="true"><use href="#i-map-pin"></use></svg> Coordonnées</h2>
                    <?php if ($point['code'] !== null && $point['code'] !== ''): ?><span class="badge"><?= $e($point['code']) ?></span><?php endif; ?>
                </div>
                <div class="card__body">
                    <dl class="dl geo__detail">
                        <dt>Décimal</dt>
                        <dd class="flex"><span class="mono" data-geo-value><?= $e($point['decimal']) ?></span> <button type="button" class="btn btn--sm btn--icon btn--ghost" data-geo-copy="<?= $e($point['decimal']) ?>" title="Copier"><svg class="icon" aria-hidden="true"><use href="#i-copy"></use></svg></button></dd>
                        <dt>DMS</dt>
                        <dd class="flex"><span class="mono"><?= $e($point['dms']) ?></span> <button type="button" class="btn btn--sm btn--icon btn--ghost" data-geo-copy="<?= $e($point['dms']) ?>" title="Copier"><svg class="icon" aria-hidden="true"><use href="#i-copy"></use></svg></button></dd>
                        <dt>URI geo</dt>
                        <dd><code><?= $e($coords->geoUri()) ?></code></dd>
                        <dt>Altitude</dt>
                        <dd><?= $point['altitude'] !== null ? $e(number_format((float) $point['altitude'], 0, ',', ' ')) . ' m' : $dash ?></dd>
                        <dt>Adresse</dt>
                        <dd><?= $point['address'] !== null && $point['address'] !== '' ? $e($point['address']) : $dash ?></dd>
                        <dt>Description</dt>
                        <dd class="geo__description"><?= $point['description'] !== null && $point['description'] !== '' ? nl2br($e($point['description'])) : $dash ?></dd>
                        <dt>Créé</dt>
                        <dd><?= $e($datetime($point['created_at'])) ?><?= $author !== null ? ' par ' . $e($author['display_name']) : '' ?></dd>
                        <dt>Modifié</dt>
                        <dd><?= $e($datetime($point['updated_at'])) ?></dd>
                    </dl>
                </div>
                <div class="card__footer">
                    <div class="toolbar mb-0">
                        <a class="btn btn--sm" href="<?= $e($coords->openStreetMapUrl()) ?>" target="_blank" rel="noopener noreferrer"><svg class="icon" aria-hidden="true"><use href="#i-globe"></use></svg> OpenStreetMap <svg class="icon icon--sm" aria-hidden="true"><use href="#i-external"></use></svg></a>
                        <a class="btn btn--sm" href="<?= $e($coords->googleMapsUrl()) ?>" target="_blank" rel="noopener noreferrer"><svg class="icon" aria-hidden="true"><use href="#i-globe"></use></svg> Google Maps <svg class="icon icon--sm" aria-hidden="true"><use href="#i-external"></use></svg></a>
                        <span class="toolbar__spacer"></span>
                        <a class="btn btn--sm btn--ghost" href="#" data-route="list?q=<?= $e(rawurlencode($point['decimal'])) ?>"><svg class="icon" aria-hidden="true"><use href="#i-navigation"></use></svg> Points à proximité</a>
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="geo-linked-title">
                <div class="card__header">
                    <h2 class="card__title" id="geo-linked-title"><svg class="icon" aria-hidden="true"><use href="#i-link"></use></svg> Informations rattachées</h2>
                    <span class="badge badge--muted"><?= count($linked) ?></span>
                </div>
                <div class="card__body">
                    <?php if ($linked === []): ?>
                        <p class="text-muted mb-0">Aucune information d’un autre module n’est rattachée à ce point. Les modules peuvent rattacher leurs données par le service <code>geo</code> (relation « localisé à »).</p>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($linked as $item): ?>
                                <li class="list__item">
                                    <svg class="icon text-muted" aria-hidden="true"><use href="#i-puzzle"></use></svg>
                                    <span class="grow">
                                        <strong><?= $e($item['label'] !== '' ? $item['label'] : $item['dataset'] . ' #' . $item['key']) ?></strong>
                                        <span class="text-muted text-small">· <?= $e($item['module']) ?> · <?= $e($item['dataset']) ?><?= $item['comment'] !== null ? ' · ' . $e($item['comment']) : '' ?></span>
                                    </span>
                                    <a class="btn btn--sm btn--ghost" href="#" data-open-module="<?= $e($item['module']) ?>" title="Ouvrir le module <?= $e($item['module']) ?>"><svg class="icon" aria-hidden="true"><use href="#i-external"></use></svg></a>
                                    <?php if ($rights['update']): ?>
                                        <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="unlink" data-params='{"id":<?= $id ?>,"relation_id":<?= (int) $item['relation_id'] ?>}' data-confirm="Retirer ce rattachement ?" title="Retirer le rattachement"><svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg></button>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($rights['update']): ?>
                        <form class="geo__link-form mt-3" data-action="link" novalidate data-geo-link>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="info_id" value="" data-geo-link-id>
                            <div class="field">
                                <label class="field__label" for="geo-link-search">Rattacher une information</label>
                                <div class="input-icon">
                                    <svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg>
                                    <input class="input" type="search" id="geo-link-search" placeholder="Rechercher dans les jeux de données partagés (2 caractères minimum)" autocomplete="off" data-geo-link-search>
                                </div>
                                <span class="field__help">Seules les informations des jeux partagés que vous pouvez consulter sont proposées.</span>
                                <span class="field__error"></span>
                            </div>
                            <ul class="list geo__link-results" data-geo-link-results hidden></ul>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="geo__show-side">
            <section class="card" aria-labelledby="geo-tags-title">
                <div class="card__header"><h2 class="card__title" id="geo-tags-title"><svg class="icon" aria-hidden="true"><use href="#i-tag"></use></svg> Tags</h2></div>
                <div class="card__body">
                    <?php if ($tags === []): ?>
                        <p class="text-muted<?= $rights['update'] ? '' : ' mb-0' ?>">Aucun tag.</p>
                    <?php else: ?>
                        <div class="chips<?= $rights['update'] ? ' mb-3' : '' ?>">
                            <?php foreach ($tags as $tag): ?>
                                <span class="chip"><?= $e($tag['name']) ?>
                                    <?php if ($rights['update']): ?>
                                        <button type="button" class="chip__remove" data-action="tag-remove" data-params='{"id":<?= $id ?>,"tag_id":<?= (int) $tag['id'] ?>}' title="Retirer le tag <?= $e($tag['name']) ?>" aria-label="Retirer le tag <?= $e($tag['name']) ?>"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-close"></use></svg></button>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($rights['update']): ?>
                        <form data-action="tag-add" novalidate>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <div class="field mb-0">
                                <label class="sr-only" for="geo-tag">Nouveau tag</label>
                                <div class="input-group">
                                    <input class="input input--sm" id="geo-tag" name="tag" placeholder="Ajouter un tag" maxlength="60" autocomplete="off">
                                    <button type="submit" class="btn btn--sm"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg></button>
                                </div>
                                <span class="field__error"></span>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="geo-files-title">
                <div class="card__header">
                    <h2 class="card__title" id="geo-files-title"><svg class="icon" aria-hidden="true"><use href="#i-paperclip"></use></svg> Pièces jointes</h2>
                    <span class="badge badge--muted"><?= count($attachments) ?></span>
                </div>
                <div class="card__body">
                    <?php if ($attachments === []): ?>
                        <p class="text-muted<?= $attachmentsModule ? '' : ' mb-0' ?>">Aucune pièce jointe.</p>
                    <?php else: ?>
                        <ul class="list<?= $attachmentsModule ? ' mb-3' : '' ?>">
                            <?php foreach ($attachments as $file): ?>
                                <li class="list__item">
                                    <svg class="icon text-muted" aria-hidden="true"><use href="#i-file"></use></svg>
                                    <a class="grow truncate" href="<?= $e($baseUrl . '/files/' . $file['id']) ?>" download title="Télécharger <?= $e($file['original_name']) ?>"><?= $e($file['original_name']) ?></a>
                                    <span class="text-muted text-small text-nowrap"><?= $e(\Atelier\Support\Str::humanSize((int) $file['size'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($attachmentsModule && $rights['update']): ?>
                        <a class="btn btn--sm" href="#" data-open-module="attachments" data-open-route="upload?point=<?= $id ?>"><svg class="icon" aria-hidden="true"><use href="#i-upload"></use></svg> Joindre un fichier</a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="geo-nearby-title">
                <div class="card__header"><h2 class="card__title" id="geo-nearby-title"><svg class="icon" aria-hidden="true"><use href="#i-navigation"></use></svg> À proximité</h2></div>
                <div class="card__body<?= $nearby === [] ? '' : ' card__body--flush' ?>">
                    <?php if ($nearby === []): ?>
                        <p class="text-muted mb-0">Aucun autre point à moins de <?= (int) $radiusKm ?> km.</p>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($nearby as $other): ?>
                                <li class="list__item">
                                    <a class="grow truncate" href="#" data-route="show/<?= (int) $other['id'] ?>"><?= $e($other['name']) ?></a>
                                    <span class="text-muted text-small text-nowrap"><?= $e($module->formatDistance((float) $other['distance_km'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
