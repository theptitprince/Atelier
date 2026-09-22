<?php
/** @var array $user @var list<\Atelier\Modules\ModuleDescriptor> $modules @var list<array> $recent @var array|null $stats @var string $lastLogin */
?>
<div class="module module-home">
    <div class="home__intro">
        <h1>Bienvenue, <?= $e($user['display_name']) ?></h1>
        <p class="text-muted">Dernière connexion : <?= $e($lastLogin) ?>. Sélectionnez un module ci-dessous ou dans la colonne de gauche.</p>
    </div>

    <?php if ($stats !== null): ?>
        <div class="home__stats">
            <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value"><?= (int) $stats['users'] ?></span><span class="kpi__label">comptes actifs</span></div></div>
            <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value"><?= (int) $stats['modules'] ?></span><span class="kpi__label">modules installés<?= $stats['modulesInError'] ? ' <span class="badge badge--danger">' . (int) $stats['modulesInError'] . ' en erreur</span>' : '' ?></span></div></div>
            <div class="card card--compact"><div class="card__body kpi"><span class="kpi__value"><?= $e(\Atelier\Support\Str::humanSize((int) $stats['attachments'])) ?></span><span class="kpi__label">pièces jointes</span></div></div>
        </div>
    <?php endif; ?>

    <h2 class="mt-4">Modules</h2>
    <?php if ($modules === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun module accessible', 'message' => 'Demandez à un administrateur de vous attribuer des droits.']) ?>
    <?php else: ?>
        <div class="home__modules">
            <?php foreach ($modules as $descriptor): ?>
                <a class="card home__module" href="<?= $e($baseUrl) ?>/m/<?= $e($descriptor->id) ?>" data-open-module="<?= $e($descriptor->id) ?>">
                    <div class="card__body">
                        <div class="home__module-head">
                            <svg class="icon icon--lg" aria-hidden="true"><use href="#i-<?= $e($descriptor->icon()) ?>"></use></svg>
                            <span class="home__module-name"><?= $e($descriptor->name()) ?></span>
                        </div>
                        <p class="text-muted text-small mb-0"><?= $e($descriptor->description() ?: 'Aucune description.') ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="mt-5">Mon activité récente</h2>
    <?php if ($recent === []): ?>
        <p class="text-muted">Aucune action enregistrée pour le moment.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th>Date</th><th>Module</th><th>Action</th><th>Résultat</th><th>Détail</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td class="text-nowrap"><?= $e($datetime($row['occurred_at'])) ?></td>
                        <td><?= $e($row['module_id']) ?></td>
                        <td><code><?= $e($row['action']) ?></code></td>
                        <td><span class="badge badge--<?= $row['result'] === 'success' ? 'success' : ($row['result'] === 'denied' ? 'warning' : 'danger') ?>"><?= $e($row['result']) ?></span></td>
                        <td class="truncate" style="max-width: 420px"><?= $e($row['message'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
