<?php
/**
 * Fiche d'intervention imprimable : page HTML autonome (aucune ressource externe), ouverte dans un nouvel onglet.
 * @var array<string, mixed> $job (avec state)
 * @var array<string, mixed>|null $asset
 * @var list<array<string, mixed>> $logs
 * @var list<array<string, mixed>> $attachments
 * @var string $printedAt
 * @var array<string, mixed> $user
 * @var string $appName
 * @var \Atelier\Modules\Maintenance\MaintenanceModule $module
 */
$unit = $job['asset_meter_unit'];
$isCorrective = $job['kind'] === 'corrective';
$parts = $module->lines($job['parts']);
$contacts = $module->lines($job['contacts']);
$tools = $module->lines($job['tools']);
$checklist = static function (array $lines) use ($e): string {
    if ($lines === []) {
        return '<p class="muted">—</p>';
    }
    $html = '<ul class="check">';
    foreach ($lines as $line) {
        $html .= '<li><span class="box"></span>' . $e($line) . '</li>';
    }
    return $html . '</ul>';
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Fiche d’intervention · <?= $e($job['title']) ?></title>
    <meta name="robots" content="noindex">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { font: 12pt/1.45 "Segoe UI", Arial, sans-serif; color: #111; margin: 0; padding: 18mm 16mm; background: #fff; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 14px; }
        header h1 { font-size: 20pt; margin: 0 0 2px; }
        header .meta { font-size: 10pt; color: #555; }
        .tag { display: inline-block; border: 1px solid #111; border-radius: 3px; padding: 1px 6px; font-size: 9pt; margin-left: 6px; vertical-align: middle; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; margin: 0 0 14px; }
        .grid div { padding: 3px 0; border-bottom: 1px dotted #bbb; }
        .grid b { display: inline-block; min-width: 9em; color: #444; font-weight: 600; }
        h2 { font-size: 13pt; margin: 16px 0 6px; padding-bottom: 2px; border-bottom: 1px solid #999; }
        .cols { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0 20px; }
        ul.check { list-style: none; padding: 0; margin: 0; }
        ul.check li { display: flex; align-items: flex-start; gap: 8px; padding: 3px 0; }
        .box { display: inline-block; width: 12px; height: 12px; border: 1.5px solid #111; border-radius: 2px; flex: none; margin-top: 3px; }
        .desc { white-space: normal; }
        .desc ul, .desc ol { margin: 4px 0; }
        .muted { color: #777; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5pt; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #ccc; vertical-align: top; }
        th { background: #f0f0f0; }
        .notes-area { border: 1px solid #999; height: 60mm; margin-top: 6px; border-radius: 3px; }
        .sign { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-top: 10px; font-size: 10pt; }
        .sign div { border-top: 1px solid #111; padding-top: 4px; }
        footer { margin-top: 18px; font-size: 9pt; color: #666; display: flex; justify-content: space-between; }
        .toolbar { position: fixed; top: 8px; right: 8px; }
        .toolbar button { font: inherit; padding: 6px 12px; cursor: pointer; }
        @media print { .toolbar { display: none; } body { padding: 0; } @page { margin: 14mm; } }
    </style>
</head>
<body>
<div class="toolbar"><button type="button" id="maintenance-print">Imprimer</button></div>
<script src="<?= $e($module->assetUrl('assets/print.js')) ?>" defer></script>
<header>
    <div>
        <h1><?= $e($job['title']) ?><span class="tag"><?= $isCorrective ? 'Panne' : 'Entretien' ?></span><span class="tag">Priorité <?= $e(strtolower(\Atelier\Modules\Maintenance\JobRepository::PRIORITIES[$job['priority']] ?? $job['priority'])) ?></span></h1>
        <div class="meta"><?= $e($job['asset_name']) ?><?= $asset !== null && ($asset['brand'] !== null || $asset['model'] !== null) ? ' — ' . $e(trim(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? ''))) : '' ?><?= $asset !== null && $asset['identifier'] !== null ? ' — ' . $e($asset['identifier']) : '' ?></div>
    </div>
    <div class="meta" style="text-align:right">Fiche d’intervention n° <?= (int) $job['id'] ?><br><?= $e($appName) ?> · imprimée le <?= $e($printedAt) ?></div>
</header>

<div class="grid">
    <div><b>État</b> <?= $e($module->stateLabel($job['state'])) ?><?= $job['status'] === 'open' && $job['state']['code'] !== 'none' ? ' (' . $e($module->stateDetail($job['state'], $unit)) . ')' : '' ?></div>
    <div><b>Compteur actuel</b> <?= $e($module->meter($job['asset_meter_value'], $unit)) ?></div>
    <div><b><?= $isCorrective ? 'À traiter avant' : 'Échéance' ?></b> <?= $e($module->dueLabel($job['next_due_at'], $job['next_due_meter'], $unit)) ?></div>
    <div><b>Périodicité</b> <?= $isCorrective ? 'ponctuelle' : $e($module->intervalLabel($job['interval_days'], $job['interval_meter'], $unit)) ?></div>
    <div><b>Dernière fois</b> <?= $e($module->dueLabel($job['last_done_at'], $job['last_done_meter'], $unit)) ?></div>
    <div><b>Rappel anticipé</b> <?= (int) $job['lead_days'] ?> j<?= $job['lead_meter'] !== null ? ' / ' . $e($module->meter($job['lead_meter'], $unit)) : '' ?></div>
    <div><b>Durée estimée</b> <?= $e($module->duration($job['estimated_minutes'])) ?></div>
    <div><b>Coût estimé</b> <?= $e($module->money($job['estimated_cost'])) ?></div>
    <?php if ($asset !== null && $asset['location'] !== null): ?><div><b>Emplacement</b> <?= $e($asset['location']) ?></div><?php endif; ?>
</div>

<h2>Descriptif</h2>
<?php if ($job['description'] === null || trim((string) $job['description']) === ''): ?><p class="muted">—</p><?php else: ?><div class="desc"><?= $module->bbcode($job['description']) ?></div><?php endif; ?>

<div class="cols">
    <div><h2>Pièces et consommables</h2><?= $checklist($parts) ?></div>
    <div><h2>Contacts</h2><?= $checklist($contacts) ?></div>
    <div><h2>Outillage</h2><?= $checklist($tools) ?></div>
</div>

<?php if ($attachments !== []): ?>
    <h2>Documents joints</h2>
    <ul><?php foreach ($attachments as $file): ?><li><?= $e($file['original_name']) ?><?= !empty($file['description']) ? ' — ' . $e($file['description']) : '' ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<?php if ($logs !== []): ?>
    <h2>Réalisations précédentes</h2>
    <table>
        <thead><tr><th>Date</th><?php if ($unit !== null): ?><th>Compteur</th><?php endif; ?><th>Intervenant</th><th>Coût</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr><td><?= $e($module->day($log['done_at'])) ?></td><?php if ($unit !== null): ?><td><?= $e($module->meter($log['meter_value'], $unit)) ?></td><?php endif; ?><td><?= $e($log['performed_by'] ?? '—') ?></td><td><?= $e($module->money($log['cost'])) ?></td><td><?= $e(\Atelier\Support\Str::truncate(\Atelier\View\BbCode::toText($log['notes']), 120)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Compte rendu de l’intervention</h2>
<div class="notes-area"></div>
<div class="sign">
    <div>Date de réalisation</div>
    <div>Compteur<?= $unit !== null ? ' (' . $e($unit) . ')' : '' ?></div>
    <div>Coût réel · intervenant</div>
</div>

<footer><span>Fiche générée par <?= $e($appName) ?> pour <?= $e($user['display_name'] ?? $user['username'] ?? '') ?></span><span>Module Entretien</span></footer>
</body>
</html>
