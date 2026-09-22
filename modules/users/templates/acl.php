<?php
/**
 * Écran ACL : arborescence des ressources protégées (gauche) et règles de la ressource sélectionnée (droite).
 * @var array|null $tree @var array|null $selected @var string $selectedPath @var list<string> $openPaths @var list<array> $rules
 * @var array<string, string> $permissionLabels @var list<array> $groups @var list<array> $users @var array|null $test @var array $testInput
 * @var \Atelier\Modules\Users\UsersModule $module
 */
use Atelier\Modules\Users\UserPresenter;

$renderNode = static function (array $node, int $depth) use (&$renderNode, $e, $selectedPath, $openPaths): string {
    $resource = $node['resource'];
    $path = (string) $resource['path'];
    $hasChildren = $node['children'] !== [];
    $isOpen = in_array($path, $openPaths, true);
    $isSelected = $path === $selectedPath;
    $html = '<li class="' . ($isOpen ? 'is-open' : '') . '" data-path="' . $e($path) . '" role="treeitem"' . ($hasChildren ? ' aria-expanded="' . ($isOpen ? 'true' : 'false') . '"' : '') . ($isSelected ? ' aria-selected="true"' : '') . '>';
    $html .= '<div class="tree__row' . ($isSelected ? ' is-selected' : '') . '">';
    if ($hasChildren) {
        $html .= '<button type="button" class="tree__toggle" data-tree-toggle aria-expanded="' . ($isOpen ? 'true' : 'false') . '" aria-label="' . ($isOpen ? 'Replier' : 'Déplier') . '"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-chevron-right"></use></svg></button>';
    } else {
        $html .= '<span class="tree__toggle tree__toggle--spacer" aria-hidden="true"></span>';
    }
    $html .= '<a class="users-tree__label" href="#" data-route="acl?resource=' . $e(rawurlencode($path)) . '" title="' . $e($path) . '">' . $e($resource['label']) . '</a>';
    $html .= '<span class="badge badge--muted users-tree__kind">' . $e(UserPresenter::kindLabel((string) $resource['kind'])) . '</span>';
    $html .= '</div>';
    if ($hasChildren) {
        $html .= '<ul role="group">';
        foreach ($node['children'] as $child) {
            $html .= $renderNode($child, $depth + 1);
        }
        $html .= '</ul>';
    }
    return $html . '</li>';
};

