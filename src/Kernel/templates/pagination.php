<?php
/**
 * Pagination côté serveur.
 * Variables : $page, $perPage, $total, $route (route du module sans paramètre de page),
 *             $query (tableau des autres paramètres à conserver), $e.
 * Les liens utilisent data-route : le noyau charge la route dans l'onglet du module.
 */
$query ??= [];
$pages = max(1, (int) ceil($total / max(1, $perPage)));
$page = max(1, min($page, $pages));
$build = static function (int $p) use ($route, $query): string {
    $params = $query + ['page' => $p];
    return $route . '?' . http_build_query($params);
};
$from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to = min($total, $page * $perPage);
$window = [];
for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) {
    $window[] = $p;
}
?>
<nav class="pagination" aria-label="Pagination">
    <span><?= $total === 0 ? 'Aucun élément' : sprintf('%d–%d sur %d', $from, $to, $total) ?></span>
    <span class="pagination__spacer"></span>
    <?php if ($pages > 1): ?>
        <div class="pagination__pages" role="group">
            <a class="btn btn--sm btn--icon" href="#" data-route="<?= $e($build(1)) ?>" title="Première page" aria-label="Première page"<?= $page === 1 ? ' aria-disabled="true"' : '' ?>><svg class="icon" aria-hidden="true"><use href="#i-chevrons-left"></use></svg></a>
            <a class="btn btn--sm btn--icon" href="#" data-route="<?= $e($build(max(1, $page - 1))) ?>" title="Page précédente" aria-label="Page précédente"<?= $page === 1 ? ' aria-disabled="true"' : '' ?>><svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg></a>
            <?php foreach ($window as $p): ?>
                <a class="btn btn--sm<?= $p === $page ? ' is-active' : '' ?>" href="#" data-route="<?= $e($build($p)) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a>
            <?php endforeach; ?>
            <a class="btn btn--sm btn--icon" href="#" data-route="<?= $e($build(min($pages, $page + 1))) ?>" title="Page suivante" aria-label="Page suivante"<?= $page === $pages ? ' aria-disabled="true"' : '' ?>><svg class="icon" aria-hidden="true"><use href="#i-chevron-right"></use></svg></a>
            <a class="btn btn--sm btn--icon" href="#" data-route="<?= $e($build($pages)) ?>" title="Dernière page" aria-label="Dernière page"<?= $page === $pages ? ' aria-disabled="true"' : '' ?>><svg class="icon" aria-hidden="true"><use href="#i-chevrons-right"></use></svg></a>
        </div>
    <?php endif; ?>
</nav>
