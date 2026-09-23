<?php
/**
 * Gestion des flux suivis.
 * @var list<array<string, mixed>> $rows @var array<string, bool> $rights
 * @var array{last: ?string, stale: bool} $cron @var array<int, int> $localCopies @var array<int, string> $refreshChoices
 * @var \Atelier\Modules\News\NewsModule $module
 */
?>
<div class="module module-news">
    <?php if ($cron['stale']): ?>
        <div class="alert alert--warning" role="status"><svg class="icon" aria-hidden="true"><use href="#i-clock"></use></svg>
            <div>
                <p class="alert__title"><?= $cron['last'] === null ? 'Tâche de fond jamais exécutée' : 'Tâche de fond exécutée pour la dernière fois le ' . $e($datetime($cron['last'])) ?></p>
                <p class="mb-0">Planifiez <code>cron.bat</code> (Windows, Planificateur de tâches) ou <code>cron.sh</code> (crontab) toutes les 5 à 15 minutes : les flux sont alors récupérés selon leur fréquence et les articles copiés en local, sans dépendre de l’ouverture du fil.</p>
            </div>
        </div>
    <?php else: ?>
        <p class="text-muted text-small"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-clock"></use></svg> Tâche de fond : dernière exécution le <?= $e($datetime($cron['last'])) ?>.</p>
    <?php endif; ?>
    <?php if ($rows === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucun flux suivi', 'message' => 'Ajoutez l’adresse d’un flux RSS, Atom ou JSON Feed (titre, site et description sont lus automatiquement), ou partez des flux suggérés.', 'actions' => $rights['create'] ? '<button type="button" class="btn btn--sm" data-action="feed-suggest">Ajouter les flux suggérés</button> <a class="btn btn--sm btn--primary" href="#" data-route="feeds/new">Ajouter un flux</a>' : '']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table news__feeds">
                <thead><tr><th>Flux</th><th>Catégorie</th><th class="col-num">Entrées</th><th>Fréquence</th><th>Dernière récupération</th><th>État</th><th class="col-actions">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $feed): ?>
                    <tr class="<?= (int) $feed['is_active'] === 1 ? '' : 'is-disabled' ?><?= $feed['last_status'] === 'error' ? ' is-warning' : '' ?>">
                        <td>
                            <a href="#" data-route="feeds/edit/<?= (int) $feed['id'] ?>" class="news__feed-title"><?= $e($feed['title']) ?></a>
                            <span class="text-muted text-small truncate news__feed-url" title="<?= $e($feed['url']) ?>"><?= $e($feed['url']) ?></span>
                        </td>
                        <td><?= $feed['category_name'] !== null ? '<span class="badge"' . $module->colorStyle($feed['category_color']) . '>' . $e($feed['category_name']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="col-num"><?= (int) $feed['item_count'] ?><?php if ((int) $feed['fetch_content'] === 1): ?> <span class="text-muted text-small" title="Articles copiés en local">(<?= (int) ($localCopies[(int) $feed['id']] ?? 0) ?> en local)</span><?php endif; ?></td>
                        <td class="text-nowrap"><?= $e($refreshChoices[(int) $feed['refresh_minutes']] ?? $feed['refresh_minutes'] . ' min') ?> · <?= (int) $feed['retention_days'] ?> j</td>
                        <td class="text-nowrap"><?= $feed['last_fetched_at'] !== null ? $e($datetime($feed['last_fetched_at'])) : '<span class="text-muted">jamais</span>' ?></td>
                        <td>
                            <?php if ((int) $feed['is_active'] !== 1): ?><span class="badge badge--muted">suspendu</span>
                            <?php elseif ($feed['last_status'] === 'error'): ?><span class="badge badge--danger" title="<?= $e($feed['last_error'] ?? '') ?>">erreur</span>
                            <?php elseif ($feed['last_status'] === 'ok'): ?><span class="badge badge--success"><?= $e(strtoupper((string) ($feed['format'] ?? 'ok'))) ?></span>
                            <?php else: ?><span class="badge badge--muted">en attente</span><?php endif; ?>
                            <?php if ($feed['last_status'] === 'error' && $feed['last_error'] !== null): ?><span class="text-muted text-small news__feed-error"><?= $e(mb_substr((string) $feed['last_error'], 0, 120, 'UTF-8')) ?></span><?php endif; ?>
                        </td>
                        <td class="col-actions">
                            <span class="table-actions">
                                <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="feed-refresh" data-params='{"id":<?= (int) $feed['id'] ?>}' title="Récupérer maintenant"><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg></button>
                                <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="feeds/edit/<?= (int) $feed['id'] ?>" title="Modifier"><svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg></a>
                                <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="feed-toggle" data-params='{"id":<?= (int) $feed['id'] ?>}' title="<?= (int) $feed['is_active'] === 1 ? 'Suspendre' : 'Activer' ?>"><svg class="icon" aria-hidden="true"><use href="#i-power"></use></svg></button>
                                <?php if ($rights['delete']): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="feed-delete" data-params='{"id":<?= (int) $feed['id'] ?>}' data-confirm="Placer « <?= $e($feed['title']) ?> » dans la corbeille ? Ses entrées non archivées seront masquées ; les faits archivés restent consultables. Restaurable pendant 30 jours." data-danger title="Retirer (corbeille)"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button><?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted text-small">Les flux périmés sont récupérés automatiquement à l’ouverture du fil (trois au plus par affichage) ; « Actualiser » force la récupération.</p>
    <?php endif; ?>
</div>
