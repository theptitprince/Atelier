<?php
/**
 * Création / édition d'un groupe, membres, règles ACL du groupe et duplication.
 * @var array $group @var bool $isNew @var list<array> $members @var list<array> $candidates @var list<array> $rules
 * @var array<string, bool> $rights
 * @var \Atelier\Modules\Users\UsersModule $module
 */
use Atelier\Modules\Users\UserPresenter;

$groupId = (int) ($group['id'] ?? 0);
$isSystem = (int) ($group['is_system'] ?? 0) === 1;
?>
<div class="module module-users">
    <div class="users-form-layout">
        <form class="card users-form" data-action="groups/save" data-track-dirty data-save-shortcut autocomplete="off" novalidate>
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg><?= $isNew ? 'Nouveau groupe' : 'Groupe' ?><?= $isSystem ? ' <span class="badge badge--muted">système</span>' : '' ?></h2></div>
            <div class="card__body">
                <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= $groupId ?>"><?php endif; ?>
                <div class="field">
                    <label class="field__label" for="g-name">Nom technique <span class="required" aria-hidden="true">*</span></label>
                    <input class="input mono" id="g-name" name="name" value="<?= $e($group['name']) ?>" maxlength="64" pattern="[a-z0-9][a-z0-9_\-]{1,63}" required<?= $isNew ? ' autofocus' : ' readonly' ?>>
                    <span class="field__help"><?= $isNew ? 'Minuscules, chiffres, tiret ou souligné ; unique et non modifiable ensuite.' : 'Le nom technique n’est pas modifiable.' ?></span>
                    <span class="field__error"></span>
                </div>
                <div class="field">
                    <label class="field__label" for="g-label">Libellé <span class="required" aria-hidden="true">*</span></label>
                    <input class="input" id="g-label" name="label" value="<?= $e($group['label']) ?>" maxlength="120" required>
                    <span class="field__error"></span>
                </div>
                <div class="field">
                    <label class="field__label" for="g-description">Description</label>
                    <textarea class="textarea" id="g-description" name="description" maxlength="1000" rows="3"><?= $e($group['description'] ?? '') ?></textarea>
                    <span class="field__error"></span>
                </div>
            </div>
            <div class="card__footer form-actions form-actions--end mt-0">
                <a class="btn btn--ghost" href="#" data-route="groups">Annuler</a>
                <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-save"></use></svg><span><?= $isNew ? 'Créer le groupe' : 'Enregistrer' ?></span></button>
            </div>
        </form>

        <?php if (!$isNew): ?>
            <div class="card">
                <div class="card__header">
                    <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-users"></use></svg>Membres</h2>
                    <span class="text-muted text-small"><?= count($members) ?> membre(s)</span>
                </div>
                <div class="card__body card__body--flush">
                    <?php if ($members === []): ?>
                        <p class="table__empty">Aucun membre.</p>
                    <?php else: ?>
                        <ul class="list">
                            <?php foreach ($members as $member): ?>
                                <li class="list__item">
                                    <svg class="icon text-muted" aria-hidden="true"><use href="#i-user"></use></svg>
                                    <span class="grow">
                                        <?php if ($rights['update']): ?><a href="#" data-route="edit/<?= (int) $member['id'] ?>"><?= $e($member['display_name']) ?></a><?php else: ?><?= $e($member['display_name']) ?><?php endif; ?>
                                        <span class="text-muted">@<?= $e($member['username']) ?></span>
                                        <?= $member['status'] !== 'active' ? ' <span class="badge badge--muted">désactivé</span>' : '' ?>
                                    </span>
                                    <?php if ($rights['update']): ?>
                                        <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="groups/members/remove" data-params='<?= $e(json_encode(['group_id' => $groupId, 'user_id' => (int) $member['id']])) ?>' data-confirm="Retirer <?= $e($member['display_name']) ?> du groupe « <?= $e($group['label']) ?> » ?" title="Retirer du groupe" aria-label="Retirer du groupe"><svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg></button>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <?php if ($rights['update']): ?>
                    <div class="card__footer">
                        <?php if ($candidates === []): ?>
                            <p class="text-muted mb-0">Tous les comptes sont déjà membres de ce groupe.</p>
                        <?php else: ?>
                            <form class="users-inline-form" data-action="groups/members/add">
                                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                <div class="field grow">
                                    <label class="field__label" for="g-add-member">Ajouter un membre</label>
                                    <select class="select" id="g-add-member" name="user_id" required>
                                        <option value="">— Choisir un compte —</option>
                                        <?php foreach ($candidates as $candidate): ?>
                                            <option value="<?= (int) $candidate['id'] ?>"><?= $e($candidate['display_name']) ?> (@<?= $e($candidate['username']) ?>)<?= $candidate['status'] !== 'active' ? ' — désactivé' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="field__error"></span>
                                </div>
                                <div class="field"><span class="field__label">&nbsp;</span><button type="submit" class="btn"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg><span>Ajouter</span></button></div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$isNew && $rights['admin']): ?>
        <div class="card">
            <div class="card__header">
                <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg>Règles ACL du groupe</h2>
                <span class="text-muted text-small"><?= count($rules) ?> règle(s)</span>
            </div>
            <div class="card__body card__body--flush">
                <?php if ($rules === []): ?>
                    <p class="table__empty">Aucune règle : ajoutez-en depuis l’écran <a href="#" data-route="acl">Droits d’accès (ACL)</a> en choisissant ce groupe comme sujet.</p>
                <?php else: ?>
                    <table class="table table--compact">
                        <thead><tr><th>Ressource</th><th>Permission</th><th>Effet</th><th>Commentaire</th><th class="col-actions"></th></tr></thead>
                        <tbody>
                        <?php foreach ($rules as $rule): ?>
                            <tr>
                                <td><a href="#" data-route="acl?resource=<?= $e(rawurlencode((string) $rule['resource'])) ?>"><?= $e($rule['resource_label'] ?? $rule['resource']) ?></a><div class="mono text-muted text-small"><?= $e($rule['resource']) ?></div></td>
                                <td><code><?= $e($rule['permission']) ?></code></td>
                                <td><?= UserPresenter::effectBadge((string) $rule['effect']) ?></td>
                                <td class="text-muted"><?= $e($rule['comment'] ?? '') ?></td>
                                <td class="col-actions"><button type="button" class="btn btn--sm btn--icon btn--ghost text-danger" data-action="acl/rules/remove" data-params='<?= $e(json_encode(['id' => (int) $rule['id'], 'resource' => $rule['resource']])) ?>' data-confirm="Supprimer cette règle du groupe ?" data-danger title="Supprimer la règle" aria-label="Supprimer la règle"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$isNew && $rights['create']): ?>
        <form class="card" data-action="groups/duplicate" autocomplete="off" novalidate>
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-copy"></use></svg>Dupliquer ce profil</h2></div>
            <div class="card__body">
                <p class="text-muted">Crée un nouveau groupe avec les mêmes règles ACL que « <?= $e($group['label']) ?> » (<?= count($rules) ?> règle(s)). Les membres peuvent être copiés en option.</p>
                <input type="hidden" name="id" value="<?= $groupId ?>">
                <div class="form-grid">
                    <div class="field">
                        <label class="field__label" for="g-dup-name">Nom technique du nouveau groupe <span class="required" aria-hidden="true">*</span></label>
                        <input class="input mono" id="g-dup-name" name="name" maxlength="64" pattern="[a-z0-9][a-z0-9_\-]{1,63}" required placeholder="<?= $e($group['name']) ?>-copie">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="g-dup-label">Libellé</label>
                        <input class="input" id="g-dup-label" name="label" maxlength="120" placeholder="<?= $e($group['label']) ?> (copie)">
                        <span class="field__error"></span>
                    </div>
                </div>
                <label class="checkbox"><input type="checkbox" name="copy_members" value="1"><span>Copier aussi les <?= count($members) ?> membre(s)</span></label>
            </div>
            <div class="card__footer form-actions form-actions--end mt-0">
                <button type="submit" class="btn"><svg class="icon" aria-hidden="true"><use href="#i-copy"></use></svg><span>Dupliquer</span></button>
            </div>
        </form>
    <?php endif; ?>
</div>
