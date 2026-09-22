<?php
/**
 * Catalogue de tous les jeux de données (partagés et privés) et jeux non alimentés.
 * @var list<array<string, mixed>> $datasets
 * @var list<array<string, mixed>> $orphaned
 * @var array<string, string> $states
 * @var array<string, string> $names
 * @var \Atelier\Modules\ModulesAdmin\ModulesAdminModule $module
 */
use Atelier\Modules\ModulesAdmin\ModulesAdminModule as M;

$stateBadge = static fn (string $s): string => '<span class="badge badge--' . M::stateBadge($s) . ' badge--dot">' . $e(M::stateLabel($s)) . '</span>';
$moduleLink = static fn (string $id): string => '<a href="' . $e($module->url('detail/' . $id)) . '" data-route="detail/' . $e($id) . '" title="' . $e($names[$id] ?? $id) . '">' . $e($id) . '</a>';
?>
<div class="module module-modules-admin ma-datasets">
    <?php if ($datasets === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun jeu de données catalogué', 'message' => 'Les jeux de données sont déclarés dans le manifeste des modules et catalogués lors de la synchronisation.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Nom</th>
                    <th>Visibilité</th>
                    <th>Module propriétaire</th>
                    <th>Tables</th>
                    <th>Opérations</th>
                    <th class="col-num">Version</th>
                    <th>Producteurs</th>
                    <th>Consommateurs</th>
                    <th>Mis à jour</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($datasets as $dataset): ?>
                    <?php $ownerState = $states[$dataset['module_id']] ?? 'missing'; $present = (int) $dataset['is_present'] === 1; ?>
                    <tr class="<?= !$present ? 'is-disabled' : '' ?>">
                        <td>
                            <code><?= $e($dataset['code']) ?></code>
                            <?php if (!$present): ?><span class="badge badge--warning" title="Plus déclaré par aucun manifeste">absent</span><?php endif; ?>
                        </td>
                        <td><?= $e($dataset['name']) ?><?php if (!empty($dataset['description'])): ?><div class="text-muted ma-desc"><?= $e($dataset['description']) ?></div><?php endif; ?></td>
                        <td><span class="badge badge--<?= $dataset['visibility'] === 'shared' ? 'info' : 'muted' ?>"><?= $dataset['visibility'] === 'shared' ? 'partagé' : 'privé' ?></span></td>
                        <td><?= $moduleLink((string) $dataset['module_id']) ?> <?= $stateBadge($ownerState) ?></td>
                        <td><?php if ($dataset['tables'] === []): ?><span class="text-muted">—</span><?php else: ?><?php foreach ($dataset['tables'] as $table): ?><code><?= $e($table) ?></code> <?php endforeach; ?><?php endif; ?></td>
                        <td class="ma-perms"><?php foreach ($dataset['operations'] as $op): ?><span class="badge badge--muted"><?= $e($op) ?></span><?php endforeach; ?></td>
                        <td class="col-num"><?= (int) $dataset['structure_version'] ?></td>
                        <td><?= $dataset['producers'] === [] ? '<span class="text-muted">—</span>' : implode(', ', array_map($moduleLink, $dataset['producers'])) ?></td>
                        <td><?= $dataset['consumers'] === [] ? '<span class="text-muted">aucun</span>' : implode(', ', array_map($moduleLink, $dataset['consumers'])) ?></td>
                        <td class="text-nowrap"><?= $e($datetime($dataset['updated_at'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted ma-hint">Les jeux privés ne sont accessibles qu’à leur module ; les jeux partagés sont lus via le service du module propriétaire, après contrôle de la ressource <code>atelier/&lt;module&gt;/data/&lt;nom&gt;</code>.</p>
    <?php endif; ?>

    <h2 class="mt-4">Jeux de données non alimentés</h2>
    <?php if ($orphaned === []): ?>
        <div class="alert alert--success">
            <svg class="icon" aria-hidden="true"><use href="#i-success"></use></svg>
            <div><p class="alert__title mb-0">Tous les jeux catalogués sont produits par un module actif.</p></div>
        </div>
    <?php else: ?>
        <div class="alert alert--warning">
            <svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg>
            <div>
                <p class="alert__title"><?= count($orphaned) ?> jeu(x) de données sans producteur actif</p>
                <p class="mb-0">Leur module propriétaire est inactif, en maintenance, en erreur ou a disparu. Les modules consommateurs listés ne peuvent plus y accéder. Les données restent en base.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th>Code</th><th>Nom</th><th>Module propriétaire</th><th>État du producteur</th><th>Déclaré</th><th>Consommateurs affectés</th></tr></thead>
                <tbody>
                <?php foreach ($orphaned as $dataset): ?>
                    <tr class="is-warning">
                        <td><code><?= $e($dataset['code']) ?></code></td>
                        <td><?= $e($dataset['name']) ?></td>
                        <td><?= isset($names[$dataset['module_id']]) ? $moduleLink((string) $dataset['module_id']) : '<code>' . $e($dataset['module_id']) . '</code>' ?></td>
                        <td><?= $stateBadge((string) $dataset['producer_state']) ?></td>
                        <td><?= (int) $dataset['is_present'] === 1 ? '<span class="badge badge--success">oui</span>' : '<span class="badge badge--warning">plus déclaré</span>' ?></td>
                        <td>
                            <?php $consumers = array_values(array_diff($dataset['consumers'], [$dataset['module_id']])); ?>
                            <?php if ($consumers === []): ?><span class="text-muted">aucun</span><?php else: ?>
                                <?php foreach ($consumers as $consumer): ?><?= $moduleLink($consumer) ?> <?= $stateBadge($states[$consumer] ?? 'missing') ?><br><?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
