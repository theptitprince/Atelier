<?php
/**
 * Fiche d'un projet : en-tête (statut, dates, priorité, budget), description, tâches, journal de bord,
 * documents, éléments liés (groupés par module), lieux GPS, budget, tags.
 * @var array<string, mixed> $project @var string $html @var list<array<string, mixed>> $tasks @var list<array<string, mixed>> $notes
 * @var list<array<string, mixed>> $tags @var list<array<string, mixed>> $attachments @var list<string> $inlineMimes
 * @var array{groups: list<array<string, mixed>>, hidden: int, count: int} $linked @var list<array<string, mixed>> $points
 * @var bool $geoAvailable @var bool $mapModule @var array{available: bool, rows: list<array<string, mixed>>, total: ?int} $budget
 * @var array<string, bool> $rights @var string|null $infoId @var array<string, string> $relationTypes
 * @var array<string, string> $statuses @var array<string, string> $statusBadges @var array<int, string> $priorities
 * @var bool $explorerModule @var string $today @var string $baseUrl
 * @var \Atelier\Modules\Project\ProjectModule $module
 */
$id = (int) $project['id'];
$canUpdate = (bool) $rights['update'];
$money = static fn (?int $cents, string $empty = '—'): string => \Atelier\Modules\Project\ProjectModule::money($cents, $empty);
$day = static fn (?string $d, string $empty = '—'): string => \Atelier\Modules\Project\ProjectModule::day($d, $empty);
$params = static fn (array $extra = []): string => $e(json_encode(['id' => $id] + $extra, JSON_UNESCAPED_UNICODE));
?>
<div class="module module-project">
    <div class="project__layout">
        <div class="project__main">
            <section class="card project__head">
                <div class="card__body">
                    <div class="project__status-row">
                        <span class="badge badge--<?= $e($project['status_badge']) ?> project__status-badge"><?= $e($project['status_label']) ?></span>
                        <?php if ($canUpdate): ?>
                            <div class="btn-group" role="group" aria-label="Changer le statut">
                                <?php foreach ($statuses as $code => $label): ?>
                                    <button type="button" class="btn btn--sm<?= $project['status'] === $code ? ' is-active' : '' ?>" data-action="set-status" data-params='<?= $params(['status' => $code]) ?>'<?= $project['status'] === $code ? ' disabled aria-pressed="true"' : '' ?>><?= $e($label) ?></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (($project['summary'] ?? '') !== ''): ?><p class="project__summary"><?= $e($project['summary']) ?></p><?php endif; ?>
                    <dl class="project__facts">
                        <div><dt>Début</dt><dd><?= $e($day($project['start_date'], 'non fixé')) ?></dd></div>
                        <div><dt>Échéance</dt><dd><?= $e($day($project['due_date'], 'non fixée')) ?><?= $project['late'] ? ' <span class="badge badge--danger">en retard</span>' : '' ?></dd></div>
                        <div><dt>Priorité</dt><dd><?= $e($project['priority_label']) ?></dd></div>
                        <div><dt>Budget estimé</dt><dd><?= $e($money($project['budget_estimate'], 'non chiffré')) ?></dd></div>
                        <div><dt>Responsable</dt><dd><?= $project['owner_name'] !== null ? $e($project['owner_name']) : '<span class="text-muted">—</span>' ?></dd></div>
                        <div><dt>Avancement</dt><dd><?= $project['task_total'] > 0 ? (int) $project['task_done'] . '/' . (int) $project['task_total'] . ' tâches (' . (int) $project['progress'] . ' %)' : '<span class="text-muted">aucune tâche</span>' ?></dd></div>
                    </dl>
                </div>
            </section>

            <section class="card">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('file') ?> Description</h2></div>
                <div class="card__body prose project__description">
                    <?= $html !== '' ? $html : '<p class="text-muted mb-0">Aucune description.' . ($canUpdate ? ' <a href="#" data-route="edit/' . $id . '">Rédiger</a>' : '') . '</p>' ?>
                </div>
            </section>

            <section class="card" id="project-tasks">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('check') ?> Tâches</h2><span class="badge badge--muted"><?= (int) $project['task_done'] ?>/<?= count($tasks) ?></span></div>
                <div class="card__body">
                    <?php if ($tasks === []): ?><p class="text-muted<?= $canUpdate ? '' : ' mb-0' ?>">Aucune tâche.</p><?php else: ?>
                        <ul class="list project__tasks<?= $canUpdate ? ' mb-3' : '' ?>">
                            <?php foreach ($tasks as $i => $task): ?>
                                <li class="list__item project__task<?= $task['done'] ? ' is-done' : '' ?><?= $task['late'] ? ' is-late' : '' ?>">
                                    <?php if ($canUpdate): ?>
                                        <button type="button" class="btn btn--sm btn--icon<?= $task['done'] ? ' btn--primary' : '' ?>" data-action="task-toggle" data-params='<?= $params(['task_id' => (int) $task['id']]) ?>' title="<?= $task['done'] ? 'Rouvrir' : 'Marquer comme faite' ?>" aria-pressed="<?= $task['done'] ? 'true' : 'false' ?>"><?= $module->icon('check') ?></button>
                                    <?php else: ?>
                                        <span class="project__task-mark"><?= $module->icon($task['done'] ? 'check' : 'clock', 'text-muted') ?></span>
                                    <?php endif; ?>
                                    <span class="grow project__task-title"><?= $e($task['title']) ?></span>
                                    <?php if ($task['due_date'] !== null): ?><span class="text-small text-nowrap<?= $task['late'] ? ' text-danger' : ' text-muted' ?>" title="Échéance"><?= $module->icon('calendar', 'icon--sm') ?> <?= $e($day($task['due_date'])) ?></span><?php endif; ?>
                                    <?php if ($canUpdate): ?>
                                        <span class="table-actions">
                                            <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="task-move" data-params='<?= $params(['task_id' => (int) $task['id'], 'direction' => 'up']) ?>' title="Monter"<?= $i === 0 ? ' disabled' : '' ?>><?= $module->icon('arrow-up') ?></button>
                                            <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="task-move" data-params='<?= $params(['task_id' => (int) $task['id'], 'direction' => 'down']) ?>' title="Descendre"<?= $i === count($tasks) - 1 ? ' disabled' : '' ?>><?= $module->icon('arrow-down') ?></button>
                                            <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="task-delete" data-params='<?= $params(['task_id' => (int) $task['id']]) ?>' data-confirm="Supprimer la tâche « <?= $e($task['title']) ?> » ?" data-danger title="Supprimer"><?= $module->icon('close') ?></button>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($canUpdate): ?>
                        <form class="project__task-form" data-action="task-add" novalidate>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <div class="field grow mb-0">
                                <label class="sr-only" for="project-task-title">Nouvelle tâche</label>
                                <input class="input input--sm" type="text" id="project-task-title" name="title" maxlength="200" placeholder="Nouvelle tâche… (Entrée pour ajouter)" autocomplete="off" required>
                                <span class="field__error"></span>
                            </div>
                            <div class="field mb-0">
                                <label class="sr-only" for="project-task-due">Échéance</label>
                                <input class="input input--sm" type="date" id="project-task-due" name="due_date" title="Échéance (facultative)">
                                <span class="field__error"></span>
                            </div>
                            <button type="submit" class="btn btn--sm"><?= $module->icon('plus') ?> Ajouter</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card" id="project-journal">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('note') ?> Journal de bord</h2><span class="badge badge--muted"><?= count($notes) ?></span></div>
                <div class="card__body">
                    <?php if ($canUpdate): ?>
                        <form class="project__note-form mb-3" data-action="note-add" novalidate>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <div class="field">
                                <label class="sr-only" for="project-note-content">Nouvelle note</label>
                                <textarea class="textarea" id="project-note-content" name="content" rows="4" maxlength="10000" data-editor="bbcode" placeholder="Où en est le projet ? Décision, avancée, problème rencontré…"></textarea>
                                <span class="field__error"></span>
                            </div>
                            <div class="form-actions form-actions--end mt-0"><button type="submit" class="btn btn--sm btn--primary"><?= $module->icon('plus') ?> Ajouter au journal</button></div>
                        </form>
                    <?php endif; ?>
                    <?php if ($notes === []): ?><p class="text-muted mb-0">Le journal est vide.</p><?php else: ?>
                        <ol class="project__journal">
                            <?php foreach ($notes as $note): ?>
                                <li class="project__entry">
                                    <div class="project__entry-head text-small text-muted">
                                        <time datetime="<?= $e(\Atelier\Support\Clock::iso($note['created_at'])) ?>"><?= $e($datetime($note['created_at'])) ?></time><?= $note['author_name'] !== null ? ' · ' . $e($note['author_name']) : '' ?>
                                        <?php if ($canUpdate): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="note-delete" data-params='<?= $params(['note_id' => (int) $note['id']]) ?>' data-confirm="Supprimer cette note du journal ?" data-danger title="Supprimer"><?= $module->icon('close') ?></button><?php endif; ?>
                                    </div>
                                    <div class="prose project__entry-body"><?= $note['html'] ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <aside class="project__side">
            <section class="card card--compact">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('tag') ?> Tags</h2></div>
                <div class="card__body"><?php if ($tags === []): ?><p class="text-muted mb-0">Aucun tag<?= $canUpdate ? ' — <a href="#" data-route="edit/' . $id . '">ajouter</a>' : '' ?>.</p><?php else: ?><div class="chips"><?php foreach ($tags as $tag): ?><a class="chip" href="#" data-route="list?tag=<?= $e(rawurlencode((string) $tag['normalized'])) ?>" title="Tous les projets portant ce tag"><?= $e($tag['name']) ?></a><?php endforeach; ?></div><?php endif; ?></div>
            </section>

            <section class="card card--compact" id="project-documents">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('paperclip') ?> Documents</h2><span class="badge badge--muted"><?= count($attachments) ?></span></div>
                <div class="card__body">
                    <?php if ($attachments === []): ?><p class="text-muted<?= $canUpdate ? '' : ' mb-0' ?>">Aucun document.</p><?php else: ?>
                        <ul class="list<?= $canUpdate ? ' mb-3' : '' ?>">
                            <?php foreach ($attachments as $file): ?>
                                <?php $isImage = str_starts_with((string) $file['mime'], 'image/'); $inline = in_array($file['mime'], $inlineMimes, true); $name = \Atelier\Shared\AttachmentService::displayName($file); ?>
                                <li class="list__item">
                                    <?= $module->icon($isImage ? 'image' : 'file', 'text-muted') ?>
                                    <span class="grow truncate">
                                        <a href="<?= $e($baseUrl . '/files/' . $file['id']) ?>" download title="Télécharger <?= $e($file['original_name']) ?>"><?= $e($name) ?></a>
                                        <?php if ($name !== $file['original_name']): ?><span class="text-muted text-small">· <?= $e($file['original_name']) ?></span><?php endif; ?>
                                    </span>
                                    <span class="text-muted text-small text-nowrap"><?= $e(\Atelier\Support\Str::humanSize((int) $file['size'])) ?></span>
                                    <?php if ($inline): ?><a class="btn btn--sm btn--icon btn--ghost" href="<?= $e($baseUrl . '/files/' . $file['id'] . '?inline=1') ?>" target="_blank" rel="noopener" title="Ouvrir dans un nouvel onglet"><?= $module->icon('eye') ?></a><?php endif; ?>
                                    <?php if ($canUpdate): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="attachment-delete" data-params='<?= $params(['attachment_id' => (string) $file['id']]) ?>' data-confirm="Retirer « <?= $e($name) ?> » ? Le fichier passe dans la corbeille." data-danger title="Retirer"><?= $module->icon('close') ?></button><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($canUpdate): ?>
                        <form class="project__attach-form" data-action="attach" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <div class="field mb-2">
                                <label class="sr-only" for="project-attach-files">Fichiers à joindre</label>
                                <div class="input-group">
                                    <input class="input input--sm" type="file" id="project-attach-files" name="files[]" multiple>
                                    <button type="submit" class="btn btn--sm" title="Joindre les fichiers choisis"><?= $module->icon('upload') ?> Joindre</button>
                                </div>
                                <span class="field__error"></span>
                            </div>
                            <div class="field mb-0">
                                <label class="sr-only" for="project-attach-label">Libellé</label>
                                <input class="input input--sm" type="text" id="project-attach-label" name="label" maxlength="200" placeholder="Libellé (devis, plan, billet…) — facultatif">
                                <span class="field__error"></span>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card card--compact" id="project-linked">
                <div class="card__header"><h2 class="card__title"><?= $module->icon('link') ?> Éléments liés</h2><span class="badge badge--muted"><?= (int) $linked['count'] ?></span></div>
                <div class="card__body">
                    <?php if ($linked['groups'] === []): ?><p class="text-muted<?= $canUpdate ? '' : ' mb-0' ?>">Aucun élément relié.</p><?php else: ?>
                        <?php foreach ($linked['groups'] as $group): ?>
                            <h4 class="project__group-title"><?= $module->icon($group['icon'], 'icon--sm') ?> <?= $e($group['module_name']) ?></h4>
                            <?php foreach ($group['datasets'] as $dataset): ?>
                                <ul class="list mb-2">
                                    <?php foreach ($dataset['items'] as $item): ?>
                                        <li class="list__item">
                                            <span class="grow truncate">
                                                <?php if ($item['open_module'] !== null): ?>
                                                    <a href="#" data-open-module="<?= $e($item['open_module']) ?>" data-open-route="<?= $e($item['open_route']) ?>" title="Ouvrir dans <?= $item['open_module'] === 'explorer' ? 'l’Explorateur' : $e($group['module_name']) ?>"><?= $e($item['label']) ?></a>
                                                <?php else: ?>
                                                    <?= $e($item['label']) ?>
                                                <?php endif; ?>
                                                <span class="text-muted text-small">· <?= $e($dataset['name']) ?> · <?= $e($item['type_label']) ?><?= $item['comment'] !== null ? ' · ' . $e($item['comment']) : '' ?></span>
                                            </span>
                                            <?php if ($canUpdate): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="unlink" data-params='<?= $params(['relation_id' => (int) $item['relation_id']]) ?>' data-confirm="Retirer « <?= $e($item['label']) ?> » du projet ? L’information elle-même n’est pas supprimée." title="Retirer du projet"><?= $module->icon('close') ?></button><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($linked['hidden'] > 0): ?><p class="text-small text-muted"><?= (int) $linked['hidden'] ?> relation(s) vers des informations non accessibles ne sont pas affichées.</p><?php endif; ?>
                    <?php if ($canUpdate): ?>
                        <form class="project__link-form" data-action="link" novalidate>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <div class="field mb-2">
                                <label class="field__label text-small" for="project-lookup">Relier une information</label>
                                <div class="input-icon"><?= $module->icon('search', 'icon--sm') ?><input class="input input--sm" type="search" id="project-lookup" placeholder="Rechercher dans tous les modules (page, lieu, opération, équipement…)" autocomplete="off" data-project-lookup data-exclude="<?= $e((string) $infoId) ?>"></div>
                                <span class="field__help" data-project-lookup-hint>Seules les informations des jeux partagés que vous pouvez lire sont proposées.</span>
                            </div>
                            <div class="field mb-2">
                                <label class="sr-only" for="project-link-to">Cible</label>
                                <select class="select select--sm" id="project-link-to" name="to" data-project-target><option value="">— Recherchez une information ci-dessus —</option></select>
                                <span class="field__error"></span>
                            </div>
                            <div class="field mb-2 project__link-type">
                                <label class="sr-only" for="project-link-type">Type de relation</label>
                                <select class="select select--sm" id="project-link-type" name="type"><?php foreach ($relationTypes as $code => $label): ?><option value="<?= $e($code) ?>"<?= $code === 'part_of' ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                                <span class="field__error"></span>
                                <button type="submit" class="btn btn--sm btn--primary"><?= $module->icon('link') ?> Relier</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($geoAvailable || $points !== []): ?>
                <section class="card card--compact" id="project-places">
                    <div class="card__header"><h2 class="card__title"><?= $module->icon('map-pin') ?> Lieux</h2><span class="badge badge--muted"><?= count($points) ?></span></div>
                    <div class="card__body">
                        <?php if ($points === []): ?><p class="text-muted mb-0">Aucun lieu rattaché<?= $canUpdate ? ' — reliez un point GPS ci-dessus' : '' ?>.</p><?php else: ?>
                            <ul class="list mb-0">
                                <?php foreach ($points as $point): ?>
                                    <li class="list__item">
                                        <span class="grow"><a href="#" data-open-module="geo" data-open-route="show/<?= (int) $point['id'] ?>"><?= $e($point['label']) ?></a><br><span class="mono text-muted text-small"><?= $e($point['dms']) ?></span></span>
                                        <?php if ($mapModule): ?><a class="btn btn--sm btn--icon btn--ghost" href="#" data-open-module="map" data-open-route="index?point=<?= (int) $point['id'] ?>" title="Voir sur la carte"><?= $module->icon('map') ?></a><?php endif; ?>
                                        <?php if ($canUpdate && ($point['relation_id'] ?? null) !== null): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="unlink" data-params='<?= $params(['relation_id' => (int) $point['relation_id']]) ?>' data-confirm="Détacher ce lieu du projet ?" title="Détacher"><?= $module->icon('close') ?></button><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($budget['available']): ?>
                <section class="card card--compact" id="project-budget">
                    <div class="card__header"><h2 class="card__title"><?= $module->icon('book') ?> Budget</h2><span class="badge badge--muted"><?= count($budget['rows']) ?></span></div>
                    <div class="card__body">
                        <dl class="project__facts project__facts--compact">
                            <div><dt>Estimé</dt><dd><?= $e($money($project['budget_estimate'], 'non chiffré')) ?></dd></div>
                            <div><dt>Opérations reliées</dt><dd><?= $budget['total'] !== null ? $e($money($budget['total'])) : count($budget['rows']) . ' (montants non exposés par le module Budget)' ?></dd></div>
                        </dl>
                        <?php if ($budget['rows'] === []): ?><p class="text-muted mb-0">Aucune opération reliée<?= $canUpdate ? ' — recherchez-la ci-dessus' : '' ?>.</p><?php else: ?>
                            <ul class="list mb-0">
                                <?php foreach ($budget['rows'] as $row): ?>
                                    <li class="list__item">
                                        <span class="grow truncate"><?php if ($row['open_route'] !== null): ?><a href="#" data-open-module="explorer" data-open-route="<?= $e($row['open_route']) ?>"><?= $e($row['label']) ?></a><?php else: ?><?= $e($row['label']) ?><?php endif; ?></span>
                                        <?php if ($row['amount'] !== null): ?><span class="mono text-nowrap<?= $row['amount'] < 0 ? ' text-danger' : ' text-success' ?>"><?= $e($money($row['amount'])) ?></span><?php endif; ?>
                                        <?php if ($canUpdate): ?><button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="unlink" data-params='<?= $params(['relation_id' => (int) $row['relation_id']]) ?>' data-confirm="Retirer cette opération du projet ?" title="Retirer"><?= $module->icon('close') ?></button><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="card card--compact">
                <div class="card__body text-small text-muted">
                    Créé le <?= $e($datetime($project['created_at'])) ?> · mis à jour le <?= $e($datetime($project['updated_at'])) ?> · identifiant <code><?= $e($project['slug']) ?></code>
                    <?php if ($explorerModule && $infoId !== null): ?><br><a href="#" data-open-module="explorer" data-open-route="info/<?= $e(rawurlencode($infoId)) ?>"><?= $module->icon('layers', 'icon--sm') ?> Voir dans l’Explorateur</a><?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>
