<?php
/**
 * Corbeille : notes supprimées depuis moins de N jours, restauration et suppression définitive.
 * Variables : $notes (list avec expires_in_days, excerpt), $retention (int), $rights (array), $module, $e, $datetime.
 */
?>
<div class="module module-notes">
    <p class="text-muted">Les notes supprimées sont conservées <?= (int) $retention ?> jours, puis effacées définitivement par la maintenance.</p>

    <?php if ($notes === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => 'La corbeille est vide',
            'message' => 'Aucune note supprimée au cours des ' . (int) $retention . ' derniers jours.',
            'actions' => '<a class="btn" href="#" data-route="list"><svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg> Retour à mes notes</a>',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table notes__table">
                <thead>
                <tr>
                    <th class="notes__col-title">Titre</th>
                    <th>Extrait</th>
                    <th class="notes__col-date">Supprimée le</th>
                    <th class="col-num">Expire dans</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($notes as $note): ?>
                    <tr<?= (int) $note['expires_in_days'] <= 3 ? ' class="is-warning"' : '' ?>>
                        <td class="notes__col-title"><span class="notes__title"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-note"></use></svg> <span><?= $e($note['title']) ?></span></span></td>
                        <td class="notes__excerpt text-muted"><?= $note['excerpt'] === '' ? '<em>Note vide</em>' : $e($note['excerpt']) ?></td>
                        <td class="notes__col-date text-nowrap"><?= $e($datetime($note['deleted_at'])) ?></td>
                        <td class="col-num text-nowrap"><?= (int) $note['expires_in_days'] ?> j</td>
                        <td class="col-actions text-nowrap">
                            <?php if ($rights['update'] ?? false): ?>
                                <button type="button" class="btn btn--sm" data-action="restore" data-params='{"id":<?= (int) $note['id'] ?>}' title="Restaurer cette note">
                                    <svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Restaurer
                                </button>
                            <?php endif; ?>
                            <?php if ($rights['delete'] ?? false): ?>
                                <button type="button" class="btn btn--sm btn--outline-danger" data-action="purge" data-params='{"id":<?= (int) $note['id'] ?>}' data-confirm="Supprimer définitivement « <?= $e($note['title']) ?> » ? Cette action est irréversible." data-confirm-title="Suppression définitive" data-confirm-label="Supprimer définitivement" data-danger title="Supprimer définitivement">
                                    <svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
