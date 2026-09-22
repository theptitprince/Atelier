<?php
/**
 * Composant optionnel de bandeau standard, utilisable par les modules :
 *   $this->renderCore('banner', ['icon' => 'note', 'title' => 'Bloc-notes', 'subtitle' => '12 notes', 'actions' => '<button ...>'])
 * Le module reste libre de fournir son propre HTML.
 * Variables : $icon, $title, $subtitle (optionnel), $actions (HTML, optionnel), $busy (optionnel, texte), $e.
 */
$icon ??= 'module';
$subtitle ??= null;
$actions ??= '';
$busy ??= 'Traitement en cours…';
?>
<div class="banner__title">
    <svg class="icon icon--lg" aria-hidden="true"><use href="#i-<?= $e($icon) ?>"></use></svg>
    <span><?= $e($title) ?></span>
</div>
<?php if ($subtitle !== null && $subtitle !== ''): ?>
    <span class="banner__subtitle" data-banner-subtitle><?= $e($subtitle) ?></span>
<?php endif; ?>
<span class="banner__busy" data-banner-busy hidden>
    <span class="spinner" aria-hidden="true"></span><span><?= $e($busy) ?></span>
</span>
<span class="banner__spacer"></span>
<?php if ($actions !== ''): ?>
    <div class="banner__actions"><?= $actions ?></div>
<?php endif; ?>
