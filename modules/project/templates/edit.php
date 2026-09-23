<?php
/**
 * Formulaire de création / modification d'un projet.
 * @var array<string, mixed> $project @var list<string> $tags @var bool $isNew @var bool $canDelete
 * @var array<string, string> $statuses @var array<int, string> $priorities @var list<array<string, mixed>> $users
 * @var int $titleMax @var int $summaryMax @var int $descriptionMax
 * @var \Atelier\Modules\Project\ProjectModule $module
 */
$budgetInput = $project['budget_estimate'] === null ? '' : number_format(((int) $project['budget_estimate']) / 100, 2, ',', '');
?>
<div class="module module-project">
    <form class="project__editor" data-action="save" data-track-dirty data-save-shortcut novalidate>
        <input type="hidden" name="id" value="<?= $isNew ? '' : (int) $project['id'] ?>">
        <div class="project__layout">
            <div class="card">
                <div class="card__body">
                    <div class="field">
                        <label class="field__label" for="project-title">Titre <span class="required" aria-hidden="true">*</span></label>
                        <input class="input" type="text" id="project-title" name="title" value="<?= $e($project['title']) ?>" maxlength="<?= (int) $titleMax ?>" required autocomplete="off" placeholder="Voyage en Écosse, Établi d’atelier, Rénovation de la cuisine…"<?= $isNew ? ' autofocus' : '' ?>>
                        <?php if (!$isNew): ?><span class="field__help">Identifiant <code><?= $e($project['slug']) ?></code> (inchangé).</span><?php endif; ?>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="project-summary">Résumé</label>
                        <input class="input" type="text" id="project-summary" name="summary" value="<?= $e($project['summary'] ?? '') ?>" maxlength="<?= (int) $summaryMax ?>" placeholder="Une phrase qui résume le projet (affichée dans la liste)">
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="project-description">Description</label>
                        <textarea class="textarea" id="project-description" name="description" rows="16" maxlength="<?= (int) $descriptionMax ?>" data-editor="bbcode" placeholder="Objectif, contraintes, idées, liens utiles…"><?= $e($project['description'] ?? '') ?></textarea>
                        <span class="field__error"></span>
                    </div>
                    <div class="field">
                        <label class="field__label" for="project-tags"><?= $module->icon('tag', 'icon--sm') ?> Tags partagés</label>
                        <input class="input" type="text" id="project-tags" name="tags" value="<?= $e(implode(', ', $tags)) ?>" placeholder="Ajouter un tag…" autocomplete="off" data-tags-input data-tags-max="20">
                        <span class="field__help">Les tags existants sont proposés pendant la saisie (Entrée ou virgule pour ajouter).</span>
                        <span class="field__error"></span>
                    </div>
                </div>
                <div class="card__footer form-actions">
                    <button type="submit" class="btn btn--primary"><?= $module->icon('save') ?> Enregistrer</button>
                    <span class="text-muted text-small">Ctrl+S</span>
                    <a class="btn btn--ghost" href="#" data-route="<?= $isNew ? 'list' : 'show/' . (int) $project['id'] ?>">Annuler</a>
                    <span class="toolbar__spacer grow"></span>
                    <?php if ($canDelete): ?><button type="button" class="btn btn--outline-danger" data-action="delete" data-params='{"id":<?= (int) $project['id'] ?>}' data-confirm="Mettre ce projet à la corbeille ?" data-danger><?= $module->icon('trash') ?> Supprimer</button><?php endif; ?>
                </div>
            </div>

            <aside class="project__side">
                <section class="card card--compact">
                    <div class="card__header"><h2 class="card__title"><?= $module->icon('sliders') ?> Cadrage</h2></div>
                    <div class="card__body">
                        <div class="field">
                            <label class="field__label" for="project-status">Statut</label>
                            <select class="select" id="project-status" name="status"><?php foreach ($statuses as $code => $label): ?><option value="<?= $e($code) ?>"<?= $project['status'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="project-priority">Priorité</label>
                            <select class="select" id="project-priority" name="priority"><?php foreach ($priorities as $code => $label): ?><option value="<?= (int) $code ?>"<?= (int) $project['priority'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="project-start">Date de début</label>
                            <input class="input" type="date" id="project-start" name="start_date" value="<?= $e($project['start_date'] ?? '') ?>">
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="project-due">Échéance</label>
                            <input class="input" type="date" id="project-due" name="due_date" value="<?= $e($project['due_date'] ?? '') ?>">
                            <span class="field__help">Un projet planifié ou en cours dont l’échéance est passée est signalé « en retard ».</span>
                            <span class="field__error"></span>
                        </div>
                        <div class="field">
                            <label class="field__label" for="project-budget">Budget estimé (€)</label>
                            <input class="input" type="text" id="project-budget" name="budget_estimate" value="<?= $e($budgetInput) ?>" inputmode="decimal" placeholder="1250,00" autocomplete="off">
                            <span class="field__error"></span>
                        </div>
                        <div class="field mb-0">
                            <label class="field__label" for="project-owner">Responsable</label>
                            <select class="select" id="project-owner" name="owner_id">
                                <option value="">— Aucun —</option>
                                <?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"<?= (int) ($project['owner_id'] ?? 0) === (int) $user['id'] ? ' selected' : '' ?>><?= $e($user['display_name'] ?: $user['username']) ?></option><?php endforeach; ?>
                            </select>
                            <span class="field__error"></span>
                        </div>
                    </div>
                </section>
                <?php if ($isNew): ?>
                    <section class="card card--compact">
                        <div class="card__body text-small text-muted">Après l’enregistrement, la fiche permet d’ajouter les tâches, le journal de bord, les documents et de relier des informations des autres modules.</div>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    </form>
</div>
