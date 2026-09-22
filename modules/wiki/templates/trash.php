<?php
/**
 * Corbeille des pages.
 * @var list<array<string, mixed>> $rows @var int $retentionDays
 * @var \Atelier\Modules\Wiki\WikiModule $module
 */
?>
<div class="module module-wiki">
    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'La corbeille est vide', 'message' => 'Les pages supprimées y restent ' . $retentionDays . ' jours avant purge automatique (versions comprises).']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table wiki__table">
                <thead><tr><th>Titre</th><th>Identifiant</th><th>Supprimée le</th><th class="col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $e($row['title']) ?> <span class="text-muted text-small">v<?= (int) $row['revision'] ?></span></td>
                        <td><code><?= $e($row['slug']) ?></code></td>
                        <td class="text-nowrap"><?= $e($datetime($row['deleted_at'])) ?></td>
                        <td class="col-actions"><span class="table-actions">
                            <button type="button" class="btn btn--sm" data-action="restore" data-params='{"id":<?= (int) $row['id'] ?>}'><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Restaurer</button>
                            <button type="button" class="btn btn--sm btn--outline-danger" data-action="purge" data-params='{"id":<?= (int) $row['id'] ?>}' data-confirm="Supprimer définitivement « <?= $e($row['title']) ?> » et toutes ses versions ?" data-danger><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer</button>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