$explicit = array_values(array_filter($rules, static fn (array $r): bool => !$r['inherited']));
$inherited = array_values(array_filter($rules, static fn (array $r): bool => $r['inherited']));
$ruleRow = static function (array $rule, bool $showOrigin) use ($e, $permissionLabels, $selectedPath): string {
    $html = '<tr>';
    $html .= '<td>' . $e(UserPresenter::subject($rule)) . '</td>';
    $html .= '<td><code>' . $e($rule['permission']) . '</code> <span class="text-muted">' . $e($permissionLabels[$rule['permission']] ?? '') . '</span></td>';
    $html .= '<td>' . UserPresenter::effectBadge((string) $rule['effect']) . '</td>';
    if ($showOrigin) {
        $html .= '<td><span class="badge badge--info">héritée</span> <a href="#" data-route="acl?resource=' . $e(rawurlencode((string) $rule['resource'])) . '" title="' . $e($rule['resource']) . '">' . $e($rule['resource_label'] ?? $rule['resource']) . '</a><div class="mono text-muted text-small">' . $e($rule['resource']) . '</div></td>';
    }
    $html .= '<td class="text-muted">' . $e($rule['comment'] ?? '') . '</td>';
    $html .= '<td class="col-actions"><button type="button" class="btn btn--sm btn--icon btn--ghost text-danger" data-action="acl/rules/remove" data-params=\'' . $e(json_encode(['id' => (int) $rule['id'], 'resource' => $selectedPath])) . '\' data-confirm="Supprimer la règle « ' . $e(UserPresenter::subject($rule)) . ' — ' . $e($rule['permission']) . ' (' . ($rule['effect'] === 'allow' ? 'autorise' : 'refuse') . ') » sur ' . $e($rule['resource']) . ' ?" data-danger title="Supprimer la règle" aria-label="Supprimer la règle"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button></td>';
    return $html . '</tr>';
};
?>
<div class="module module-users">
    <div class="split users-acl">
        <aside class="card users-acl__tree">
            <div class="card__header">
                <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg>Ressources protégées</h2>
                <button type="button" class="btn btn--sm btn--ghost" data-tree-expand-all title="Tout déplier">Tout</button>
            </div>
            <div class="card__body card__body--flush users-acl__tree-body">
                <?php if ($tree === null): ?>
                    <p class="table__empty">Aucune ressource synchronisée. Lancez « db:migrate ».</p>
                <?php else: ?>
                    <ul class="tree" role="tree" data-acl-tree><?= $renderNode($tree, 0) ?></ul>
                <?php endif; ?>
            </div>
        </aside>

        <section class="users-acl__detail">
            <?php if ($selected === null): ?>
                <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucune ressource sélectionnée', 'message' => 'Choisissez une ressource dans l’arborescence.']) ?>
            <?php else: ?>
                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-<?= $selected['kind'] === 'dataset' ? 'database' : ($selected['kind'] === 'module' ? 'puzzle' : 'shield') ?>"></use></svg><?= $e($selected['label']) ?></h2>
                        <span class="badge badge--muted"><?= $e(UserPresenter::kindLabel((string) $selected['kind'])) ?></span>
                    </div>
                    <div class="card__body">
                        <dl class="dl">
                            <dt>Chemin</dt><dd class="mono"><?= $e($selected['path']) ?></dd>
                            <?php if (!empty($selected['module_id'])): ?><dt>Module</dt><dd><?= $e($selected['module_id']) ?></dd><?php endif; ?>
                            <?php if (!empty($selected['description'])): ?><dt>Description</dt><dd><?= $e($selected['description']) ?></dd><?php endif; ?>
                            <dt>Permissions pertinentes</dt>
                            <dd>
                                <div class="chips">
                                    <?php foreach ($selected['permission_list'] as $permission): ?>
                                        <span class="chip" title="<?= $e($permissionLabels[$permission] ?? '') ?>"><?= $e($permission) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </dd>
                        </dl>
                    </div>
                </div>

                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-lock"></use></svg>Règles explicites sur cette ressource</h2>
                        <span class="text-muted text-small"><?= count($explicit) ?> règle(s)</span>
                    </div>
                    <div class="card__body card__body--flush">
                        <?php if ($explicit === []): ?>
                            <p class="table__empty">Aucune règle définie directement sur <code><?= $e($selectedPath) ?></code> : seules les règles héritées s’appliquent.</p>
                        <?php else: ?>
                            <table class="table table--compact">
                                <thead><tr><th>Sujet</th><th>Permission</th><th>Effet</th><th>Commentaire</th><th class="col-actions"></th></tr></thead>
                                <tbody><?php foreach ($explicit as $rule) { echo $ruleRow($rule, false); } ?></tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div class="card__footer">
                        <form class="users-inline-form" data-action="acl/rules/add" autocomplete="off">
                            <input type="hidden" name="resource" value="<?= $e($selectedPath) ?>">
                            <div class="field grow">
                                <label class="field__label" for="acl-subject">Sujet</label>
                                <select class="select" id="acl-subject" name="subject" required>
                                    <option value="all">Tous les utilisateurs connectés</option>
                                    <optgroup label="Groupes">
                                        <?php foreach ($groups as $group): ?>
                                            <option value="group:<?= (int) $group['id'] ?>"><?= $e($group['label']) ?> (<?= (int) $group['member_count'] ?>)</option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="Utilisateurs">
                                        <?php foreach ($users as $user): ?>
                                            <option value="user:<?= (int) $user['id'] ?>"><?= $e($user['username']) ?> — <?= $e($user['display_name']) ?><?= $user['status'] !== 'active' ? ' (désactivé)' : '' ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                                <span class="field__error"></span>
                            </div>
                            <div class="field">
                                <label class="field__label" for="acl-permission">Permission</label>
                                <select class="select" id="acl-permission" name="permission" required>
                                    <?php foreach ($selected['permission_list'] as $permission): ?>
                                        <option value="<?= $e($permission) ?>"><?= $e($permission) ?> — <?= $e($permissionLabels[$permission] ?? $permission) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="field__error"></span>
                            </div>
                            <div class="field">
                                <label class="field__label" for="acl-effect">Effet</label>
                                <select class="select" id="acl-effect" name="effect">
                                    <option value="allow">Autoriser</option>
                                    <option value="deny">Refuser</option>
                                </select>
                                <span class="field__error"></span>
                            </div>
                            <div class="field grow">
                                <label class="field__label" for="acl-comment">Commentaire</label>
                                <input class="input" id="acl-comment" name="comment" maxlength="500" placeholder="facultatif">
                                <span class="field__error"></span>
                            </div>
                            <div class="field"><span class="field__label">&nbsp;</span><button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg><span>Ajouter</span></button></div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-arrow-up"></use></svg>Règles héritées des niveaux supérieurs</h2>
                        <span class="text-muted text-small"><?= count($inherited) ?> règle(s)</span>
                    </div>
                    <div class="card__body card__body--flush">
                        <?php if ($inherited === []): ?>
                            <p class="table__empty">Aucune règle héritée<?= $selectedPath === 'atelier' ? ' : la racine n’a pas de niveau supérieur' : '' ?>.</p>
                        <?php else: ?>
                            <table class="table table--compact users-acl__inherited">
                                <thead><tr><th>Sujet</th><th>Permission</th><th>Effet</th><th>Origine</th><th>Commentaire</th><th class="col-actions"></th></tr></thead>
                                <tbody><?php foreach ($inherited as $rule) { echo $ruleRow($rule, true); } ?></tbody>
                            </table>
                        <?php endif; ?>
                        <p class="text-muted text-small users-acl__hint">Une règle plus précise (chemin plus long) prévaut sur une règle héritée ; à précision égale : utilisateur &gt; groupe &gt; tous, et entre groupes un refus prévaut. Sans règle : refus. « admin » implique toutes les permissions sur la ressource et ses descendants.</p>
                    </div>
                </div>

                <div class="card users-acl__test">
                    <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-flask"></use></svg>Tester le droit effectif</h2></div>
                    <div class="card__body">
                        <form class="users-inline-form" data-action="acl/test" autocomplete="off">
                            <input type="hidden" name="resource" value="<?= $e($selectedPath) ?>">
                            <div class="field grow">
                                <label class="field__label" for="acl-test-user">Utilisateur</label>
                                <select class="select" id="acl-test-user" name="test_user" required>
                                    <option value="">— Choisir —</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= (int) $user['id'] ?>"<?= (int) ($testInput['user'] ?? 0) === (int) $user['id'] ? ' selected' : '' ?>><?= $e($user['username']) ?> — <?= $e($user['display_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="field__error"></span>
                            </div>
                            <div class="field">
                                <label class="field__label" for="acl-test-permission">Permission</label>
                                <select class="select" id="acl-test-permission" name="test_permission" required>
                                    <?php foreach ($selected['permission_list'] as $permission): ?>
                                        <option value="<?= $e($permission) ?>"<?= ($testInput['permission'] ?? '') === $permission ? ' selected' : '' ?>><?= $e($permission) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="field__error"></span>
                            </div>
                            <div class="field"><span class="field__label">&nbsp;</span><button type="submit" class="btn"><svg class="icon" aria-hidden="true"><use href="#i-flask"></use></svg><span>Tester</span></button></div>
                        </form>

                        <?php if ($test !== null && isset($test['error'])): ?>
                            <div class="alert alert--warning mt-0"><svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg><div><?= $e($test['error']) ?></div></div>
                        <?php elseif ($test !== null): ?>
                            <?php $decision = $test['decision']; ?>
                            <div class="alert <?= $decision->allowed ? 'alert--success' : 'alert--error' ?>">
                                <svg class="icon" aria-hidden="true"><use href="#i-<?= $decision->allowed ? 'success' : 'lock' ?>"></use></svg>
                                <div>
                                    <p class="alert__title"><?= UserPresenter::decisionBadge($decision->allowed) ?> <?= $e($test['user']['username'] ?? '') ?> · <code><?= $e($decision->permission) ?></code> sur <code><?= $e($decision->resource) ?></code></p>
                                    <p class="mb-0"><?= $e($decision->explanation) ?></p>
                                </div>
                            </div>
                            <h3 class="users-acl__candidates-title">Règles candidates (par ordre de priorité)</h3>
                            <?php if ($test['candidates'] === []): ?>
                                <p class="text-muted mb-0">Aucune règle ne s’applique : refus par défaut.</p>
                            <?php else: ?>
                                <table class="table table--compact">
                                    <thead><tr><th>#</th><th>Sujet</th><th>Ressource</th><th>Permission</th><th>Effet</th><th>Rôle</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($test['candidates'] as $i => $rule): ?>
                                        <?php $isWinner = $decision->winner !== null && (int) $decision->winner['id'] === (int) $rule['id']; ?>
                                        <tr class="<?= $isWinner ? 'is-selected' : '' ?>">
                                            <td class="col-num"><?= $i + 1 ?></td>
                                            <td><?= $e(UserPresenter::subject($rule)) ?></td>
                                            <td><a href="#" data-route="acl?resource=<?= $e(rawurlencode((string) $rule['resource'])) ?>"><?= $e($rule['resource_label'] ?? $rule['resource']) ?></a><div class="mono text-muted text-small"><?= $e($rule['resource']) ?></div></td>
                                            <td><code><?= $e($rule['permission']) ?></code><?= $rule['permission'] !== $decision->permission ? ' <span class="text-muted text-small">(implicite)</span>' : '' ?></td>
                                            <td><?= UserPresenter::effectBadge((string) $rule['effect']) ?></td>
                                            <td><?= $isWinner ? '<span class="badge badge--success">déterminante</span>' : '<span class="text-muted">écartée</span>' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
