<?php
/**
 * En-tête de colonne triable.
 * Variables : $label, $column, $sort (colonne courante), $direction (asc|desc), $route, $query (autres paramètres), $e.
 */
$query ??= [];
$isCurrent = $sort === $column;
$next = $isCurrent && $direction === 'asc' ? 'desc' : 'asc';
$target = $route . '?' . http_build_query($query + ['sort' => $column, 'dir' => $next, 'page' => 1]);
?>
<a class="th-sort<?= $isCurrent ? ' is-' . $e($direction) : '' ?>" href="#" data-route="<?= $e($target) ?>" aria-sort="<?= $isCurrent ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
    <?= $e($label) ?>
    <svg class="icon icon--sm" aria-hidden="true"><use href="#i-arrow-up"></use></svg>
</a>
