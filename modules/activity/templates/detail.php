<?php
/**
 * Détail d'une entrée du journal (lecture seule).
 * @var array<string, mixed> $entry
 * @var string|null $detailsPretty
 * @var array<string, array{0: string, 1: string}> $results
 * @var string $occurredAt
 */
[$resultLabel, $resultTone] = $results[$entry['result']] ?? [(string) $entry['result'], 'muted'];
$dash = '<span class="text-muted">—</span>';
$filterLink = static fn (array $params, string $label): string => '<a class="btn btn--sm" href="#" data-route="' . $e('list?' . http_build_query($params)) . '">'
    . '<svg class="icon" aria-hidden="true"><use href="#i-filter"></use></svg> ' . $e($label) . '</a>';
?>
<div class="module module-activity">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">
                <svg class="icon" aria-hidden="true"><use href="#i-activity"></use></svg>
                Entrée n° <?= (int) $entry['id'] ?>
            </h2>
            <span class="badge badge--<?= $e($resultTone) ?>"><?= $e($resultLabel) ?></span>
        </div>
        <div class="card__body">
            <dl class="dl activity__detail">
                <dt>Date</dt>
                <dd><?= $e($occurredAt) ?> <span class="text-muted text-small">(UTC : <?= $e($entry['occurred_at']) ?>)</span></dd>

                <dt>Utilisateur</dt>
                <dd>
                    <?php if ($entry['username'] !== null && $entry['username'] !== ''): ?>
                        <?= $e($entry['username']) ?><?= $entry['user_id'] !== null ? ' <span class="text-muted">(id ' . (int) $entry['user_id'] . ')</span>' : '' ?>
                    <?php else: ?>
                        <?= $dash ?> <span class="text-muted">(anonyme ou système)</span>
                    <?php endif; ?>
                </dd>

                <dt>Module</dt>
                <dd><?= $e($entry['module_id']) ?></dd>

                <dt>Action</dt>
                <dd><code><?= $e($entry['action']) ?></code></dd>

                <dt>Résultat</dt>
                <dd><span class="badge badge--<?= $e($resultTone) ?>"><?= $e($resultLabel) ?></span> <span class="text-muted">(<?= $e($entry['result']) ?>)</span></dd>

                <dt>Ressource</dt>
                <dd><?= $entry['resource_ref'] !== null && $entry['resource_ref'] !== '' ? '<code>' . $e($entry['resource_ref']) . '</code>' : $dash ?></dd>

                <dt>Message</dt>
                <dd><?= $entry['message'] !== null && $entry['message'] !== '' ? $e($entry['message']) : $dash ?></dd>

                <dt>Adresse IP</dt>
                <dd><?= $entry['ip'] !== null && $entry['ip'] !== '' ? '<code>' . $e($entry['ip']) . '</code>' : $dash ?></dd>

                <dt>Référence d’erreur</dt>
                <dd><?= $entry['error_id'] !== null && $entry['error_id'] !== '' ? '<code>' . $e($entry['error_id']) . '</code>' : $dash ?></dd>

                <dt>Détails</dt>
                <dd>
                    <?php if ($detailsPretty !== null): ?>
                        <pre class="activity__details mono"><?= $e($detailsPretty) ?></pre>
                    <?php else: ?>
                        <?= $dash ?>
                    <?php endif; ?>
                </dd>
            </dl>
        </div>
        <div class="card__footer">
            <div class="toolbar mb-0">
                <?php if ($entry['user_id'] !== null): ?>
                    <?= $filterLink(['user_id' => (int) $entry['user_id']], 'Filtrer sur cet utilisateur') ?>
                <?php endif; ?>
                <?= $filterLink(['module' => (string) $entry['module_id']], 'Filtrer sur ce module') ?>
                <?= $filterLink(['action' => (string) $entry['action']], 'Filtrer sur cette action') ?>
                <span class="toolbar__spacer"></span>
                <a class="btn btn--sm btn--ghost" href="#" data-route="list">
                    <svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg> Retour au journal
                </a>
            </div>
        </div>
    </div>
    <p class="text-muted text-small">Les entrées du journal ne peuvent être ni modifiées ni supprimées ; seule la purge de rétention les retire.</p>
</div>
