<?php
/**
 * Liste des modules installés.
 * @var list<array{descriptor: \Atelier\Modules\ModuleDescriptor, groupLabel: string, consumedByOthers: list<string>, isSelf: bool}> $rows
 * @var bool $isAdmin
 * @var array<string, int> $counts
 * @var \Atelier\Modules\ModulesAdmin\ModulesAdminModule $module
 */
use Atelier\Modules\ModulesAdmin\ModulesAdminModule as M;

$icon = static fn (string $name): string => '<svg class="icon icon--sm" aria-hidden="true"><use href="#i-' . $e($name) . '"></use></svg>';
?>
<div class="module module-modules-admin">
    <?php if ($counts['error'] > 0): ?>
        <div class="alert alert--error" role="alert">
            <svg class="icon" aria-hidden="true"><use href="#i-error"></use></svg>
            <div>
                <p class="alert__title"><?= (int) $counts['error'] ?> module(s) ont un manifeste invalide</p>
                <p class="mb-0">Ils ne sont pas chargés. Corrigez leur fichier <code>manifest.json</code> puis resynchronisez ; le détail des erreurs est affiché dans la ligne concernée.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun module installé', 'message' => 'Déposez un répertoire de module dans modules/ puis resynchronisez.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table ma-list">
                <thead>
                <tr>
                    <th>Module</th>
                    <th>Identifiant</th>
                    <th>Version</th>
                    <th>Groupe</th>
                    <th>État effectif</th>
                    <th>Manifeste</th>
                    <th class="col-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $d = $row['descriptor'];
                    $state = $d->state();
                    $manifestStatus = $d->manifest?->get('status');
                    $overridden = isset($d->overrides['status']);
                    $confirmParts = ['Désactiver le module « ' . $d->name() . ' » ?', 'Ses données ne sont pas supprimées : elles restent en base et pourront être réutilisées à la réactivation.'];
                    if ($row['consumedByOthers'] !== []) {
                        $confirmParts[] = 'Attention : ses jeux de données sont consommés par d’autres modules : ' . implode(' ; ', $row['consumedByOthers']) . '. Ces modules seront affectés.';
                    }
                    $confirm = implode(' ', $confirmParts);
                    ?>
                    <tr class="<?= $state === 'error' ? 'is-error' : ($state === 'active' ? '' : 'is-disabled') ?>" data-module-row="<?= $e($d->id) ?>">
                        <td>
                            <div class="ma-name">
                                <svg class="icon" aria-hidden="true"><use href="#i-<?= $e($d->icon()) ?>"></use></svg>
                                <div>
                                    <a href="<?= $e($module->url('detail/' . $d->id)) ?>" data-route="detail/<?= $e($d->id) ?>" class="ma-name__label"><?= $e($d->name()) ?></a>
                                    <?php if ($d->description() !== ''): ?><div class="text-muted text-small ma-desc"><?= $e($d->description()) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><code><?= $e($d->id) ?></code></td>
                        <td class="text-nowrap"><?= $e($d->version()) ?></td>
                        <td><?= $e($row['groupLabel']) ?> <span class="text-muted">(<?= $e($d->group()) ?>)</span></td>
                        <td>
                            <span class="badge badge--<?= M::stateBadge($state) ?> badge--dot"><?= $e(M::stateLabel($state)) ?></span>
                            <?php if ($overridden): ?><span class="badge badge--info" title="État imposé par l’administrateur (var/config/modules.json)">surcharge</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d->isValid()): ?>
                                <span class="badge badge--success">valide</span>
                                <?php if ($manifestStatus !== null && $manifestStatus !== $state): ?>
                                    <span class="text-muted text-small">déclaré : <?= $e(M::stateLabel((string) $manifestStatus)) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <details class="ma-errors">
                                    <summary><span class="badge badge--danger"><?= count($d->errors) ?> erreur(s)</span></summary>
                                    <ul class="ma-errors__list">
                                        <?php foreach ($d->errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td class="col-actions">
                            <div class="table-actions">
                                <?php if ($isAdmin && $d->isValid()): ?>
                                    <?php if ($state !== 'active'): ?>
                                        <button type="button" class="btn btn--sm" data-action="set-state" data-params='<?= $e(json_encode(['id' => $d->id, 'state' => 'active'], JSON_UNESCAPED_UNICODE)) ?>' title="Activer"><?= $icon('power') ?><span>Activer</span></button>
                                    <?php endif; ?>
                                    <?php if ($state !== 'maintenance' && !$row['isSelf']): ?>
                                        <button type="button" class="btn btn--sm" data-action="set-state" data-params='<?= $e(json_encode(['id' => $d->id, 'state' => 'maintenance'], JSON_UNESCAPED_UNICODE)) ?>' data-confirm="<?= $e('Mettre le module « ' . $d->name() . ' » en maintenance ? Les utilisateurs verront un message d’indisponibilité.') ?>" data-confirm-label="Mettre en maintenance" title="Mettre en maintenance"><?= $icon('tool') ?><span>Maintenance</span></button>
                                    <?php endif; ?>
                                    <?php if ($state !== 'inactive' && !$row['isSelf']): ?>
                                        <button type="button" class="btn btn--sm btn--outline-danger" data-action="set-state" data-params='<?= $e(json_encode(['id' => $d->id, 'state' => 'inactive'], JSON_UNESCAPED_UNICODE)) ?>' data-confirm="<?= $e($confirm) ?>" data-confirm-label="Désactiver" data-danger title="Désactiver"><?= $icon('eye-off') ?><span>Désactiver</span></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <a class="btn btn--sm btn--ghost" href="<?= $e($module->url('detail/' . $d->id)) ?>" data-route="detail/<?= $e($d->id) ?>" title="Détail du module"><?= $icon('info') ?><span>Détail</span></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted text-small">
            L’état effectif combine le manifeste et la surcharge administrative. Un module inactif ou en maintenance conserve toutes ses données ;
            la réactivation se fait ici ou par la console (<code>console modules:set &lt;id&gt; active</code>).
        </p>
    <?php endif; ?>
</div>
