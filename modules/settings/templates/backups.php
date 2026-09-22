<?php
/** @var list<array{name: string, size: int, created_at: string}> $backups @var string $directory @var bool $isSqlite */
?>
<div class="module module-settings">
    <?php if (!$isSqlite): ?>
        <div class="alert alert--warning"><svg class="icon" aria-hidden="true"><use href="#i-warning"></use></svg><div>La sauvegarde intégrée ne concerne que SQLite. Avec MariaDB, utilisez <code>mysqldump</code> et copiez le répertoire des pièces jointes.</div></div>
    <?php endif; ?>
    <p class="text-muted">Chaque sauvegarde regroupe une copie cohérente de la base, les pièces jointes et la configuration des modules dans <code><?= $e($directory) ?></code>. La restauration se fait depuis la console, serveur arrêté : <code>console backup:restore &lt;nom&gt; --force</code>.</p>

    <?php if ($backups === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucune sauvegarde', 'message' => 'Créez une première sauvegarde depuis le bandeau.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Nom</th><th>Date</th><th class="col-num">Taille</th><th>Commande de restauration</th></tr></thead>
                <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td class="mono"><?= $e($backup['name']) ?></td>
                        <td class="text-nowrap"><?= $e($datetime($backup['created_at'])) ?></td>
                        <td class="col-num"><?= $e(\Atelier\Support\Str::humanSize((int) $backup['size'])) ?></td>
                        <td><code>console backup:restore <?= $e($backup['name']) ?> --force</code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
