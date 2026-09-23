<?php
/**
 * Corbeille des fichiers joints.
 * @var list<array<string, mixed>> $rows
 * @var int $retentionDays
 * @var bool $assist
 * @var \Atelier\Modules\Attachments\AttachmentsModule $module
 */
?>
<div class="module module-attachments">
    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'La corbeille est vide', 'message' => 'Les fichiers supprimés y restent ' . $retentionDays . ' jours avant purge automatique (fichier et métadonnées). La corbeille globale regroupe les corbeilles de tous les modules.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table attachments__table">
                <thead>
                <tr>
                    <th class="col-icon"></th>
                    <th>Nom</th>
                    <th class="col-num">Taille</th>
                    <?php if ($assist): ?><th>Auteur</th><?php endif; ?>
                    <th>Supprimé le</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $name = $module::displayName($row); $hasLabel = $name !== (string) $row['original_name']; ?>
                    <tr>
                        <td class="col-icon"><svg class="icon text-muted" aria-hidden="true"><use href="#i-<?= $e($module::kindIcon((string) $row['mime'])) ?>"></use></svg></td>
                        <td class="attachments__cell-name">
                            <a href="#" data-route="show/<?= $e($row['id']) ?>" class="attachments__name truncate" title="<?= $e($hasLabel ? 'Fichier : ' . $row['original_name'] : $row['original_name']) ?>"><?= $e($name) ?></a>
                            <?php if ($hasLabel): ?><span class="text-muted text-small truncate"><?= $e($row['original_name']) ?></span><?php endif; ?>
                        </td>
                        <td class="col-num text-nowrap"><?= $e($module->humanSize((int) $row['size'])) ?></td>
                        <?php if ($assist): ?><td><?= $row['uploader'] !== null ? $e($row['uploader_name'] ?? $row['uploader']) : '<span class="text-muted">—</span>' ?></td><?php endif; ?>
                        <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <button type="button" class="btn btn--sm" data-action="restore" data-params='{"id":"<?= $e($row['id']) ?>"}'><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Restaurer</button>
                                <button type="button" class="btn btn--sm btn--outline-danger" data-action="purge" data-params='{"id":"<?= $e($row['id']) ?>"}' data-confirm="Supprimer définitivement « <?= $e($name) ?> » ? Cette action est irréversible." data-danger><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer</button>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
