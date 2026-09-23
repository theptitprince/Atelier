<?php
/**
 * Liste des projets : recherche, filtre par tag, tri, sous-onglets par statut, cartes avec indicateurs.
 * @var list<array<string, mixed>> $rows @var array<string, int> $counts
 * @var string $q @var string $tag @var string $sort @var string $status @var array<string, string> $sorts
 * @var list<array{name: string, normalized: string, count: int}> $tagChoices
 * @var array<string, bool> $rights @var string $today
 * @var \Atelier\Modules\Project\ProjectModule $module
 */
$hasFilters = $q !== '' || $tag !== '';
$statuses = ['all' => 'Tous'] + \Atelier\Modules\Project\ProjectModule::STATUSES;
$tagRoute = static fn (string $normalized): string => 'list?' . http_build_query(array_filter(['q' => $q, 'tag' => $normalized, 'sort' => $sort !== 'updated' ? $sort : ''], static fn ($v): bool => $v !== ''));

/** Carte d'un projet. */
$card = static function (array $row) use ($module, $e, $tagRoute): string {
    ob_start(); ?>
    <article class="card project__card<?= $row['late'] ? ' project__card--late' : '' ?>" data-project-status="<?= $e($row['status']) ?>">
        <div class="card__body">
            <div class="project__card-head">
                <a class="project__title" href="#" data-route="show/<?= (int) $row['id'] ?>"><?= $e($row['title']) ?></a>
                <span class="badge badge--<?= $e($row['status_badge']) ?>"><?= $e($row['status_label']) ?></span>
            </div>
            <?php if ($row['excerpt'] !== ''): ?><p class="project__excerpt text-muted"><?= $e($row['excerpt']) ?></p><?php endif; ?>
            <?php if ($row['tags'] !== []): ?>
                <div class="chips mb-2"><?php foreach ($row['tags'] as $name): ?><a class="chip" href="#" data-route="<?= $e($tagRoute(\Atelier\Support\Str::normalizeTag($name))) ?>" title="Filtrer sur ce tag"><?= $e($name) ?></a><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($row['task_total'] > 0): ?>
                <div class="project__progress" title="<?= (int) $row['task_done'] ?> tâche(s) faite(s) sur <?= (int) $row['task_total'] ?>">
                    <progress class="project__bar" max="100" value="<?= (int) $row['progress'] ?>"></progress>
                    <span class="text-small text-muted"><?= (int) $row['task_done'] ?>/<?= (int) $row['task_total'] ?> tâches</span>
                </div>
            <?php endif; ?>
            <ul class="project__meta">
                <li title="Échéance"><?= $module->icon('calendar', 'icon--sm') ?> <?= $row['due_date'] !== null ? $e(\Atelier\Modules\Project\ProjectModule::day($row['due_date'])) : '<span class="text-muted">sans échéance</span>' ?><?= $row['late'] ? ' <span class="badge badge--danger">en retard</span>' : '' ?></li>
                <li title="Priorité"><?= $module->icon('sliders', 'icon--sm') ?> <?= $e($row['priority_label']) ?></li>
                <li title="Budget estimé"><?= $module->icon('book', 'icon--sm') ?> <?= $e(\Atelier\Modules\Project\ProjectModule::money($row['budget_estimate'], 'non chiffré')) ?></li>
                <li title="Éléments liés et documents"><?= $module->icon('link', 'icon--sm') ?> <?= (int) $row['linked_count'] ?> lié(s) · <?= $module->icon('paperclip', 'icon--sm') ?> <?= (int) $row['attachment_count'] ?></li>
            </ul>
        </div>
        <div class="card__footer text-small text-muted project__card-foot">
            <span>Mis à jour le <?= $e(\Atelier\Support\Clock::formatDate($row['updated_at'])) ?><?= $row['owner_name'] !== null ? ' · ' . $e($row['owner_name']) : '' ?></span>
            <a class="btn btn--sm btn--ghost" href="#" data-route="show/<?= (int) $row['id'] ?>"><?= $module->icon('eye') ?> Ouvrir</a>
        </div>
    </article>
    <?php return (string) ob_get_clean();
};
?>
<div class="module module-project">
    <form class="card card--compact" data-action="filter" data-auto-submit novalidate>
        <input type="hidden" name="tag" value="<?= $e($tag) ?>">
        <input type="hidden" name="status" value="<?= $e($status) ?>">
        <div class="card__body toolbar mb-0">
            <div class="field grow mb-0">
                <label class="sr-only" for="project-q">Recherche</label>
                <div class="input-icon"><?= $module->icon('search', 'icon--sm') ?><input class="input" type="search" id="project-q" name="q" value="<?= $e($q) ?>" placeholder="Rechercher un projet (titre, résumé, description)" autocomplete="off"></div>
            </div>
            <label class="field field--inline"><span class="field__label">Tri</span>
                <select class="select select--sm" name="sort" aria-label="Trier">
                    <?php foreach ($sorts as $code => $label): ?><option value="<?= $e($code) ?>"<?= $sort === $code ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn"><?= $module->icon('search') ?> Rechercher</button>
            <a class="btn btn--ghost" href="#" data-route="list"<?= $hasFilters ? '' : ' aria-disabled="true"' ?>>Effacer</a>
        </div>
    </form>

    <?php if ($tagChoices !== []): ?>
        <nav class="chips project__tags mb-3" aria-label="Filtrer par tag">
            <a class="chip<?= $tag === '' ? ' is-active' : '' ?>" href="#" data-route="<?= $e($tagRoute('')) ?>"><?= $module->icon('grid', 'icon--sm') ?> Tous</a>
            <?php foreach ($tagChoices as $choice): ?>
                <a class="chip<?= $tag === $choice['normalized'] ? ' is-active' : '' ?>" href="#" data-route="<?= $e($tagRoute($choice['normalized'])) ?>" title="<?= (int) $choice['count'] ?> projet(s)"><?= $module->icon('tag', 'icon--sm') ?> <?= $e($choice['name']) ?> <span class="project__tag-count"><?= (int) $choice['count'] ?></span></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="subtabs" role="tablist" data-subtabs="project-status-panels">
        <?php foreach ($statuses as $code => $label): ?>
            <button type="button" class="subtabs__tab" role="tab" data-subtab="<?= $e($code) ?>" aria-selected="<?= $status === $code ? 'true' : 'false' ?>"><?= $e($label) ?> <span class="badge badge--muted"><?= (int) ($counts[$code] ?? 0) ?></span></button>
        <?php endforeach; ?>
    </div>

    <div id="project-status-panels">
        <?php foreach ($statuses as $code => $label): ?>
            <?php $panelRows = $code === 'all' ? $rows : array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === $code)); ?>
            <section data-subtab-panel="<?= $e($code) ?>"<?= $status === $code ? '' : ' hidden' ?>>
                <?php if ($panelRows === []): ?>
                    <?= $module->renderCore('state', [
                        'type' => 'empty',
                        'title' => $hasFilters ? 'Aucun projet ne correspond' : ($code === 'all' ? 'Aucun projet' : 'Aucun projet « ' . $label . ' »'),
                        'message' => $hasFilters ? 'Modifiez la recherche ou le tag.' : 'Un projet regroupe tâches, journal de bord, documents et informations des autres modules : un objet à fabriquer, un voyage, un achat à documenter, des travaux…',
                        'actions' => $rights['create'] && !$hasFilters ? '<a class="btn btn--sm btn--primary" href="#" data-route="new">Nouveau projet</a>' : '',
                    ]) ?>
                <?php else: ?>
                    <div class="cards project__cards">
                        <?php foreach ($panelRows as $row): ?><?= $card($row) ?><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
</div>
