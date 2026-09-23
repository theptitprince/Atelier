<?php

declare(strict_types=1);

namespace Atelier\Modules\Project;

use Atelier\Error\ForbiddenException;
use Atelier\Error\ModuleUnavailableException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Modules\TrashProviderInterface;
use Atelier\Shared\AttachmentService;
use Atelier\Shared\TagService;
use Atelier\Support\Clock;
use Atelier\Support\Str;
use Atelier\View\BbCode;

/**
 * Projets : conteneurs (objet à fabriquer, voyage, achat, travaux…) qui regroupent des tâches,
 * un journal de bord, des documents joints et des informations de tous les modules reliées par
 * le registre commun (pages, lieux GPS, opérations du budget, équipements, actualités…).
 *
 * Règles : suppression logique exposée à la corbeille globale ; les informations reliées ne sont
 * affichées que si leur jeu de données est partagé et lisible par l'utilisateur (même règle que
 * l'Explorateur) ; les modules optionnels (geo, budget, explorer) sont détectés et jamais requis.
 */
final class ProjectModule extends AbstractModule implements TrashProviderInterface
{
    public const STATUSES = ['idea' => 'Idée', 'planned' => 'Planifié', 'active' => 'En cours', 'done' => 'Terminé', 'dropped' => 'Abandonné'];
    public const STATUS_BADGES = ['idea' => 'muted', 'planned' => 'info', 'active' => 'success', 'done' => 'muted', 'dropped' => 'danger'];
    public const PRIORITIES = [1 => 'Haute', 2 => 'Normale', 3 => 'Basse'];
    /** Types de relation proposés depuis la fiche ; « part_of » est le défaut (information → projet). */
    public const RELATION_TYPES = [
        'part_of' => 'Fait partie du projet',
        'related' => 'En rapport avec',
        'references' => 'Fait référence à',
        'depends_on' => 'Dépend de',
        'located_at' => 'Localisé à',
    ];
    public const GEO_DATASET = 'geo.point';
    public const BUDGET_DATASET = 'budget.transaction';

    public const TITLE_MAX = 200;
    public const SUMMARY_MAX = 500;
    public const DESCRIPTION_MAX = 100000;
    public const NOTE_MAX = 10000;
    public const TASK_MAX = 200;
    public const TAG_MAX = 60;
    public const TAGS_MAX_COUNT = 20;
    public const LOOKUP_LIMIT = 15;

    private ?ProjectRepository $projects = null;
    private ?TaskRepository $tasks = null;
    private ?NoteRepository $notes = null;
    /** @var list<string>|null */
    private ?array $visibleCodes = null;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $datasetIndex = null;

    public function routes(RouteCollection $r): void
    {
        // Vues
        $r->view('list', [$this, 'list'], permission: 'open');
        $r->view('show/{id}', [$this, 'show'], permission: 'open');
        $r->view('new', [$this, 'create'], permission: 'create');
        $r->view('edit/{id}', [$this, 'edit'], permission: 'update');
        $r->view('trash', [$this, 'trashView'], permission: 'open');

        // Actions (POST sauf mention) ; « save » distingue création et modification.
        $r->action('filter', [$this, 'filter'], permission: 'open');
        $r->action('save', [$this, 'save'], permission: 'open');
        $r->action('set-status', [$this, 'setStatus'], permission: 'update');
        $r->action('delete', [$this, 'delete'], permission: 'delete');
        $r->action('restore', [$this, 'restore'], permission: 'delete');
        $r->action('purge', [$this, 'destroy'], permission: 'delete');
        $r->action('task-add', [$this, 'taskAdd'], permission: 'update');
        $r->action('task-toggle', [$this, 'taskToggle'], permission: 'update');
        $r->action('task-move', [$this, 'taskMove'], permission: 'update');
        $r->action('task-delete', [$this, 'taskDelete'], permission: 'update');
        $r->action('note-add', [$this, 'noteAdd'], permission: 'update');
        $r->action('note-delete', [$this, 'noteDelete'], permission: 'update');
        $r->action('attach', [$this, 'attach'], permission: 'update');
        $r->action('attachment-delete', [$this, 'attachmentDelete'], permission: 'update');
        $r->action('link', [$this, 'link'], permission: 'update');
        $r->action('unlink', [$this, 'unlink'], permission: 'update');
        $r->action('lookup', [$this, 'lookup'], permission: 'open', methods: ['GET']);
        $r->action('badge', [$this, 'badge'], permission: 'open', methods: ['GET']);
    }

    public function service(): ?object
    {
        return new ProjectService($this->ctx);
    }

    // =====================================================================
    // Vues
    // =====================================================================

