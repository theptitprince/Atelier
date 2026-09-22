<?php
/**
 * Onglet « Droits » d'un utilisateur : règles directes et droits effectifs expliqués.
 * @var array $user @var list<array> $directRules @var list<array> $resources @var array|null $selected
 * @var array<string, \Atelier\Security\Acl\Decision> $effective @var array<string, string> $permissionLabels @var list<array> $groups
 * @var \Atelier\Modules\Users\UsersModule $module
 */
use Atelier\Modules\Users\UserPresenter;
use Atelier\Security\Acl\AclService;

$userId = (int) $user['id'];
$editRoute = 'edit/' . $userId . '?tab=rights';
?>
<div class="users-rights">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg>Règles directes de l’utilisateur</h2>
            <span class="text-muted text-small"><?= count($directRules) ?> règle(s)</span>
        </div>
        <div class="card__body card__body--flush">
            <?php if ($directRules === []): ?>
                <p class="table__empty">Aucune règle directe : les droits de ce compte proviennent uniquement de ses groupes<?= $groups === [] ? '' : ' (' . $e(implode(', ', array_map(static fn (array $g): string => (string) $g['label'], $groups))) . ')' ?> et des règles générales.</p>
            <?php else: ?>
                <table class="table table--compact">
                    <thead><tr><th>Ressource</th><th>Permission</th><th>Effet</th><th>Commentaire</th><th class="col-actions"></th></tr></thead>
                    <tbody>
                    <?php foreach ($directRules as $rule): ?>
                        <tr>
                            <td>
                                <a href="#" data-route="acl?resource=<?= $e(rawurlencode((string) $rule['resource'])) ?>" title="Ouvrir dans l’écran ACL"><?= $e($rule['resource_label'] ?? $rule['resource']) ?></a>
                                <div class="mono text-muted text-small"><?= $e($rule['resource']) ?></div>
                            </td>
                            <td><code><?= $e($rule['permission']) ?></code> <span class="text-muted"><?= $e($permissionLabels[$rule['permission']] ?? '') ?></span></td>
                            <td><?= UserPresenter::effectBadge((string) $rule['effect']) ?></td>
                            <td class="text-muted"><?= $e($rule['comment'] ?? '') ?></td>
                            <td class="col-actions">
                                <button type="button" class="btn btn--sm btn--icon btn--ghost text-danger" data-action="rules/remove" data-params='<?= $e(json_encode(['user_id' => $userId, 'rule_id' => (int) $rule['id']])) ?>' data-confirm="Supprimer cette règle directe ?" data-danger title="Supprimer la règle" aria-label="Supprimer la règle"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div class="card__footer">
            <form class="users-inline-form" data-action="rules/add" autocomplete="off">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                <div class="field">
                    <label class="field__label" for="ur-resource">Ressource</label>
                    <select class="select" id="ur-resource" name="resource" required>
                        <?php foreach ($resources as $resource): ?>
                            <option value="<?= $e($resource['path']) ?>"><?= $e(str_repeat('— ', max(0, (int) $resource['depth'] - 1)) . $resource['label']) ?> · <?= $e($resource['path']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__error"></span>
                </div>
                <div class="field">
                    <label class="field__label" for="ur-permission">Permission</label>
                    <select class="select" id="ur-permission" name="permission" required>
                        <?php foreach (AclService::GENERIC_PERMISSIONS as $permission): ?>
                            <option value="<?= $e($permission) ?>"><?= $e($permission) ?> — <?= $e($permissionLabels[$permission] ?? $permission) ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($permissionLabels as $code => $label): ?>
                            <?php if (!in_array($code, AclService::GENERIC_PERMISSIONS, true)): ?>
                                <option value="<?= $e($code) ?>"><?= $e($code) ?> — <?= $e($label) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__error"></span>
                </div>
                <div class="field">
                    <label class="field__label" for="ur-effect">Effet</label>
                    <select class="select" id="ur-effect" name="effect">
                        <option value="allow">Autoriser</option>
                        <option value="deny">Refuser</option>
                    </select>
                    <span class="field__error"></span>
                </div>
                <div class="field grow">
                    <label class="field__label" for="ur-comment">Commentaire</label>
                    <input class="input" id="ur-comment" name="comment" maxlength="500" placeholder="facultatif">
                    <span class="field__error"></span>
                </div>
                <div class="field">
                    <span class="field__label">&nbsp;</span>
                    <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg><span>Ajouter la règle</span></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg>Droits effectifs</h2>
        </div>
        <div class="card__body">
            <div class="field">
                <label class="field__label" for="ur-effective-resource">Ressource à examiner</label>
                <select class="select" id="ur-effective-resource" data-route-select>
                    <option value="<?= $e($editRoute) ?>"<?= $selected === null ? ' selected' : '' ?>>— Choisir une ressource —</option>
                    <?php foreach ($resources as $resource): ?>
                        <option value="<?= $e($editRoute . '&resource=' . rawurlencode((string) $resource['path'])) ?>"<?= $selected !== null && $selected['path'] === $resource['path'] ? ' selected' : '' ?>><?= $e(str_repeat('— ', max(0, (int) $resource['depth'] - 1)) . $resource['label']) ?> · <?= $e($resource['path']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field__help">Pour chaque permission pertinente, la décision calculée et la règle qui l’explique (pourquoi l’accès est autorisé ou refusé).</span>
            </div>

            <?php if ($selected !== null): ?>
                <table class="table table--compact users-effective">
                    <thead><tr><th>Permission</th><th>Décision</th><th>Explication</th></tr></thead>
                    <tbody>
                    <?php foreach ($effective as $permission => $decision): ?>
                        <tr class="<?= $decision->allowed ? '' : 'is-disabled' ?>">
                            <td class="text-nowrap"><code><?= $e($permission) ?></code> <span class="text-muted"><?= $e($permissionLabels[$permission] ?? '') ?></span></td>
                            <td><?= UserPresenter::decisionBadge($decision->allowed) ?></td>
                            <td>
                                <?= $e($decision->explanation) ?>
                                <?php if ($decision->winner !== null): ?>
                                    <div class="text-small text-muted">Règle n°<?= (int) $decision->winner['id'] ?> · <a href="#" data-route="acl?resource=<?= $e(rawurlencode((string) $decision->winner['resource'])) ?>">voir la ressource</a><?= count($decision->candidates) > 1 ? ' · ' . (count($decision->candidates) - 1) . ' autre(s) règle(s) candidate(s) écartée(s)' : '' ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted mb-0">Sélectionnez une ressource pour afficher les droits effectifs de <?= $e($user['display_name']) ?>.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
