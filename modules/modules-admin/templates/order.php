<?php
/**
 * Ordre d'affichage : groupes, modules de chaque groupe, accès directs de chaque module.
 * @var list<array{id: string, label: string, order: int, overridden: bool, modules: list<\Atelier\Modules\ModuleDescriptor>}> $tree
 * @var array<string, array{label: string, order: int}> $groups
 * @var array<string, mixed> $overrides
 * @var string $overridesFile
 * @var \Atelier\Modules\ModulesAdmin\ModulesAdminModule $module
 */
use Atelier\Modules\ModulesAdmin\ModulesAdminModule as M;

$icon = static fn (string $name): string => '<svg class="icon icon--sm" aria-hidden="true"><use href="#i-' . $e($name) . '"></use></svg>';
$json = static fn (array $data): string => $e(json_encode($data, JSON_UNESCAPED_UNICODE));
$groupCount = count($tree);
?>
<div class="module module-modules-admin ma-order" data-order-root>
    <div class="alert alert--info">
        <svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg>
        <div>
            <p class="alert__title">Comment réordonner</p>
            <p class="mb-0">Utilisez les boutons <strong>Monter</strong> / <strong>Descendre</strong> (accessibles au clavier) ou faites glisser un groupe, un module ou un accès direct avec la poignée <span class="ma-handle ma-handle--inline" aria-hidden="true"><?= $icon('menu') ?></span>.
                Un module peut être déposé dans un autre groupe. Les surcharges sont écrites dans <code><?= $e($overridesFile) ?></code> et la colonne de navigation est reconstruite immédiatement.</p>
        </div>
    </div>

    <ol class="ma-groups" data-dropzone="group">
        <?php foreach ($tree as $gIndex => $group): ?>
            <li class="card ma-group" data-drag-type="group" data-drag-id="<?= $e($group['id']) ?>" draggable="true">
                <div class="card__header ma-group__header">
                    <span class="ma-handle" title="Glisser pour déplacer le groupe" aria-hidden="true"><?= $icon('menu') ?></span>
                    <form class="ma-group__form" data-action="group-save">
                        <input type="hidden" name="id" value="<?= $e($group['id']) ?>">
                        <div class="field field--inline">
                            <label class="field__label" for="ma-group-label-<?= $e($group['id']) ?>">Groupe <code><?= $e($group['id']) ?></code></label>
                            <input class="input input--sm ma-group__label" id="ma-group-label-<?= $e($group['id']) ?>" type="text" name="label" value="<?= $e($group['label']) ?>" maxlength="60" required aria-label="Libellé du groupe <?= $e($group['id']) ?>">
                            <span class="field__error"></span>
                        </div>
                        <div class="field field--inline">
                            <label class="field__label" for="ma-group-order-<?= $e($group['id']) ?>">Ordre</label>
                            <input class="input input--sm ma-group__order" id="ma-group-order-<?= $e($group['id']) ?>" type="number" name="order" value="<?= (int) $group['order'] ?>" min="0" max="9999" aria-label="Ordre du groupe <?= $e($group['id']) ?>">
                            <span class="field__error"></span>
                        </div>
                        <button type="submit" class="btn btn--sm" title="Enregistrer le libellé et l’ordre du groupe"><?= $icon('save') ?><span>Enregistrer</span></button>
                        <?php if ($group['overridden']): ?><span class="badge badge--info" title="Libellé ou ordre surchargé">surcharge</span><?php endif; ?>
                    </form>
                    <span class="grow"></span>
                    <div class="btn-group ma-move" role="group" aria-label="Déplacer le groupe <?= $e($group['label']) ?>">
                        <button type="button" class="btn btn--sm btn--icon" data-action="move" data-params='<?= $json(['type' => 'group', 'id' => $group['id'], 'direction' => 'up']) ?>' title="Monter le groupe" aria-label="Monter le groupe <?= $e($group['label']) ?>"<?= $gIndex === 0 ? ' disabled' : '' ?>><?= $icon('arrow-up') ?></button>
                        <button type="button" class="btn btn--sm btn--icon" data-action="move" data-params='<?= $json(['type' => 'group', 'id' => $group['id'], 'direction' => 'down']) ?>' title="Descendre le groupe" aria-label="Descendre le groupe <?= $e($group['label']) ?>"<?= $gIndex === $groupCount - 1 ? ' disabled' : '' ?>><?= $icon('arrow-down') ?></button>
                    </div>
                    <button type="button" class="btn btn--sm btn--ghost" data-action="reset-order" data-params='<?= $json(['scope' => 'group', 'id' => $group['id']]) ?>' data-confirm="Réinitialiser le libellé, l’ordre du groupe et l’ordre de ses modules ?" title="Réinitialiser ce groupe"><?= $icon('refresh') ?></button>
                </div>

                <ol class="ma-modules" data-dropzone="module" data-group="<?= $e($group['id']) ?>">
                    <?php if ($group['modules'] === []): ?>
                        <li class="ma-empty" data-placeholder>Aucun module dans ce groupe. Déposez-en un ici ou changez le groupe d’un module.</li>
                    <?php endif; ?>
                    <?php $moduleCount = count($group['modules']); ?>
                    <?php foreach ($group['modules'] as $mIndex => $d): ?>
                        <?php
                        $state = $d->state();
                        $navigation = $d->navigation();
                        $navCount = count($navigation);
                        $mo = $overrides['modules'][$d->id] ?? [];
                        $hasOverride = isset($mo['order']) || isset($mo['group']) || !empty($mo['navigation']);
                        ?>
                        <li class="ma-module<?= $state === 'active' ? '' : ' is-muted' ?>" data-drag-type="module" data-drag-id="<?= $e($d->id) ?>" draggable="true">
                            <div class="ma-module__row">
                                <span class="ma-handle" title="Glisser pour déplacer le module" aria-hidden="true"><?= $icon('menu') ?></span>
                                <svg class="icon" aria-hidden="true"><use href="#i-<?= $e($d->icon()) ?>"></use></svg>
                                <a class="ma-module__name" href="<?= $e($module->url('detail/' . $d->id)) ?>" data-route="detail/<?= $e($d->id) ?>"><?= $e($d->name()) ?></a>
                                <code class="text-muted"><?= $e($d->id) ?></code>
                                <span class="badge badge--<?= M::stateBadge($state) ?> badge--dot"><?= $e(M::stateLabel($state)) ?></span>
                                <span class="text-muted ma-module__order" title="Ordre effectif">ordre <?= (int) $d->order() ?></span>
                                <?php if ($hasOverride): ?><span class="badge badge--info" title="Ordre ou groupe surchargé">surcharge</span><?php endif; ?>
                                <span class="grow"></span>
                                <form class="ma-module__group" data-action="set-group" data-auto-submit>
                                    <input type="hidden" name="id" value="<?= $e($d->id) ?>">
                                    <label class="sr-only" for="ma-group-select-<?= $e($d->id) ?>">Groupe du module <?= $e($d->name()) ?></label>
                                    <select class="select select--sm" id="ma-group-select-<?= $e($d->id) ?>" name="group">
                                        <?php foreach ($groups as $gid => $g): ?>
                                            <option value="<?= $e($gid) ?>"<?= $gid === $d->group() ? ' selected' : '' ?>><?= $e($g['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <div class="btn-group ma-move" role="group" aria-label="Déplacer le module <?= $e($d->name()) ?>">
                                    <button type="button" class="btn btn--sm btn--icon" data-action="move" data-params='<?= $json(['type' => 'module', 'id' => $d->id, 'direction' => 'up']) ?>' title="Monter" aria-label="Monter le module <?= $e($d->name()) ?>"<?= $mIndex === 0 ? ' disabled' : '' ?>><?= $icon('arrow-up') ?></button>
                                    <button type="button" class="btn btn--sm btn--icon" data-action="move" data-params='<?= $json(['type' => 'module', 'id' => $d->id, 'direction' => 'down']) ?>' title="Descendre" aria-label="Descendre le module <?= $e($d->name()) ?>"<?= $mIndex === $moduleCount - 1 ? ' disabled' : '' ?>><?= $icon('arrow-down') ?></button>
                                </div>
                                <button type="button" class="btn btn--sm btn--ghost" data-action="reset-order" data-params='<?= $json(['scope' => 'module', 'id' => $d->id]) ?>' data-confirm="<?= $e('Réinitialiser le groupe, l’ordre et l’ordre des accès directs du module « ' . $d->name() . ' » ?') ?>" title="Réinitialiser ce module"<?= $hasOverride ? '' : ' disabled' ?>><?= $icon('refresh') ?></button>
                            </div>

                            <?php if ($navigation !== []): ?>
                                <ol class="ma-navs" data-dropzone="nav" data-module="<?= $e($d->id) ?>" aria-label="Accès directs du module <?= $e($d->name()) ?>">
                                    <?php foreach ($navigation as $nIndex => $entry): ?>
                                        <li class="ma-nav" data-drag-type="nav" data-drag-id="<?= $e($entry['id']) ?>" draggable="true">
                                            <span class="ma-handle" title="Glisser pour déplacer l’accès direct" aria-hidden="true"><?= $icon('menu') ?></span>
                                            <?php if (!empty($entry['icon'])): ?><svg class="icon icon--sm" aria-hidden="true"><use href="#i-<?= $e($entry['icon']) ?>"></use></svg><?php endif; ?>
                                            <span class="ma-nav__label"><?= $e($entry['label']) ?></span>
                                            <code class="text-muted"><?= $e($entry['route']) ?></code>
                                            <?php if ($entry['parent'] !== null): ?><span class="badge badge--muted">sous <?= $e($entry['parent']) ?></span><?php endif; ?>
                                            <span class="text-muted ma-module__order">ordre <?= (int) $entry['order'] ?></span>
                                            <?php if (isset($mo['navigation'][$entry['id']]['order'])): ?><span class="badge badge--info">surcharge</span><?php endif; ?>
                                            <span class="grow"></span>
                                            <div class="btn-group ma-move" role="group" aria-label="Déplacer l’accès <?= $e($entry['label']) ?>">
                                                <button type="button" class="btn btn--sm btn--icon" data-action="move" data-params='<?= $json(['type' => 'nav', 'module' => $d->id, 'id' => $entry['id'], 'direction' => 'up']) ?>' title="Monter" aria-label="Monter l’accès <?= $e($entry['label']) ?>"<?= $nIndex === 0 ? ' disabled' : '' ?>><?= $icon('arrow-up') ?></button>
                                                <button type="button" class="btn btn--sm btn--icon" data-action="move" data-params='<?= $json(['type' => 'nav', 'module' => $d->id, 'id' => $entry['id'], 'direction' => 'down']) ?>' title="Descendre" aria-label="Descendre l’accès <?= $e($entry['label']) ?>"<?= $nIndex === $navCount - 1 ? ' disabled' : '' ?>><?= $icon('arrow-down') ?></button>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </li>
        <?php endforeach; ?>
    </ol>
</div>
