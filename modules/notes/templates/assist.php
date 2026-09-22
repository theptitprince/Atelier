<?php
/**
 * Assistance : lecture seule des notes d'un autre utilisateur (chaque consultation est journalisée).
 * Variables : $users (list), $target (array|null), $notes (list), $note (array|null), $tags (list<string>),
 *             $total, $page, $perPage, $module, $e, $datetime.
 */
$targetId = $target === null ? 0 : (int) $target['id'];
?>
<div class="module module-notes">
    <div class="alert alert--warning" role="status">
        <svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg>
        <div>
            <p class="alert__title mb-0">Mode assistance — lecture seule</p>
            Chaque consultation est inscrite au journal d’activité (<code>notes.assist_read</code>). Aucune modification n’est possible depuis cette vue.
        </div>
    </div>

    <div class="toolbar">
        <div class="field field--inline">
            <label class="field__label" for="assist-user">Utilisateur</label>
            <select class="select notes__user-select" id="assist-user" data-route-select>
                <option value="assist"<?= $targetId === 0 ? ' selected' : '' ?>>— Choisir un utilisateur —</option>
                <?php foreach ($users as $user): ?>
                    <option value="assist?user=<?= (int) $user['id'] ?>"<?= (int) $user['id'] === $targetId ? ' selected' : '' ?>>
                        <?= $e($user['display_name']) ?> (<?= $e($user['username']) ?>)<?= ($user['status'] ?? 'active') !== 'active' ? ' — désactivé' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($note !== null): ?>
            <a class="btn" href="#" data-route="assist?user=<?= $targetId ?>">
                <svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg> Toutes les notes de <?= $e($target['display_name']) ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($target === null): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Sélectionnez un utilisateur', 'message' => 'Ses notes s’afficheront ici, en lecture seule.']) ?>

    <?php elseif ($note !== null): ?>
        <article class="card notes__reader">
            <div class="card__header">
                <h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-note"></use></svg> <?= $e($note['title']) ?></h2>
                <span class="badge badge--warning">Lecture seule</span>
            </div>
            <div class="card__body">
                <?php if ($tags !== []): ?>
                    <div class="chips mb-3">
                        <?php foreach ($tags as $tag): ?>
                            <span class="chip"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg><?= $e($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (trim((string) ($note['content'] ?? '')) === ''): ?>
                    <p class="text-muted"><em>Cette note ne contient aucun texte.</em></p>
                <?php else: ?>
                    <div class="notes__reader-content prose"><?= \Atelier\View\BbCode::toHtml((string) $note['content']) ?></div>
                <?php endif; ?>
                <dl class="dl notes__meta">
                    <dt>Propriétaire</dt><dd><?= $e($target['display_name']) ?> (<?= $e($target['username']) ?>)</dd>
                    <dt>Créée le</dt><dd><?= $e($datetime($note['created_at'])) ?></dd>
                    <dt>Modifiée le</dt><dd><?= $e($datetime($note['updated_at'])) ?></dd>
                </dl>
            </div>
        </article>

    <?php elseif ($notes === []): ?>
        <?= $module->renderCore('state', ['type' => 'empty', 'title' => 'Aucune note', 'message' => $target['display_name'] . ' n’a aucune note active.']) ?>

    <?php else: ?>
        <div class="table-wrap">
            <table class="table notes__table">
                <thead>
                <tr>
                    <th class="notes__col-title">Titre</th>
                    <th>Extrait</th>
                    <th class="notes__col-tags">Tags</th>
                    <th class="notes__col-date">Modifiée le</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($notes as $row): ?>
                    <tr>
                        <td class="notes__col-title">
                            <a class="notes__title" href="#" data-route="assist?user=<?= $targetId ?>&amp;note=<?= (int) $row['id'] ?>">
                                <svg class="icon icon--sm" aria-hidden="true"><use href="#i-eye"></use></svg>
                                <span><?= $e($row['title']) ?></span>
                            </a>
                        </td>
                        <td class="notes__excerpt text-muted"><?= $row['excerpt'] === '' ? '<em>Note vide</em>' : $e($row['excerpt']) ?></td>
                        <td class="notes__col-tags">
                            <?php if ($row['tags'] !== []): ?>
                                <span class="chips"><?php foreach ($row['tags'] as $tag): ?><span class="chip"><?= $e($tag) ?></span><?php endforeach; ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="notes__col-date text-nowrap"><?= $e($datetime($row['updated_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $module->renderCore('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'route' => 'assist', 'query' => ['user' => $targetId]]) ?>
    <?php endif; ?>
</div>
