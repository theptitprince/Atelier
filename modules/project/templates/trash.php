<?php
/**
 * Corbeille des projets.
 * @var list<array<string, mixed>> $rows @var int $retention @var array<string, bool> $rights @var array<string, string> $statuses
 * @var \Atelier\Modules\Project\ProjectModule $module
 */
?>
<div class="module module-project">
    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'La corbeille est vide', 'message' => 'Les projets supprimés y restent ' . $retention . ' jours avant purge automatique (tâches et journal compris). Ils apparaissent aussi dans la corbeille globale.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table project__table">
                <thead><tr><th>Titre</th><th>Statut</th><th>Identifiant</th><th>Supprimé le</th><th>Purge dans</th><th class="col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $e($row['title']) ?></td>
                        <td><span class="badge badge--muted"><?= $e($statuses[$row['status']] ?? $row['status']) ?></span></td>
                        <td><code><?= $e($row['slug']) ?></code></td>
                        <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                        <td class="text-nowrap<?= $row['expires_in_days'] <= 3 ? ' text-danger' : '' ?>"><?= (int) $row['expires_in_days'] ?> jour(s)</td>
                        <td class="col-actions"><span class="table-actions">
                            <?php if ($rights['delete']): ?>
                                <button type="button" class="btn btn--sm" data-action="restore" data-params='{"id":<?= (int) $row['id'] ?>}'><?= $module->icon('refresh') ?> Restaurer</button>
                                <button type="button" class="btn btn--sm btn--outline-danger" data-action="purge" data-params='{"id":<?= (int) $row['id'] ?>}' data-confirm="Supprimer définitivement « <?= $e($row['title']) ?> », ses tâches et son journal ?" data-danger><?= $module->icon('trash') ?> Supprimer</button>
                            <?php else: ?>
                                <span class="text-muted text-small">Restauration réservée au droit « delete »</span>
                            <?php endif; ?>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
