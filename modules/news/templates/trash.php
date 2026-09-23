<?php
/**
 * Corbeille des actualités : flux retirés et faits archivés supprimés depuis moins de N jours.
 * @var list<array<string, mixed>> $rows lignes décorées (trash_kind, trash_id, trash_label, trash_type, deleted_at, expires_in_days, archived_count)
 * @var int $retention
 * @var array<string, bool> $rights update, archive, delete
 * @var \Atelier\Modules\News\NewsModule $module
 */
?>
<div class="module module-news">
    <p class="text-muted">Les flux retirés et les faits archivés supprimés sont conservés <?= (int) $retention ?> jours puis effacés définitivement par la maintenance. Un flux en corbeille masque ses entrées non archivées ; ses faits archivés restent consultables et il ne peut être purgé tant qu’il en porte. Les entrées non archivées suivent la rétention de leur flux, pas la corbeille.</p>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'La corbeille est vide', 'message' => 'Aucun flux ni fait archivé supprimé au cours des ' . (int) $retention . ' derniers jours.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Type</th><th>Élément</th><th>Détail</th><th>Supprimé le</th><th class="col-num">Expire dans</th><th class="col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $isFeed = $row['trash_kind'] === 'feed';
                    $canRestore = $isFeed ? ($rights['update'] ?? false) : ($rights['archive'] ?? false);
                    $blocked = $isFeed && $row['archived_count'] > 0;
                    ?>
                    <tr<?= !$blocked && (int) $row['expires_in_days'] <= 3 ? ' class="is-warning"' : '' ?>>
                        <td class="text-nowrap"><svg class="icon icon--sm text-muted" aria-hidden="true"><use href="#i-<?= $isFeed ? 'globe' : 'archive' ?>"></use></svg> <?= $e($row['trash_type']) ?></td>
                        <td>
                            <?= $e($isFeed ? $row['title'] : $row['title']) ?>
                            <?php if ($isFeed): ?><span class="text-muted text-small truncate news__feed-url" title="<?= $e($row['url']) ?>"><?= $e($row['url']) ?></span><?php endif; ?>
                        </td>
                        <td class="text-small">
                            <?php if ($isFeed): ?>
                                <?= $row['category_name'] !== null ? $e($row['category_name']) . ' · ' : '' ?><?= (int) $row['item_count'] ?> entrée(s) masquée(s)<?= $row['archived_count'] > 0 ? ' · <span class="badge badge--warning">' . (int) $row['archived_count'] . ' fait(s) archivé(s) : purge bloquée</span>' : '' ?>
                            <?php else: ?>
                                <?= $e($row['feed_title']) ?><?= $row['feed_deleted_at'] !== null ? ' <span class="badge badge--muted">flux en corbeille</span>' : '' ?><?= $row['archived_at'] !== null ? ' · archivé le ' . $e($datetime($row['archived_at'])) : '' ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                        <td class="col-num text-nowrap"><?= $blocked ? '<span class="text-muted">—</span>' : (int) $row['expires_in_days'] . ' j' ?></td>
                        <td class="col-actions"><span class="table-actions">
                            <?php if ($canRestore): ?><button type="button" class="btn btn--sm" data-action="trash-restore" data-params='<?= $e(json_encode(['id' => $row['trash_id']], JSON_UNESCAPED_UNICODE)) ?>' title="Restaurer"><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Restaurer</button><?php endif; ?>
                            <?php if (($rights['delete'] ?? false) && !$blocked): ?><button type="button" class="btn btn--sm btn--outline-danger" data-action="trash-purge" data-params='<?= $e(json_encode(['id' => $row['trash_id']], JSON_UNESCAPED_UNICODE)) ?>' data-confirm="Supprimer définitivement « <?= $e($row['title']) ?> » ?<?= $isFeed ? ' Ses entrées seront effacées.' : ' Sa note, ses tags et ses relations seront perdus.' ?> Cette action est irréversible." data-confirm-title="Suppression définitive" data-confirm-label="Supprimer définitivement" data-danger title="Supprimer définitivement"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button><?php endif; ?>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