    /** Liste : list?q=&tag=&sort=&status= */
    public function list(Request $request, array $params): ModuleView
    {
        $q = trim((string) $request->query('q', ''));
        $tag = Str::normalizeTag(trim((string) $request->query('tag', '')));
        $sort = (string) $request->query('sort', 'updated');
        if (!ProjectRepository::isSortable($sort)) {
            $sort = 'updated';
        }
        $status = (string) $request->query('status', 'all');
        if ($status !== 'all' && !isset(self::STATUSES[$status])) {
            $status = 'all';
        }
        $rows = $this->decorateList($this->repo()->listActive($q, $tag !== '' ? $tag : null, $sort));
        $rights = $this->rights(['create', 'update', 'delete']);
        $counts = ['all' => count($rows)];
        foreach (array_keys(self::STATUSES) as $code) {
            $counts[$code] = count(array_filter($rows, static fn (array $r): bool => $r['status'] === $code));
        }
        $tags = $this->tagChoices();

        $content = $this->render('list', [
            'rows' => $rows,
            'counts' => $counts,
            'q' => $q,
            'tag' => $tag,
            'sort' => $sort,
            'status' => $status,
            'sorts' => ['updated' => 'Mise à jour', 'due' => 'Échéance', 'title' => 'Titre', 'priority' => 'Priorité'],
            'tagChoices' => $tags,
            'rights' => $rights,
            'today' => $this->today(),
        ]);
        $total = $this->repo()->countActive();
        $actions = '';
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="new">' . $this->icon('plus') . ' Nouveau projet</a>';
        }
        $actions .= '<a class="btn btn--ghost" href="#" data-route="trash" title="Projets supprimés">' . $this->icon('trash') . ' Corbeille</a>';
        return ModuleView::make('Projets')
            ->banner($this->banner('Projets', $this->plural($total, 'projet'), $actions))
            ->content($content)
            ->status($q !== '' || $tag !== '' ? $this->plural(count($rows), 'projet trouvé', 'projets trouvés') . ' sur ' . $total : $this->plural($total, 'projet'));
    }

    /** Fiche : show/{id} (identifiant numérique ou identifiant lisible). */
    public function show(Request $request, array $params): ModuleView
    {
        $key = (string) ($params['id'] ?? '');
        $project = ctype_digit($key) ? $this->repo()->find((int) $key) : $this->repo()->findBySlug($key);
        if ($project === null) {
            if (ctype_digit($key) && $this->repo()->findTrashed((int) $key) !== null) {
                throw new NotFoundException('Ce projet est dans la corbeille : restaurez-le pour le consulter.');
            }
            throw new NotFoundException('Projet introuvable.');
        }
        $id = (int) $project['id'];
        $rights = $this->rights(['update', 'delete', 'create']);
        $infoId = $this->infoIdOf($id);
        $shared = $this->ctx->shared;

        $notes = $this->notes()->ofProject($id);
        foreach ($notes as &$note) {
            $note['html'] = BbCode::toHtml((string) $note['content']);
        }
        unset($note);

        $tasks = $this->tasks()->ofProject($id);
        $today = $this->today();
        foreach ($tasks as &$task) {
            $task['late'] = !(bool) $task['done'] && $task['due_date'] !== null && $task['due_date'] < $today;
        }
        unset($task);

        $points = $infoId !== null ? $this->linkedPoints($infoId) : [];
        $budget = $infoId !== null ? $this->linkedBudget($infoId) : ['available' => false, 'rows' => [], 'total' => null];
        $linked = $infoId !== null ? $this->linkedGroups($infoId, $points !== [] || $this->geoAvailable(), $budget['available']) : ['groups' => [], 'hidden' => 0, 'count' => 0];

        $content = $this->render('show', [
            'project' => $this->decorateProject($project),
            'html' => BbCode::toHtml((string) ($project['description'] ?? '')),
            'tasks' => $tasks,
            'notes' => $notes,
            'tags' => $infoId !== null ? $shared->tags->tagsOf($infoId, TagService::SHARED) : [],
            'attachments' => $infoId !== null ? $shared->attachments->listFor($infoId) : [],
            'inlineMimes' => AttachmentService::INLINE_MIMES,
            'linked' => $linked,
            'points' => $points,
            'geoAvailable' => $this->geoAvailable(),
            'mapModule' => $this->ctx->modules()->has('map'),
            'budget' => $budget,
            'rights' => $rights,
            'infoId' => $infoId,
            'relationTypes' => self::RELATION_TYPES,
            'statuses' => self::STATUSES,
            'statusBadges' => self::STATUS_BADGES,
            'priorities' => self::PRIORITIES,
            'explorerModule' => $this->ctx->modules()->has('explorer'),
            'today' => $today,
        ]);

        $actions = '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . ' Projets</a>';
        if ($rights['update']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="edit/' . $id . '">' . $this->icon('edit') . ' Modifier</a>';
        }
        if ($rights['delete']) {
            $actions .= '<button type="button" class="btn btn--outline-danger" data-action="delete" data-params=\'{"id":' . $id . '}\' data-confirm="Mettre ce projet à la corbeille ?" data-danger>' . $this->icon('trash') . ' Supprimer</button>';
        }
        $done = (int) $project['task_done'];
        $total = (int) $project['task_total'];
        $subtitle = self::STATUSES[$project['status']] . ' · ' . ($total > 0 ? $done . '/' . $total . ' tâches' : 'aucune tâche') . ' · ' . $this->plural($linked['count'] + count($points) + count($budget['rows']), 'élément lié', 'éléments liés');
        return ModuleView::make('Projet · ' . $project['title'])
            ->banner($this->banner((string) $project['title'], $subtitle, $actions))
            ->content($content)
            ->status('Projet « ' . $project['title'] . ' » · ' . self::STATUSES[$project['status']]);
    }

    public function create(Request $request, array $params): ModuleView
    {
        $project = [
            'id' => null, 'title' => trim((string) $request->query('title', '')), 'slug' => '', 'status' => 'idea', 'summary' => '', 'description' => '',
            'start_date' => null, 'due_date' => null, 'budget_estimate' => null, 'priority' => 2, 'owner_id' => $this->ctx->userId(), 'updated_at' => null,
        ];
        return $this->editorView($project, [], true);
    }

    public function edit(Request $request, array $params): ModuleView
    {
        $project = $this->requireProject((int) ($params['id'] ?? 0));
        return $this->editorView($project, $this->tagNames((int) $project['id']), false);
    }

    public function trashView(Request $request, array $params): ModuleView
    {
        $retention = $this->retentionDays();
        $rows = $this->repo()->trashed($retention);
        foreach ($rows as &$row) {
            $deletedAt = Clock::parseUtc((string) $row['deleted_at']);
            $row['expires_in_days'] = $deletedAt === null ? 0 : max(0, $retention - (int) $deletedAt->diff(Clock::now())->days);
        }
        unset($row);
        $rights = $this->rights(['delete']);
        $actions = '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . ' Projets</a>';
        return ModuleView::make('Corbeille des projets')
            ->banner($this->banner('Corbeille', $this->plural(count($rows), 'projet supprimé', 'projets supprimés'), $actions, 'trash'))
            ->content($this->render('trash', ['rows' => $rows, 'retention' => $retention, 'rights' => $rights, 'statuses' => self::STATUSES]))
            ->status($this->plural(count($rows), 'projet en corbeille', 'projets en corbeille'));
    }

    // =====================================================================
    // Actions : projet
    // =====================================================================

    /** Formulaire de recherche (data-auto-submit) : redirige vers la liste filtrée. */
    public function filter(Request $request, array $params): ActionResult
    {
        $query = array_filter([
            'q' => trim($request->string('q')),
            'tag' => Str::normalizeTag(trim($request->string('tag'))),
            'sort' => trim($request->string('sort')) === 'updated' ? '' : trim($request->string('sort')),
            'status' => trim($request->string('status')) === 'all' ? '' : trim($request->string('status')),
        ], static fn (string $v): bool => $v !== '');
        return ActionResult::ok()->navigate('list' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    public function save(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update');
        $existing = $isNew ? null : $this->requireProject((int) $id);

        $title = trim(preg_replace('/\s+/u', ' ', $request->string('title')) ?? '');
        $status = $request->string('status', 'idea');
        $summary = trim($request->string('summary'));
        $rawDescription = $request->input('description', '');
        $description = is_scalar($rawDescription) ? str_replace(["\r\n", "\r"], "\n", (string) $rawDescription) : '';
        $startDate = trim($request->string('start_date'));
        $dueDate = trim($request->string('due_date'));
        $budgetRaw = trim($request->string('budget_estimate'));
        $priority = (int) ($request->int('priority') ?? 2);
        $ownerId = $request->int('owner_id');
        $tags = $this->parseTags($request->input('tags', ''));

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Le titre est obligatoire.';
        } elseif (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            $errors['title'] = 'Le titre ne doit pas dépasser ' . self::TITLE_MAX . ' caractères.';
        } elseif ($this->repo()->findByTitle($title, $isNew ? null : (int) $id) !== null) {
            $errors['title'] = 'Un projet porte déjà ce titre.';
        }
        if (!isset(self::STATUSES[$status])) {
            $errors['status'] = 'Statut inconnu.';
        }
        if (mb_strlen($summary, 'UTF-8') > self::SUMMARY_MAX) {
            $errors['summary'] = 'Le résumé ne doit pas dépasser ' . self::SUMMARY_MAX . ' caractères.';
        }
        if (mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX) {
            $errors['description'] = 'La description ne doit pas dépasser ' . number_format(self::DESCRIPTION_MAX, 0, ',', ' ') . ' caractères.';
        }
        if ($startDate !== '' && !self::isDate($startDate)) {
            $errors['start_date'] = 'Date de début invalide (AAAA-MM-JJ).';
        }
        if ($dueDate !== '' && !self::isDate($dueDate)) {
            $errors['due_date'] = 'Échéance invalide (AAAA-MM-JJ).';
        }
        if ($startDate !== '' && $dueDate !== '' && !isset($errors['start_date'], $errors['due_date']) && $dueDate < $startDate) {
            $errors['due_date'] = 'L’échéance doit être postérieure ou égale à la date de début.';
        }
        $budget = null;
        if ($budgetRaw !== '') {
            $budget = self::parseMoney($budgetRaw);
            if ($budget === null || $budget < 0) {
                $errors['budget_estimate'] = 'Montant invalide (ex. 1250,50).';
            }
        }
        if (!isset(self::PRIORITIES[$priority])) {
            $errors['priority'] = 'Priorité inconnue.';
        }
        if ($ownerId !== null && $ownerId > 0 && $this->ctx->users->find($ownerId) === null) {
            $errors['owner_id'] = 'Responsable inconnu.';
        }
        if (count($tags) > self::TAGS_MAX_COUNT) {
            $errors['tags'] = 'Au maximum ' . self::TAGS_MAX_COUNT . ' tags par projet.';
        }
        foreach ($tags as $tag) {
            if (mb_strlen($tag, 'UTF-8') > self::TAG_MAX) {
                $errors['tags'] = 'Chaque tag doit comporter au plus ' . self::TAG_MAX . ' caractères.';
                break;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $userId = $this->ctx->userId();
        $data = [
            'title' => $title,
            'slug' => $isNew ? $this->repo()->uniqueSlug($title) : (string) $existing['slug'],
            'status' => $status,
            'summary' => $summary,
            'description' => $description,
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'budget_estimate' => $budget,
            'priority' => $priority,
            'owner_id' => $ownerId !== null && $ownerId > 0 ? $ownerId : null,
        ];
        $projectId = $this->ctx->db->transaction(function () use ($isNew, $id, $data, $tags, $userId): int {
            $projectId = $isNew ? $this->repo()->create($data, $userId) : (int) $id;
            if (!$isNew) {
                $this->repo()->update($projectId, $data);
            }
            $infoId = $this->ctx->shared->registry->register(ProjectService::DATASET, (string) $projectId, $data['title'], $userId);
            $this->ctx->shared->tags->replace($infoId, $tags, TagService::SHARED, $userId);
            return $projectId;
        });
        $this->log($isNew ? 'project.create' : 'project.update', 'success', 'project:' . $projectId, ($isNew ? 'Projet créé : ' : 'Projet modifié : ') . $title, ['status' => $status, 'tags' => count($tags)]);
        return ActionResult::ok(['id' => $projectId, 'slug' => $data['slug']], 'Projet « ' . $title . ' » enregistré.')->navigate('show/' . $projectId)->dirty(false);
    }

    /** Changement de statut depuis la fiche : { id, status }. */
    public function setStatus(Request $request, array $params): ActionResult
    {
        $project = $this->requireProject($this->requireId($request));
        $status = $request->string('status');
        if (!isset(self::STATUSES[$status])) {
            throw ValidationException::single('status', 'Statut inconnu.');
        }
        if ($status === $project['status']) {
            return ActionResult::info(null, 'Le projet est déjà « ' . self::STATUSES[$status] . ' ».');
        }
        $this->repo()->setStatus((int) $project['id'], $status);
        $this->log('project.status', 'success', 'project:' . $project['id'], 'Statut « ' . self::STATUSES[$status] . ' » : ' . $project['title'], ['from' => $project['status'], 'to' => $status]);
        return ActionResult::ok(['status' => $status], 'Projet « ' . self::STATUSES[$status] . ' ».')->refresh();
    }

    public function delete(Request $request, array $params): ActionResult
    {
        $project = $this->requireProject($this->requireId($request));
        $this->repo()->softDelete((int) $project['id']);
        $this->log('project.delete', 'success', 'project:' . $project['id'], 'Projet mis à la corbeille : ' . $project['title']);
        return ActionResult::ok(null, 'Projet « ' . $project['title'] . ' » mis à la corbeille.')->navigate('list')->dirty(false);
    }

    public function restore(Request $request, array $params): ActionResult
    {
        $project = $this->restoreTrashed($this->requireId($request), 'Projet restauré');
        return ActionResult::ok(['id' => $project['id']], 'Projet « ' . $project['title'] . ' » restauré.')->refresh();
    }

    /** Suppression définitive depuis la corbeille du module (action « purge »). */
    public function destroy(Request $request, array $params): ActionResult
    {
        $project = $this->purgeTrashed($this->requireId($request), 'Projet supprimé définitivement');
        return ActionResult::ok(null, 'Projet « ' . $project['title'] . ' » supprimé définitivement.')->refresh();
    }

    // =====================================================================
    // Actions : tâches et journal
    // =====================================================================

    /** Ajout rapide : { id, title, due_date? }. */
    public function taskAdd(Request $request, array $params): ActionResult
    {
        $project = $this->requireProject($this->requireId($request));
        $title = trim(preg_replace('/\s+/u', ' ', $request->string('title')) ?? '');
        $dueDate = trim($request->string('due_date'));
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Indiquez l’intitulé de la tâche.';
        } elseif (mb_strlen($title, 'UTF-8') > self::TASK_MAX) {
            $errors['title'] = 'L’intitulé ne doit pas dépasser ' . self::TASK_MAX . ' caractères.';
        }
        if ($dueDate !== '' && !self::isDate($dueDate)) {
            $errors['due_date'] = 'Échéance invalide (AAAA-MM-JJ).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $taskId = $this->tasks()->create((int) $project['id'], $title, $dueDate !== '' ? $dueDate : null);
        $this->repo()->touch((int) $project['id']);
        $this->log('project.task_add', 'success', 'project:' . $project['id'], 'Tâche ajoutée : ' . $title, ['task_id' => $taskId]);
        return ActionResult::ok(['id' => $taskId], 'Tâche ajoutée.')->refresh();
    }

    /** Cocher / décocher : { id, task_id, done? } (bascule si « done » absent). */
    public function taskToggle(Request $request, array $params): ActionResult
    {
        [$project, $task] = $this->requireTask($request);
        $done = $request->input('done') === null ? !(bool) $task['done'] : $request->bool('done');
        $this->tasks()->setDone((int) $task['id'], $done);
        $this->repo()->touch((int) $project['id']);
        $this->log('project.task_toggle', 'success', 'project:' . $project['id'], ($done ? 'Tâche faite : ' : 'Tâche rouverte : ') . $task['title'], ['task_id' => $task['id']]);
        return ActionResult::ok(['done' => $done], $done ? 'Tâche « ' . $task['title'] . ' » faite.' : 'Tâche « ' . $task['title'] . ' » rouverte.')->refresh();
    }

    /** Réordonner : { id, task_id, direction: "up"|"down" }. */
    public function taskMove(Request $request, array $params): ActionResult
    {
        [$project, $task] = $this->requireTask($request);
        $direction = $request->string('direction', 'up') === 'down' ? 1 : -1;
        if (!$this->tasks()->move((int) $project['id'], (int) $task['id'], $direction)) {
            return ActionResult::info(null, 'La tâche est déjà en ' . ($direction < 0 ? 'tête' : 'fin') . ' de liste.');
        }
        return ActionResult::ok()->refresh();
    }

    public function taskDelete(Request $request, array $params): ActionResult
    {
        [$project, $task] = $this->requireTask($request);
        $this->tasks()->softDelete((int) $task['id']);
        $this->repo()->touch((int) $project['id']);
        $this->log('project.task_delete', 'success', 'project:' . $project['id'], 'Tâche supprimée : ' . $task['title'], ['task_id' => $task['id']]);
        return ActionResult::ok(null, 'Tâche « ' . $task['title'] . ' » supprimée.')->refresh();
    }

    /** Journal de bord : { id, content }. */
    public function noteAdd(Request $request, array $params): ActionResult
    {
        $project = $this->requireProject($this->requireId($request));
        $raw = $request->input('content', '');
        $content = is_scalar($raw) ? trim(str_replace(["\r\n", "\r"], "\n", (string) $raw)) : '';
        if ($content === '') {
            throw ValidationException::single('content', 'Rédigez la note avant de l’ajouter.');
        }
        if (mb_strlen($content, 'UTF-8') > self::NOTE_MAX) {
            throw ValidationException::single('content', 'Une note ne doit pas dépasser ' . number_format(self::NOTE_MAX, 0, ',', ' ') . ' caractères.');
        }
        $noteId = $this->notes()->create((int) $project['id'], $content, $this->ctx->userId());
        $this->repo()->touch((int) $project['id']);
        $this->log('project.note_add', 'success', 'project:' . $project['id'], 'Note de journal ajoutée', ['note_id' => $noteId, 'length' => mb_strlen($content, 'UTF-8')]);
        return ActionResult::ok(['id' => $noteId], 'Note ajoutée au journal.')->refresh();
    }

    public function noteDelete(Request $request, array $params): ActionResult
    {
        $project = $this->requireProject($this->requireId($request));
        $noteId = (int) ($request->int('note_id') ?? 0);
        $note = $noteId > 0 ? $this->notes()->find((int) $project['id'], $noteId) : null;
        if ($note === null) {
            throw new NotFoundException('Note introuvable.');
        }
        $this->notes()->delete($noteId);
        $this->log('project.note_delete', 'success', 'project:' . $project['id'], 'Note de journal supprimée', ['note_id' => $noteId]);
        return ActionResult::ok(null, 'Note supprimée.')->refresh();
    }

    // =====================================================================
    // Actions : documents
    // =====================================================================

    /** Téléversement multipart : { id, files[] (ou file), label? }. */
    public function attach(Request $request, array $params): ActionResult
    {
        $project = $this->requireProject($this->requireId($request));
        $files = array_merge($request->fileList('files'), $request->fileList('file'));
        if ($files === []) {
            throw ValidationException::single('files', 'Choisissez au moins un fichier à joindre.');
        }
        $label = trim($request->string('label'));
        $userId = $this->ctx->userId();
        $infoId = $this->infoIdOf((int) $project['id'], true);
        $stored = [];
        foreach ($files as $file) {
            // AttachmentService contrôle type MIME, extension, taille et quotas (ValidationException sinon).
            $record = $this->ctx->shared->attachments->store($file, $infoId, $userId, null, $label !== '' ? $label : null);
            $stored[] = ['id' => $record['id'], 'name' => $record['original_name'], 'size' => (int) $record['size']];
            $this->log('project.attach', 'success', 'project:' . $project['id'], 'Document joint : ' . $record['original_name'], ['attachment_id' => $record['id'], 'size' => $record['size'], 'mime' => $record['mime']]);
        }
        $count = count($stored);
        return ActionResult::ok(['files' => $stored], $count === 1 ? 'Document « ' . $stored[0]['name'] . ' » joint (' . Str::humanSize($stored[0]['size']) . ').' : $count . ' documents joints.')->refresh();
    }

    /** Suppression logique d'un document : { id, attachment_id }. */
    public function attachmentDelete(Request $request, array $params): ActionResult
    {
        $project = $this->requireProject($this->requireId($request));
        $attachmentId = trim($request->string('attachment_id'));
        $attachment = $attachmentId === '' ? null : $this->ctx->shared->attachments->find($attachmentId);
        $infoId = $this->infoIdOf((int) $project['id']);
        if ($attachment === null || $infoId === null || (string) $attachment['info_id'] !== $infoId) {
            throw new NotFoundException('Document introuvable sur ce projet.');
        }
        $this->ctx->shared->attachments->softDelete($attachmentId);
        $this->log('project.attachment_delete', 'success', 'project:' . $project['id'], 'Document retiré : ' . $attachment['original_name'], ['attachment_id' => $attachmentId]);
        return ActionResult::ok(null, 'Document « ' . AttachmentService::displayName($attachment) . ' » retiré (récupérable dans la corbeille).')->refresh();
    }

    // =====================================================================
    // Actions : éléments liés
    // =====================================================================

    /**
     * Recherche transversale de cibles : lookup?q=&exclude= (GET, JSON), limitée aux jeux partagés
     * lisibles par l'utilisateur, comme l'Explorateur.
     *
     * @return array<string, mixed>
     */
    public function lookup(Request $request, array $params): array
    {
        $term = trim((string) $request->query('q', ''));
        $exclude = (string) $request->query('exclude', '');
        $results = [];
        if ($term !== '') {
            $index = $this->datasetIndex();
            foreach ($this->ctx->shared->registry->search($term, $this->visibleCodes(), self::LOOKUP_LIMIT + 1) as $row) {
                if ((string) $row['id'] === $exclude) {
                    continue;
                }
                $meta = $index[$row['dataset_code']] ?? null;
                $results[] = [
                    'id' => (string) $row['id'],
                    'label' => (string) ($row['label'] ?? $row['local_key']),
                    'dataset' => (string) $row['dataset_code'],
                    'dataset_name' => $meta['name'] ?? $row['dataset_code'],
                    'module' => (string) $row['module_id'],
                    'module_name' => $meta['module_name'] ?? $row['module_id'],
                ];
                if (count($results) >= self::LOOKUP_LIMIT) {
                    break;
                }
            }
        }
        return ['results' => $results];
    }

    /**
     * Relier une information au projet : { id, to (UUID du registre), type? }.
     * « part_of » (défaut) va de l'information vers le projet ; les autres types partent du projet.
     * Une cible « geo.point » est rattachée par le service GPS (relation « located_at ») quand il est disponible.
     */
    public function link(Request $request, array $params): ActionResult
    {
        $project = $this->requireProject($this->requireId($request));
        $toId = trim($request->string('to'));
        if ($toId === '') {
            throw ValidationException::single('to', 'Choisissez une information à relier.');
        }
        $type = $request->string('type', ProjectService::RELATION);
        if (!isset(self::RELATION_TYPES[$type])) {
            throw ValidationException::single('type', 'Type de relation inconnu.');
        }
        $to = $this->ctx->shared->registry->get($toId);
        if ($to === null || !$this->isVisibleCode((string) $to['dataset_code'])) {
            throw ValidationException::single('to', 'Information introuvable ou non accessible.');
        }
        $userId = $this->ctx->userId();
        $infoId = $this->infoIdOf((int) $project['id'], true);
        if ($infoId === (string) $to['id']) {
            throw ValidationException::single('to', 'Un projet ne peut pas être relié à lui-même.');
        }
        $label = (string) ($to['label'] ?? $to['local_key']);
        if ((string) $to['dataset_code'] === self::GEO_DATASET && in_array($type, ['part_of', 'located_at'], true)) {
            $type = 'located_at';
            try {
                $this->ctx->moduleService('geo')->attach($infoId, (int) $to['local_key']);
            } catch (ModuleUnavailableException | ForbiddenException) {
                $this->ctx->shared->relations->relate($type, $infoId, (string) $to['id'], $userId);
            }
        } elseif ($type === ProjectService::RELATION) {
            $this->ctx->shared->relations->relate($type, (string) $to['id'], $infoId, $userId);
        } else {
            $this->ctx->shared->relations->relate($type, $infoId, (string) $to['id'], $userId);
        }
        $this->log('project.link', 'success', 'project:' . $project['id'], 'Élément relié (' . self::RELATION_TYPES[$type] . ') : ' . $label, ['to' => $to['id'], 'dataset' => $to['dataset_code'], 'type' => $type]);
        return ActionResult::ok(null, '« ' . $label . ' » relié au projet.')->refresh();
    }

    /** Retirer une relation : { id, relation_id }. La relation doit impliquer le projet. */
    public function unlink(Request $request, array $params): ActionResult
    {
        $project = $this->requireProject($this->requireId($request));
        $relationId = (int) ($request->int('relation_id') ?? 0);
        $relation = $relationId > 0 ? $this->ctx->shared->relations->find($relationId) : null;
        $infoId = $this->infoIdOf((int) $project['id']);
        if ($relation === null || $infoId === null || ($relation['from_info'] !== $infoId && $relation['to_info'] !== $infoId)) {
            throw new NotFoundException('Relation introuvable sur ce projet.');
        }
        $otherId = $relation['from_info'] === $infoId ? (string) $relation['to_info'] : (string) $relation['from_info'];
        $other = $this->ctx->shared->registry->get($otherId);
        if ($other === null || !$this->isVisibleCode((string) $other['dataset_code'])) {
            throw new ForbiddenException('L’autre information de cette relation n’est pas accessible.');
        }
        $this->ctx->shared->relations->remove($relationId);
        $this->log('project.unlink', 'success', 'project:' . $project['id'], 'Élément retiré : ' . ($other['label'] ?? $other['local_key']), ['relation_id' => $relationId, 'type' => $relation['type']]);
        return ActionResult::ok(null, '« ' . ($other['label'] ?? $other['local_key']) . ' » retiré du projet.')->refresh();
    }

    /** Badge de la colonne : projets « en cours » ayant une tâche en retard. @return array<string, mixed> */
    public function badge(Request $request, array $params): array
    {
        $count = $this->repo()->countActiveWithLateTask($this->today());
        return ['count' => $count, 'label' => $count === 0 ? 'Aucune tâche en retard' : $this->plural($count, 'projet en cours avec une tâche en retard', 'projets en cours avec des tâches en retard')];
    }

    // =====================================================================
    // Corbeille globale (TrashProviderInterface)
    // =====================================================================

    public function trashItems(): array
    {
        $retention = $this->retentionDays();
        $canDelete = $this->can('delete');
        $items = [];
        foreach ($this->repo()->trashed($retention) as $project) {
            $deletedAt = (string) $project['deleted_at'];
            $purgeAt = Clock::parseUtc($deletedAt)?->modify('+' . $retention . ' days');
            $items[] = [
                'id' => (string) $project['id'],
                'label' => (string) $project['title'],
                'dataset' => ProjectService::DATASET,
                'deleted_at' => $deletedAt,
                'deleted_by' => null,
                'purge_at' => $purgeAt === null ? null : Clock::utc($purgeAt),
                'can_restore' => $canDelete,
                'can_purge' => $canDelete,
            ];
        }
        return $items;
    }

    public function restoreTrashItem(string $id): void
    {
        $this->require('delete');
        $this->restoreTrashed((int) $id, 'Projet restauré depuis la corbeille globale');
    }

    public function purgeTrashItem(string $id): void
    {
        $this->require('delete');
        $this->purgeTrashed((int) $id, 'Projet supprimé définitivement depuis la corbeille globale');
    }

    // =====================================================================
    // Hooks
    // =====================================================================

    /** Données de démonstration : deux projets, si la table est vide. */
    public function seed(): string
    {
        if ($this->repo()->countAll() > 0) {
            return 'projets d’exemple déjà présents';
        }
        $admin = $this->ctx->users->findByUsername('admin');
        $userId = $this->ctx->auth->userId() ?? ($admin === null ? null : (int) $admin['id']);
        $today = Clock::now();
        $day = static fn (int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

        $trip = $this->repo()->create([
            'title' => 'Voyage en Écosse',
            'slug' => $this->repo()->uniqueSlug('Voyage en Écosse'),
            'status' => 'planned',
            'summary' => 'Dix jours entre Édimbourg, les Highlands et l’île de Skye.',
            'description' => "[h2]Itinéraire[/h2]\n[list]\n[*]Édimbourg (3 nuits)\n[*]Inverness et le loch Ness\n[*]Île de Skye (3 nuits)\n[*]Glasgow, retour\n[/list]\n[h2]À prévoir[/h2]\nVêtements de pluie, adaptateur électrique, permis de conduire international.",
            'start_date' => $day(60),
            'due_date' => $day(70),
            'budget_estimate' => 320000,
            'priority' => 2,
            'owner_id' => $userId,
        ], $userId);
        $infoId = $this->ctx->shared->registry->register(ProjectService::DATASET, (string) $trip, 'Voyage en Écosse', $userId);
        $this->ctx->shared->tags->replace($infoId, ['voyage', 'exemple'], TagService::SHARED, $userId);
        foreach ([['Réserver les billets d’avion', $day(-3), true], ['Réserver la voiture de location', $day(10), false], ['Trouver les hébergements sur Skye', $day(20), false], ['Faire la liste des randonnées', null, false]] as [$title, $due, $done]) {
            $taskId = $this->tasks()->create($trip, $title, $due);
            if ($done) {
                $this->tasks()->setDone($taskId, true);
            }
        }
        $this->notes()->create($trip, 'Billets pris sur un vol direct, arrivée le matin à Édimbourg. Reste à confirmer la voiture.', $userId);
        $this->notes()->create($trip, 'Idée : [b]Fairy Pools[/b] et Old Man of Storr sur Skye, prévoir une journée complète.', $userId);

        $bench = $this->repo()->create([
            'title' => 'Établi d’atelier',
            'slug' => $this->repo()->uniqueSlug('Établi d’atelier'),
            'status' => 'idea',
            'summary' => 'Établi en hêtre massif avec presse avant et rangement d’outils.',
            'description' => "Plateau lamellé-collé de 60 mm, piètement en chêne, presse d’établi à vis.\n\n[b]Dimensions :[/b] 180 × 65 cm, hauteur 90 cm.",
            'start_date' => null,
            'due_date' => null,
            'budget_estimate' => 45000,
            'priority' => 3,
            'owner_id' => $userId,
        ], $userId);
        $infoId = $this->ctx->shared->registry->register(ProjectService::DATASET, (string) $bench, 'Établi d’atelier', $userId);
        $this->ctx->shared->tags->replace($infoId, ['atelier', 'bois', 'exemple'], TagService::SHARED, $userId);
        $this->tasks()->create($bench, 'Dessiner le plan et la liste de débit', null);
        $this->tasks()->create($bench, 'Comparer les prix du bois', null);

        return '2 projets d’exemple créés';
    }

    /** Rétention : suppression physique des projets en corbeille depuis plus de N jours. */
    public function purge(): string
    {
        $days = $this->retentionDays();
        $ids = $this->repo()->expiredTrashIds($days);
        foreach ($ids as $id) {
            $this->ctx->db->transaction(function () use ($id): void {
                $this->ctx->shared->registry->unregister(ProjectService::DATASET, (string) $id);
                $this->repo()->purge($id);
            });
            $this->log('project.purge', 'success', 'project:' . $id, 'Projet purgé par la rétention (' . $days . ' jours)');
        }
        return count($ids) . ' projet(s) purgé(s) de la corbeille (> ' . $days . ' jours)';
    }

    // =====================================================================
    // Helpers publics (gabarits)
    // =====================================================================

    public function icon(string $name, string $extra = ''): string
    {
        return '<svg class="icon' . ($extra !== '' ? ' ' . $this->e($extra) : '') . '" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    /** Montant en centimes → « 1 250,50 € ». */
    public static function money(?int $cents, string $empty = '—'): string
    {
        if ($cents === null) {
            return $empty;
        }
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    /** « 1250,50 » / « 1 250.5 » / « 1250 » → centimes, null si invalide. */
    public static function parseMoney(string $value): ?int
    {
        $clean = str_replace([' ', "\u{202F}", "\u{00A0}", '€'], '', trim($value));
        $clean = str_replace(',', '.', $clean);
        if ($clean === '' || !preg_match('/^-?\d+(\.\d{1,2})?$/', $clean)) {
            return null;
        }
        return (int) round((float) $clean * 100);
    }

    /** « 2026-09-23 » → « 23/09/2026 ». */
    public static function day(?string $date, string $empty = '—'): string
    {
        if ($date === null || !self::isDate($date)) {
            return $empty;
        }
        return substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
    }

    public static function isDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    // =====================================================================
    // Helpers privés
    // =====================================================================

    private function repo(): ProjectRepository
    {
        return $this->projects ??= new ProjectRepository($this->ctx->db);
    }

    private function tasks(): TaskRepository
    {
        return $this->tasks ??= new TaskRepository($this->ctx->db);
    }

    private function notes(): NoteRepository
    {
        return $this->notes ??= new NoteRepository($this->ctx->db);
    }

    private function retentionDays(): int
    {
        return max(1, $this->ctx->config->int('trash.retention_days', 30));
    }

    private function today(): string
    {
        return Clock::now()->format('Y-m-d');
    }

    private function requireId(Request $request, string $key = 'id'): int
    {
        $id = (int) ($request->int($key) ?? 0);
        if ($id <= 0) {
            throw ValidationException::single($key, 'Identifiant manquant.');
        }
        return $id;
    }

    /** @return array<string, mixed> projet actif, NotFoundException sinon */
    private function requireProject(int $id): array
    {
        $project = $id > 0 ? $this->repo()->find($id) : null;
        if ($project === null) {
            throw new NotFoundException('Projet introuvable ou déjà supprimé.');
        }
        return $project;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} projet et tâche ({ id, task_id }) */
    private function requireTask(Request $request): array
    {
        $project = $this->requireProject($this->requireId($request));
        $taskId = (int) ($request->int('task_id') ?? 0);
        $task = $taskId > 0 ? $this->tasks()->find((int) $project['id'], $taskId) : null;
        if ($task === null) {
            throw new NotFoundException('Tâche introuvable.');
        }
        return [$project, $task];
    }

    /** @return array<string, mixed> projet en corbeille, NotFoundException sinon */
    private function requireTrashed(int $id): array
    {
        $project = $id > 0 ? $this->repo()->findTrashed($id) : null;
        if ($project === null) {
            throw new NotFoundException('Ce projet n’est pas dans la corbeille.');
        }
        return $project;
    }

    /** @return array<string, mixed> */
    private function restoreTrashed(int $id, string $message): array
    {
        $project = $this->requireTrashed($id);
        if (!$this->repo()->restore($id)) {
            throw new NotFoundException('Ce projet n’est pas dans la corbeille.');
        }
        $this->log('project.restore', 'success', 'project:' . $id, $message . ' : ' . $project['title']);
        return $project;
    }

    /** @return array<string, mixed> */
    private function purgeTrashed(int $id, string $message): array
    {
        $project = $this->requireTrashed($id);
        $this->ctx->db->transaction(function () use ($id): void {
            $this->ctx->shared->registry->unregister(ProjectService::DATASET, (string) $id);
            $this->repo()->purge($id);
        });
        $this->log('project.purge', 'success', 'project:' . $id, $message . ' : ' . $project['title']);
        return $project;
    }

    /** Identifiant de registre d'un projet ; créé à la demande si $create. */
    private function infoIdOf(int $projectId, bool $create = false): ?string
    {
        $info = $this->ctx->shared->registry->find(ProjectService::DATASET, (string) $projectId);
        if ($info !== null) {
            return (string) $info['id'];
        }
        if (!$create) {
            return null;
        }
        $project = $this->repo()->find($projectId);
        return $this->ctx->shared->registry->register(ProjectService::DATASET, (string) $projectId, (string) ($project['title'] ?? ''), $this->ctx->userId());
    }

    /** @return list<string> */
    private function tagNames(int $projectId): array
    {
        $infoId = $this->infoIdOf($projectId);
        if ($infoId === null) {
            return [];
        }
        return array_map(static fn (array $t): string => (string) $t['name'], $this->ctx->shared->tags->tagsOf($infoId, TagService::SHARED));
    }

    /** Tags partagés utilisés par au moins un projet actif (filtre de la liste). @return list<array{name: string, normalized: string, count: int}> */
    private function tagChoices(): array
    {
        $counts = [];
        foreach ($this->repo()->listActive('', null, 'title') as $row) {
            foreach ($this->tagNames((int) $row['id']) as $name) {
                $key = Str::normalizeTag($name);
                $counts[$key] ??= ['name' => $name, 'normalized' => $key, 'count' => 0];
                $counts[$key]['count']++;
            }
        }
        ksort($counts);
        return array_values($counts);
    }

    /** Vue de l'éditeur. @param array<string, mixed> $project @param list<string> $tags */
    private function editorView(array $project, array $tags, bool $isNew): ModuleView
    {
        $content = $this->render('edit', [
            'project' => $project,
            'tags' => $tags,
            'isNew' => $isNew,
            'canDelete' => !$isNew && $this->can('delete'),
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'users' => $this->ctx->users->all(),
            'titleMax' => self::TITLE_MAX,
            'summaryMax' => self::SUMMARY_MAX,
            'descriptionMax' => self::DESCRIPTION_MAX,
        ]);
        $title = $isNew ? 'Nouveau projet' : (string) $project['title'];
        $actions = '<a class="btn btn--ghost" href="#" data-route="' . ($isNew ? 'list' : 'show/' . (int) $project['id']) . '">' . $this->icon('chevron-left') . ' ' . ($isNew ? 'Projets' : 'Fiche') . '</a>';
        return ModuleView::make($title)
            ->banner($this->banner($title, $isNew ? 'Décrivez l’idée, le voyage, l’achat ou les travaux' : 'Modifié le ' . Clock::formatDateTime($project['updated_at'] ?? null), $actions, $isNew ? 'plus' : 'edit'))
            ->content($content)
            ->status('Ctrl+S pour enregistrer');
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function decorateList(array $rows): array
    {
        foreach ($rows as &$row) {
            $row = $this->decorateProject($row);
            $row['tags'] = $this->tagNames((int) $row['id']);
            $infoId = $this->infoIdOf((int) $row['id']);
            $row['linked_count'] = $infoId === null ? 0 : $this->ctx->shared->relations->countFor($infoId);
            $row['attachment_count'] = $infoId === null ? 0 : $this->ctx->shared->attachments->countFor($infoId);
        }
        unset($row);
        return $rows;
    }

    /** Libellés, extrait et retard d'un projet. @param array<string, mixed> $row @return array<string, mixed> */
    private function decorateProject(array $row): array
    {
        $today = $this->today();
        $row['status_label'] = self::STATUSES[$row['status']] ?? (string) $row['status'];
        $row['status_badge'] = self::STATUS_BADGES[$row['status']] ?? 'muted';
        $row['priority_label'] = self::PRIORITIES[(int) $row['priority']] ?? 'Normale';
        $row['task_total'] = (int) ($row['task_total'] ?? 0);
        $row['task_done'] = (int) ($row['task_done'] ?? 0);
        $row['progress'] = $row['task_total'] > 0 ? (int) round($row['task_done'] * 100 / $row['task_total']) : 0;
        $row['late'] = in_array($row['status'], ['planned', 'active'], true) && (
            ($row['due_date'] !== null && $row['due_date'] < $today)
            || (($row['next_task_due'] ?? null) !== null && $row['next_task_due'] < $today)
        );
        $plain = trim(preg_replace('/\s+/u', ' ', BbCode::toText((string) ($row['description'] ?? ''))) ?? '');
        $row['excerpt'] = (string) ($row['summary'] ?? '') !== '' ? (string) $row['summary'] : Str::truncate($plain, 160);
        return $row;
    }

    // ----- Visibilité (même règle que l'Explorateur) -----

    /** @return list<string> */
    private function visibleCodes(): array
    {
        return $this->visibleCodes ??= $this->ctx->shared->catalog->readableCodes($this->ctx->userId());
    }

    private function isVisibleCode(string $code): bool
    {
        return in_array($code, $this->visibleCodes(), true);
    }

    /**
     * Index des jeux partagés lisibles : code => nom, module, nom et icône du module, openRoute.
     *
     * @return array<string, array<string, mixed>>
     */
    private function datasetIndex(): array
    {
        if ($this->datasetIndex !== null) {
            return $this->datasetIndex;
        }
        $index = [];
        $modules = $this->ctx->modules();
        foreach ($this->ctx->shared->catalog->shared() as $dataset) {
            $code = (string) $dataset['code'];
            if (!$this->isVisibleCode($code)) {
                continue;
            }
            $descriptor = $modules->get((string) $dataset['module_id']);
            $openRoute = null;
            if ($descriptor !== null && $descriptor->manifest !== null && $descriptor->isUsable()) {
                foreach ($descriptor->manifest->datasets() as $declared) {
                    if ($declared['code'] === $code) {
                        $openRoute = $declared['openRoute'] ?? null;
                        break;
                    }
                }
            }
            $index[$code] = [
                'code' => $code,
                'name' => (string) $dataset['name'],
                'module' => (string) $dataset['module_id'],
                'module_name' => $descriptor?->name() ?? (string) $dataset['module_id'],
                'module_icon' => $descriptor?->icon() ?? 'module',
                'module_usable' => $descriptor !== null && $descriptor->isUsable(),
                'open_route' => $openRoute,
            ];
        }
        return $this->datasetIndex = $index;
    }

    /**
     * Relations du projet groupées par module puis jeu de données, limitées aux jeux lisibles.
     * Les points GPS et les opérations du budget sont exclus lorsque leur bloc dédié est affiché.
     *
     * @return array{groups: list<array<string, mixed>>, hidden: int, count: int}
     */
    private function linkedGroups(string $infoId, bool $skipGeo, bool $skipBudget): array
    {
        $index = $this->datasetIndex();
        $explorer = $this->ctx->modules()->has('explorer');
        $groups = [];
        $hidden = 0;
        $count = 0;
        foreach ($this->ctx->shared->relations->relationsOf($infoId) as $relation) {
            $code = (string) $relation['other_dataset'];
            if (!$this->isVisibleCode($code)) {
                $hidden++;
                continue;
            }
            if (($skipGeo && $code === self::GEO_DATASET) || ($skipBudget && $code === self::BUDGET_DATASET)) {
                continue;
            }
            $meta = $index[$code] ?? null;
            $moduleId = (string) $relation['other_module'];
            $item = [
                'relation_id' => (int) $relation['id'],
                'info_id' => (string) $relation['other_id'],
                'label' => (string) ($relation['other_label'] ?? $relation['other_key']),
                'key' => (string) $relation['other_key'],
                'type' => (string) $relation['type'],
                'type_label' => self::RELATION_TYPES[$relation['type']] ?? (string) $relation['type_label'],
                'direction' => (string) $relation['direction'],
                'comment' => $relation['comment'] !== null ? (string) $relation['comment'] : null,
                'open_module' => null,
                'open_route' => null,
            ];
            if ($meta !== null && $meta['open_route'] !== null && $meta['module_usable']) {
                $item['open_module'] = $meta['module'];
                $item['open_route'] = str_replace('{key}', rawurlencode((string) $relation['other_key']), (string) $meta['open_route']);
            } elseif ($explorer) {
                $item['open_module'] = 'explorer';
                $item['open_route'] = 'info/' . rawurlencode((string) $relation['other_id']);
            }
            $groups[$moduleId] ??= ['module' => $moduleId, 'module_name' => $meta['module_name'] ?? $moduleId, 'icon' => $meta['module_icon'] ?? 'module', 'datasets' => []];
            $groups[$moduleId]['datasets'][$code] ??= ['code' => $code, 'name' => $meta['name'] ?? $code, 'items' => []];
            $groups[$moduleId]['datasets'][$code]['items'][] = $item;
            $count++;
        }
        ksort($groups);
        foreach ($groups as &$group) {
            $group['datasets'] = array_values($group['datasets']);
        }
        unset($group);
        return ['groups' => array_values($groups), 'hidden' => $hidden, 'count' => $count];
    }

    private function geoAvailable(): bool
    {
        return $this->ctx->modules()->has('geo') && $this->isVisibleCode(self::GEO_DATASET);
    }

    /** Points GPS reliés (service geo, relation « located_at »), vide si le module est absent ou non lisible. @return list<array<string, mixed>> */
    private function linkedPoints(string $infoId): array
    {
        if (!$this->geoAvailable()) {
            return [];
        }
        try {
            return $this->ctx->moduleService('geo')->pointsOf($infoId);
        } catch (ModuleUnavailableException | ForbiddenException) {
            return [];
        }
    }

    /**
     * Opérations du budget reliées. Le total n'est calculé que si le service du module Budget
     * expose une lecture par identifiant (méthode « transaction ») ; sinon simple liste des relations.
     *
     * @return array{available: bool, rows: list<array<string, mixed>>, total: ?int}
     */
    private function linkedBudget(string $infoId): array
    {
        if (!$this->ctx->modules()->has('budget') || !$this->isVisibleCode(self::BUDGET_DATASET)) {
            return ['available' => false, 'rows' => [], 'total' => null];
        }
        $service = null;
        try {
            $candidate = $this->ctx->moduleService('budget');
            $service = method_exists($candidate, 'transaction') ? $candidate : null;
        } catch (ModuleUnavailableException | ForbiddenException) {
            $service = null;
        }
        $explorer = $this->ctx->modules()->has('explorer');
        $rows = [];
        $total = $service === null ? null : 0;
        foreach ($this->ctx->shared->relations->relationsOf($infoId) as $relation) {
            if ((string) $relation['other_dataset'] !== self::BUDGET_DATASET) {
                continue;
            }
            $row = [
                'relation_id' => (int) $relation['id'],
                'info_id' => (string) $relation['other_id'],
                'key' => (string) $relation['other_key'],
                'label' => (string) ($relation['other_label'] ?? $relation['other_key']),
                'amount' => null,
                'open_route' => $explorer ? 'info/' . rawurlencode((string) $relation['other_id']) : null,
            ];
            if ($service !== null) {
                try {
                    $transaction = $service->transaction($this->ctx->userId(), (int) $relation['other_key']);
                    if (is_array($transaction) && isset($transaction['amount'])) {
                        $row['amount'] = (int) $transaction['amount'];
                        $total += (int) $transaction['amount'];
                    }
                } catch (\Throwable) {
                    $total = null;
                }
            }
            $rows[] = $row;
        }
        return ['available' => true, 'rows' => $rows, 'total' => $total];
    }

    /** @return list<string> */
    private function parseTags(mixed $input): array
    {
        $parts = is_array($input) ? $input : explode(',', is_scalar($input) ? (string) $input : '');
        $tags = [];
        $seen = [];
        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }
            $name = trim(ltrim(trim((string) $part), '#'));
            $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
            $key = Str::normalizeTag($name);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $tags[] = $name;
        }
        return $tags;
    }

    private function banner(string $title, string $subtitle, string $actions = '', string $icon = 'folder'): string
    {
        return $this->renderCore('banner', ['icon' => $icon, 'title' => $title, 'subtitle' => $subtitle, 'actions' => $actions]);
    }

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        $plural ??= $singular . 's';
        return $count . ' ' . ($count === 1 ? $singular : $plural);
    }
}
