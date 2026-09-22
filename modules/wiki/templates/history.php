<?php
/**
 * Historique des versions d'une page.
 * @var array<string, mixed> $page @var list<array<string, mixed>> $revisions @var bool $canUpdate @var int $kept
 */
?>
<div class="module module-wiki">
    <div class="table-wrap">
        <table class="table wiki__table">
            <thead><tr><th class="col-num">Version</th><th>Titre</th><th>Enregistrée</th><th class="col-num">Taille</th><th class="col-actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($revisions as $revision): ?>
                <?php $current = (int) $revision['revision'] === (int) $page['revision']; ?>
                <tr<?= $current ? ' class="is-selected"' : '' ?>>
                    <td class="col-num"><?= (int) $revision['revision'] ?><?= $current ? ' <span class="badge badge--success">courante</span>' : '' ?></td>
                    <td><?= $e($revision['title']) ?></td>
                    <td class="text-nowrap"><?= $e($datetime($revision['saved_at'])) ?><?= $revision['saved_by_name'] !== null ? '<span class="text-muted text-small"> · ' . $e($revision['saved_by_name']) . '</span>' : '' ?></td>
                    <td class="col-num"><?= number_format((int) $revision['length'], 0, ',', ' ') ?></td>
                    <td class="col-actions"><span class="table-actions">
                        <a class="btn btn--sm" href="#" data-route="revision/<?= (int) $page['id'] ?>/<?= (int) $revision['revision'] ?>"><svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg> Voir</a>
                        <?php if ($canUpdate && !$current): ?><button type="button" class="btn btn--sm btn--ghost" data-action="restore-revision" data-params='{"id":<?= (int) $page['id'] ?>,"revision":<?= (int) $revision['revision'] ?>}' data-confirm="Restaurer la version <?= (int) $revision['revision'] ?> ? Une nouvelle version sera créée ; rien n’est perdu."><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Restaurer</button><?php endif; ?>
                    </span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted text-small">Les <?= (int) $kept ?> dernières versions sont conservées.</p>
</div>
