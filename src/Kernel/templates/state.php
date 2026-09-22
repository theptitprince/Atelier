<?php
/**
 * Bloc d'état de la zone centrale : absence de données, accès refusé, erreur, indisponible.
 * Variables : $type (empty|denied|error|unavailable|loading), $title, $message (optionnel), $actions (HTML, optionnel), $e.
 */
$message ??= '';
$actions ??= '';
$icons = ['empty' => 'folder', 'denied' => 'lock', 'error' => 'error', 'unavailable' => 'warning', 'loading' => 'loader'];
?>
<div class="state state--<?= $e($type) ?>" role="status">
    <svg class="icon icon--xl" aria-hidden="true"><use href="#i-<?= $e($icons[$type] ?? 'info') ?>"></use></svg>
    <p class="state__title"><?= $e($title) ?></p>
    <?php if ($message !== ''): ?><p class="state__message"><?= $e($message) ?></p><?php endif; ?>
    <?php if ($actions !== ''): ?><div class="state__actions"><?= $actions ?></div><?php endif; ?>
</div>
