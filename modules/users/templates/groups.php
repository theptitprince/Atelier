<?php
/**
 * Liste des groupes.
 * @var list<array> $groups @var array<string, bool> $rights
 * @var \Atelier\Modules\Users\UsersModule $module
 */
?>
<div class="module module-users">
    <?php if ($groups === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun groupe', 'message' => 'Créez un groupe pour regrouper des utilisateurs et leur attribuer des droits communs.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Nom technique</th>
                    <th>Libellé</th>
                    <th>Description</th>
                    <th class="col-num">Membres</th>
                    <th>Type</th>
                    <?php if ($rights['update'] || $rights['create'] || $rights['delete']): ?><th class="col-actions">Actions</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr>
                        <td class="mono">
                            <?php if ($rights['update']): ?>
                                <a href="#" data-route="groups/edit/<?= (int) $group['id'] ?>"><?= $e($group['name']) ?></a>
                            <?php else: ?>
                                <?= $e($group['name']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $e($group['label']) ?></td>
                        <td class="text-muted"><?= $e($group['description'] ?? '') ?></td>
                        <td class="col-num"><a href="#" data-route="list?group=<?= (int) $group['id'] ?>" title="Voir les membres dans la liste des utilisateurs"><?= (int) $group['member_count'] ?></a></td>
                        <td><?= (int) $group['is_system'] === 1 ? '<span class="badge badge--muted">système</span>' : '<span class="badge">personnalisé</span>' ?></td>
                        <?php if ($rights['update'] || $rights['create'] || $rights['delete']): ?>
                            <td class="col-actions">
                                <div class="table-actions">
                                    <?php if ($rights['update']): ?>
                                        <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="groups/edit/<?= (int) $group['id'] ?>" title="Modifier, gérer les membres" aria-label="Modifier"><svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg></a>
                                    <?php endif; ?>
                                    <?php if ($rights['create']): ?>
                                        <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="groups/duplicate" data-params='<?= $e(json_encode(['id' => (int) $group['id']])) ?>' data-prompt="Nom technique du nouveau groupe (minuscules, chiffres, tirets). Les règles ACL de « <?= $e($group['label']) ?> » seront copiées ; les membres ne le seront pas." data-prompt-field="name" data-confirm-title="Dupliquer le profil" title="Dupliquer (copier les règles ACL)" aria-label="Dupliquer"><svg class="icon" aria-hidden="true"><use href="#i-copy"></use></svg></button>
                                    <?php endif; ?>
                                    <?php if ($rights['delete'] && (int) $group['is_system'] !== 1): ?>
                                        <button type="button" class="btn btn--sm btn--icon btn--ghost text-danger" data-action="groups/delete" data-params='<?= $e(json_encode(['id' => (int) $group['id']])) ?>' data-confirm="Supprimer définitivement le groupe « <?= $e($group['label']) ?> » (<?= (int) $group['member_count'] ?> membre(s)), ses appartenances et ses règles ACL ?" data-danger title="Supprimer" aria-label="Supprimer"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted text-small">Les groupes système ne peuvent pas être supprimés. La duplication copie les règles ACL du groupe (« profil ») ; depuis la fiche d’un groupe, il est aussi possible de copier ses membres.</p>
    <?php endif; ?>
</div>
