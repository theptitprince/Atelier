<?php
/**
 * Détail d'un module.
 * @var \Atelier\Modules\ModuleDescriptor $descriptor
 * @var array<string, mixed> $data  descripteur sérialisé (toArray())
 * @var string $groupLabel
 * @var list<array<string, mixed>> $manifestResources
 * @var list<array<string, mixed>> $aclResources
 * @var list<array<string, mixed>> $dbPermissions
 * @var list<array<string, mixed>> $produced
 * @var list<array{code: string, dataset: array|null, ownerState: string}> $consumed
 * @var array<string, list<string>> $dependents
 * @var array<string, string> $states
 * @var list<array<string, mixed>> $errors
 * @var int $activityCount
 * @var list<array{version: int, name: string, applied_at: string}> $migrations
 * @var int $migrationVersion
 * @var list<array{version: int, name: string}> $availableMigrations
 * @var bool $isAdmin
 * @var bool $isSelf
 * @var \Atelier\Modules\ModulesAdmin\ModulesAdminModule $module
 */
use Atelier\Modules\ModulesAdmin\ModulesAdminModule as M;

$state = $descriptor->state();
$stateBadge = static fn (string $s): string => '<span class="badge badge--' . M::stateBadge($s) . ' badge--dot">' . $e(M::stateLabel($s)) . '</span>';
$pendingMigrations = array_values(array_filter($availableMigrations, static fn (array $m): bool => $m['version'] > $migrationVersion));
$resultBadge = static fn (string $r): string => $r === 'denied' ? 'warning' : 'danger';
?>
<div class="module module-modules-admin ma-detail">
    <?php if (!$descriptor->isValid()): ?>
        <div class="alert alert--error" role="alert">
            <svg class="icon" aria-hidden="true"><use href="#i-error"></use></svg>
            <div>
                <p class="alert__title">Manifeste invalide : le module n’est pas chargé</p>
                <ul class="mb-0">
                    <?php foreach ($descriptor->errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="ma-detail__grid">
        <section class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg>Identité</h2></div>
            <div class="card__body">
                <dl class="dl">
                    <dt>Nom</dt><dd><?= $e($data['name']) ?></dd>
                    <dt>Identifiant</dt><dd><code><?= $e($data['id']) ?></code></dd>
                    <dt>Version</dt><dd><?= $e($data['version']) ?></dd>
                    <dt>Description</dt><dd><?= $data['description'] !== '' ? $e($data['description']) : '<span class="text-muted">—</span>' ?></dd>
                    <dt>Auteur</dt><dd><?= $data['author'] !== '' ? $e($data['author']) : '<span class="text-muted">—</span>' ?></dd>
                    <dt>Icône</dt><dd><svg class="icon" aria-hidden="true"><use href="#i-<?= $e($data['icon']) ?>"></use></svg> <code><?= $e($data['icon']) ?></code></dd>
                    <dt>Répertoire</dt><dd><code><?= $e($descriptor->directory) ?></code></dd>
                    <?php if ($descriptor->manifest !== null): ?>
                        <dt>Classe d’entrée</dt><dd><code><?= $e($descriptor->manifest->entryClass()) ?></code></dd>
                        <dt>Route par défaut</dt><dd><code><?= $e($data['defaultRoute']) ?></code></dd>
                        <dt>Conservation en arrière-plan</dt><dd><?= $descriptor->manifest->keepAlive() ? 'oui (keepAlive)' : 'non' ?></dd>
                        <dt>Route de badge</dt><dd><?= $descriptor->manifest->badgeRoute() !== null ? '<code>' . $e($descriptor->manifest->badgeRoute()) . '</code>' : '<span class="text-muted">aucune</span>' ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card__header">
                <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-power"></use></svg>État et affichage</h2>
            </div>
            <div class="card__body">
                <dl class="dl">
                    <dt>État effectif</dt><dd><?= $stateBadge($state) ?></dd>
                    <dt>État du manifeste</dt><dd><?= $data['manifestStatus'] !== null ? $e(M::stateLabel((string) $data['manifestStatus'])) : '<span class="text-muted">—</span>' ?></dd>
                    <dt>Surcharges</dt>
                    <dd>
                        <?php if ($data['overrides'] === []): ?>
                            <span class="text-muted">aucune</span>
                        <?php else: ?>
                            <pre class="ma-json mb-0"><?= $e(json_encode($data['overrides'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                        <?php endif; ?>
                    </dd>
                    <dt>Groupe</dt><dd><?= $e($groupLabel) ?> <span class="text-muted">(<?= $e($data['group']) ?>)</span></dd>
                    <dt>Ordre</dt><dd><?= (int) $data['order'] ?></dd>
                </dl>
                <?php if ($isAdmin && $descriptor->isValid()): ?>
                    <div class="form-actions">
                        <?php if ($state !== 'active'): ?>
                            <button type="button" class="btn btn--primary" data-action="set-state" data-params='<?= $e(json_encode(['id' => $descriptor->id, 'state' => 'active'])) ?>'>Activer</button>
                        <?php endif; ?>
                        <?php if (!$isSelf && $state !== 'maintenance'): ?>
                            <button type="button" class="btn" data-action="set-state" data-params='<?= $e(json_encode(['id' => $descriptor->id, 'state' => 'maintenance'])) ?>' data-confirm="Mettre ce module en maintenance ?" data-confirm-label="Mettre en maintenance">Mettre en maintenance</button>
                        <?php endif; ?>
                        <?php if (!$isSelf && $state !== 'inactive'): ?>
                            <button type="button" class="btn btn--outline-danger" data-action="set-state" data-params='<?= $e(json_encode(['id' => $descriptor->id, 'state' => 'inactive'])) ?>' data-confirm="<?= $e('Désactiver le module « ' . $descriptor->name() . ' » ? Ses données ne sont pas supprimées.' . ($dependents !== [] ? ' Modules dépendants : ' . implode(', ', array_keys($dependents)) . '.' : '')) ?>" data-confirm-label="Désactiver" data-danger>Désactiver</button>
                        <?php endif; ?>
                        <a class="btn btn--ghost" href="<?= $e($module->url('order')) ?>" data-route="order">Ordre d’affichage</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-list"></use></svg>Navigation (accès directs)</h2><span class="badge badge--muted"><?= count($data['navigation']) ?></span></div>
        <div class="card__body card__body--flush">
            <?php if ($data['navigation'] === []): ?>
                <p class="table__empty">Aucun accès direct déclaré : le libellé du module ouvre la route par défaut.</p>
            <?php else: ?>
                <table class="table table--compact">
                    <thead><tr><th>Ordre</th><th>Identifiant</th><th>Libellé</th><th>Route</th><th>Permission</th><th>Ressource ACL</th><th>Parent</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['navigation'] as $entry): ?>
                        <tr>
                            <td class="col-num"><?= (int) $entry['order'] ?></td>
                            <td><code><?= $e($entry['id']) ?></code></td>
                            <td><?= $e($entry['label']) ?><?php if (!empty($entry['description'])): ?> <span class="text-muted">— <?= $e($entry['description']) ?></span><?php endif; ?></td>
                            <td><code><?= $e($entry['route']) ?></code></td>
                            <td><span class="badge"><?= $e($entry['permission']) ?></span></td>
                            <td><code>atelier/<?= $e($descriptor->id) ?>/<?= $e($entry['resource']) ?></code></td>
                            <td><?= $entry['parent'] !== null ? '<code>' . $e($entry['parent']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <div class="ma-detail__grid">
        <section class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-key"></use></svg>Permissions propres</h2><span class="badge badge--muted"><?= count($data['permissions']) ?></span></div>
            <div class="card__body card__body--flush">
                <?php if ($data['permissions'] === []): ?>
                    <p class="table__empty">Aucune permission propre : seules les permissions génériques s’appliquent.</p>
                <?php else: ?>
                    <?php $synced = array_column($dbPermissions, 'code'); ?>
                    <table class="table table--compact">
                        <thead><tr><th>Code</th><th>Libellé</th><th>Description</th><th>Synchronisée</th></tr></thead>
                        <tbody>
                        <?php foreach ($data['permissions'] as $permission): ?>
                            <tr>
                                <td><code><?= $e($permission['code']) ?></code></td>
                                <td><?= $e($permission['label']) ?></td>
                                <td><?= $e($permission['description'] ?? '') ?></td>
                                <td><?= in_array($permission['code'], $synced, true) ? '<span class="badge badge--success">oui</span>' : '<span class="badge badge--warning">non</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-shield"></use></svg>Ressources protégées</h2><span class="badge badge--muted"><?= count($aclResources) ?></span></div>
            <div class="card__body card__body--flush">
                <?php if ($manifestResources !== []): ?>
                    <p class="ma-hint">Déclarées dans le manifeste (<code>resources</code>) : <?php foreach ($manifestResources as $resource): ?><code><?= $e($resource['path']) ?></code> (<?= $e($resource['kind']) ?>) <?php endforeach; ?></p>
                <?php endif; ?>
                <?php if ($aclResources === []): ?>
                    <p class="table__empty">Aucune ressource synchronisée en base (module jamais synchronisé ou manifeste invalide).</p>
                <?php else: ?>
                    <table class="table table--compact">
                        <thead><tr><th>Chemin</th><th>Type</th><th>Libellé</th><th>Permissions</th><th>Présente</th></tr></thead>
                        <tbody>
                        <?php foreach ($aclResources as $resource): ?>
                            <tr class="<?= $resource['is_present'] ? '' : 'is-disabled' ?>">
                                <td><code><?= $e($resource['path']) ?></code></td>
                                <td><?= $e($resource['kind']) ?></td>
                                <td><?= $e($resource['label']) ?></td>
                                <td class="ma-perms"><?php foreach ((array) $resource['permissions'] as $p): ?><span class="badge badge--muted"><?= $e($p) ?></span><?php endforeach; ?></td>
                                <td><?= $resource['is_present'] ? '<span class="badge badge--success">oui</span>' : '<span class="badge badge--warning">disparue</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-database"></use></svg>Jeux de données produits</h2><span class="badge badge--muted"><?= count($produced) ?></span></div>
        <div class="card__body card__body--flush">
            <?php if ($produced === []): ?>
                <p class="table__empty">Ce module ne déclare aucun jeu de données.</p>
            <?php else: ?>
                <table class="table table--compact">
                    <thead><tr><th>Code</th><th>Nom</th><th>Visibilité</th><th>Tables</th><th>Champs</th><th>Opérations</th><th>Version</th><th>Consommateurs</th></tr></thead>
                    <tbody>
                    <?php foreach ($produced as $dataset): ?>
                        <tr class="<?= (int) $dataset['is_present'] === 1 ? '' : 'is-disabled' ?>">
                            <td><code><?= $e($dataset['code']) ?></code><?php if ((int) $dataset['is_present'] !== 1): ?> <span class="badge badge--warning">absent</span><?php endif; ?></td>
                            <td><?= $e($dataset['name']) ?><?php if (!empty($dataset['description'])): ?><div class="text-muted ma-desc"><?= $e($dataset['description']) ?></div><?php endif; ?></td>
                            <td><span class="badge badge--<?= $dataset['visibility'] === 'shared' ? 'info' : 'muted' ?>"><?= $dataset['visibility'] === 'shared' ? 'partagé' : 'privé' ?></span></td>
                            <td>
                                <?php foreach ($dataset['tableCounts'] as $table => $count): ?>
                                    <div><code><?= $e($table) ?></code> <span class="text-muted"><?= $count === null ? 'table absente' : $count . ' ligne(s)' ?></span></div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($dataset['fields'] === []): ?><span class="text-muted">—</span><?php else: ?>
                                    <details class="ma-fields"><summary><?= count($dataset['fields']) ?> champ(s)</summary>
                                        <dl class="dl"><?php foreach ($dataset['fields'] as $field => $label): ?><dt><code><?= $e($field) ?></code></dt><dd><?= $e(is_scalar($label) ? $label : json_encode($label)) ?></dd><?php endforeach; ?></dl>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td class="ma-perms"><?php foreach ($dataset['operations'] as $op): ?><span class="badge badge--muted"><?= $e($op) ?></span><?php endforeach; ?></td>
                            <td class="col-num"><?= (int) $dataset['structure_version'] ?></td>
                            <td>
                                <?php $others = array_values(array_diff($dataset['consumers'], [$descriptor->id])); ?>
                                <?php if ($others === []): ?><span class="text-muted">aucun</span><?php else: ?>
                                    <?php foreach ($others as $consumer): ?><a href="<?= $e($module->url('detail/' . $consumer)) ?>" data-route="detail/<?= $e($consumer) ?>" class="chip"><?= $e($consumer) ?></a> <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <div class="ma-detail__grid">
        <section class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-link"></use></svg>Jeux de données consommés</h2><span class="badge badge--muted"><?= count($consumed) ?></span></div>
            <div class="card__body card__body--flush">
                <?php if ($consumed === []): ?>
                    <p class="table__empty">Ce module ne consomme aucun jeu de données d’un autre module.</p>
                <?php else: ?>
                    <table class="table table--compact">
                        <thead><tr><th>Code</th><th>Propriétaire</th><th>État du propriétaire</th><th>Visibilité</th></tr></thead>
                        <tbody>
                        <?php foreach ($consumed as $item): ?>
                            <?php $ds = $item['dataset']; ?>
                            <tr class="<?= $ds === null || $item['ownerState'] !== 'active' || $ds['visibility'] !== 'shared' ? 'is-warning' : '' ?>">
                                <td><code><?= $e($item['code']) ?></code></td>
                                <td><?= $ds !== null ? '<a href="' . $e($module->url('detail/' . $ds['module_id'])) . '" data-route="detail/' . $e($ds['module_id']) . '">' . $e($ds['module_id']) . '</a>' : '<span class="text-danger">jeu inconnu du catalogue</span>' ?></td>
                                <td><?= $ds !== null ? $stateBadge($item['ownerState']) : '<span class="text-muted">—</span>' ?></td>
                                <td><?php if ($ds !== null): ?><span class="badge badge--<?= $ds['visibility'] === 'shared' ? 'info' : 'danger' ?>"><?= $ds['visibility'] === 'shared' ? 'partagé' : 'privé (inaccessible)' ?></span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-layers"></use></svg>Modules dépendants</h2><span class="badge badge--muted"><?= count($dependents) ?></span></div>
            <div class="card__body card__body--flush">
                <?php if ($dependents === []): ?>
                    <p class="table__empty">Aucun module connu ne consomme les jeux de données de ce module.</p>
                <?php else: ?>
                    <table class="table table--compact">
                        <thead><tr><th>Module</th><th>État</th><th>Jeux consommés</th></tr></thead>
                        <tbody>
                        <?php foreach ($dependents as $consumer => $codes): ?>
                            <tr>
                                <td><a href="<?= $e($module->url('detail/' . $consumer)) ?>" data-route="detail/<?= $e($consumer) ?>"><?= $e($consumer) ?></a></td>
                                <td><?= $stateBadge($states[$consumer] ?? 'missing') ?></td>
                                <td><?php foreach ($codes as $code): ?><code><?= $e($code) ?></code> <?php endforeach; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="ma-detail__grid">
        <section class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-folder"></use></svg>Ressources statiques</h2></div>
            <div class="card__body">
                <dl class="dl">
                    <dt>CSS</dt><dd><?= $data['assets']['css'] === [] ? '<span class="text-muted">—</span>' : implode('<br>', array_map(static fn (string $p): string => '<code>' . $e($p) . '</code>', $data['assets']['css'])) ?></dd>
                    <dt>JavaScript</dt><dd><?= $data['assets']['js'] === [] ? '<span class="text-muted">—</span>' : implode('<br>', array_map(static fn (string $p): string => '<code>' . $e($p) . '</code>', $data['assets']['js'])) ?></dd>
                    <dt>Bibliothèques tierces</dt>
                    <dd>
                        <?php if ($data['assets']['vendor'] === []): ?><span class="text-muted">—</span><?php else: ?>
                            <?php foreach ($data['assets']['vendor'] as $vendor): ?>
                                <div><code><?= $e($vendor['path']) ?></code> — <?= $e($vendor['name']) ?> <?= $e($vendor['version']) ?> <span class="text-muted">(<?= $e($vendor['license'] ?: 'licence non précisée') ?>)</span></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-database"></use></svg>Migrations</h2>
                <span class="badge badge--<?= $pendingMigrations === [] ? 'success' : 'warning' ?>">version <?= (int) $migrationVersion ?></span>
            </div>
            <div class="card__body card__body--flush">
                <?php if ($migrations === [] && $availableMigrations === []): ?>
                    <p class="table__empty">Ce module n’a pas de migrations.</p>
                <?php else: ?>
                    <table class="table table--compact">
                        <thead><tr><th>Version</th><th>Nom</th><th>Appliquée le</th></tr></thead>
                        <tbody>
                        <?php foreach ($migrations as $migration): ?>
                            <tr><td class="col-num"><?= sprintf('%03d', $migration['version']) ?></td><td><code><?= $e($migration['name']) ?></code></td><td><?= $e($datetime($migration['applied_at'])) ?></td></tr>
                        <?php endforeach; ?>
                        <?php foreach ($pendingMigrations as $migration): ?>
                            <tr class="is-warning"><td class="col-num"><?= sprintf('%03d', $migration['version']) ?></td><td><code><?= $e($migration['name']) ?></code></td><td><span class="badge badge--warning">en attente</span></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($pendingMigrations !== []): ?>
                        <p class="ma-hint">Des migrations sont en attente : utilisez « Resynchroniser les manifestes » ou <code>console db:migrate</code>.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card__header">
            <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-activity"></use></svg>Erreurs et refus récents</h2>
            <span class="text-muted"><?= (int) $activityCount ?> entrée(s) au total pour ce module</span>
        </div>
        <div class="card__body card__body--flush">
            <?php if ($errors === []): ?>
                <p class="table__empty">Aucune erreur, échec ni refus enregistré pour ce module.</p>
            <?php else: ?>
                <table class="table table--compact">
                    <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Résultat</th><th>Ressource</th><th>Message</th><th>Réf.</th></tr></thead>
                    <tbody>
                    <?php foreach ($errors as $row): ?>
                        <tr>
                            <td class="text-nowrap"><?= $e(\Atelier\Support\Clock::formatDateTimeSeconds($row['occurred_at'])) ?></td>
                            <td><?= $e($row['username'] ?? '—') ?></td>
                            <td><code><?= $e($row['action']) ?></code></td>
                            <td><span class="badge badge--<?= $resultBadge((string) $row['result']) ?>"><?= $e($row['result']) ?></span></td>
                            <td><code><?= $e($row['resource_ref'] ?? '') ?></code></td>
                            <td class="truncate" style="max-width: 360px" title="<?= $e($row['message'] ?? '') ?>"><?= $e($row['message'] ?? '') ?></td>
                            <td><code><?= $e($row['error_id'] ?? '') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</div>
