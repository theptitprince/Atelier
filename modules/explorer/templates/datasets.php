<?php
/**
 * Cartes des jeux de données partagés lisibles par l'utilisateur.
 * Variables : $datasets (list : code, name, description, module_id, module_name, fields, operations, structure_version,
 *             info_count, producers, consumers, open_route, can_update), $module, $e.
 */
$icon = static fn (string $name, string $cls = ''): string => '<svg class="icon' . ($cls !== '' ? ' ' . $cls : '') . '" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
$operationLabels = ['read' => 'lecture', 'create' => 'création', 'update' => 'modification', 'delete' => 'suppression'];
?>
<div class="module module-explorer">
    <?php if ($datasets === []): ?>
        <?= $module->renderCore('state', [
            'type' => 'empty',
            'title' => 'Aucun jeu de données lisible',
            'message' => 'Aucun module actif ne partage de jeu de données que vos droits permettent de consulter.',
        ]) ?>
    <?php else: ?>
        <div class="cards explorer__datasets">
            <?php foreach ($datasets as $dataset): ?>
                <div class="card explorer__dataset">
                    <div class="card__header">
                        <h3 class="card__title"><?= $icon('database') ?> <?= $e($dataset['name']) ?></h3>
                        <span class="badge badge--muted"><?= $e($dataset['module_name']) ?></span>
                    </div>
                    <div class="card__body">
                        <p class="text-small mb-2"><code><?= $e($dataset['code']) ?></code> · version <?= (int) $dataset['structure_version'] ?></p>
                        <?php if (!empty($dataset['description'])): ?>
                            <p class="text-small text-muted"><?= $e($dataset['description']) ?></p>
                        <?php endif; ?>
                        <div class="kpi explorer__kpi">
                            <span class="kpi__value"><?= (int) $dataset['info_count'] ?></span>
                            <span class="kpi__label"><?= (int) $dataset['info_count'] === 1 ? 'information enregistrée dans le registre' : 'informations enregistrées dans le registre' ?></span>
                        </div>
                        <dl class="dl explorer__dataset-meta">
                            <dt>Champs</dt>
                            <dd>
                                <?php if ($dataset['fields'] === []): ?><span class="text-muted">non documentés</span><?php else: ?>
                                    <?php foreach ($dataset['fields'] as $field => $label): ?><span class="explorer__field" title="<?= $e($field) ?>"><code><?= $e($field) ?></code> <?= $e($label) ?></span><?php endforeach; ?>
                                <?php endif; ?>
                            </dd>
                            <dt>Opérations</dt>
                            <dd><?php foreach ($dataset['operations'] as $op): ?><span class="badge badge--info"><?= $e($operationLabels[$op] ?? $op) ?></span> <?php endforeach; ?></dd>
                            <dt>Vos droits</dt>
                            <dd><span class="badge badge--success">lecture</span> <?= $dataset['can_update'] ? '<span class="badge badge--success">modification</span>' : '<span class="badge badge--muted">modification refusée</span>' ?></dd>
                            <dt>Producteurs</dt>
                            <dd><?= $dataset['producers'] === [] ? '<span class="text-muted">—</span>' : $e(implode(', ', $dataset['producers'])) ?></dd>
                            <dt>Consommateurs</dt>
                            <dd><?= $dataset['consumers'] === [] ? '<span class="text-muted">aucun déclaré</span>' : $e(implode(', ', $dataset['consumers'])) ?></dd>
                            <dt>Ouverture</dt>
                            <dd><?= $dataset['open_route'] === null ? '<span class="text-muted">pas de route <code>openRoute</code></span>' : '<code>' . $e($dataset['open_route']) . '</code>' ?></dd>
                        </dl>
                    </div>
                    <div class="card__footer">
                        <a class="btn btn--sm" href="#" data-route="search?dataset=<?= $e(rawurlencode($dataset['code'])) ?>"><?= $icon('search') ?> Rechercher dans ce jeu</a>
                        <a class="btn btn--sm btn--ghost" href="#" data-route="attachments?dataset=<?= $e(rawurlencode($dataset['code'])) ?>"><?= $icon('paperclip') ?> Pièces jointes</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
