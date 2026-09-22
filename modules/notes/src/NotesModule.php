<?php

declare(strict_types=1);

namespace Atelier\Modules\Notes;

use Atelier\Error\ConflictException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Shared\TagService;
use Atelier\Support\Str;

/**
 * Bloc-notes : notes strictement personnelles (chaque requête est filtrée par propriétaire),
 * tags partagés, corbeille avec rétention, et vue d'assistance (lecture seule, journalisée)
 * pour les utilisateurs disposant de la permission propre « assist ».
 */
final class NotesModule extends AbstractModule
{
    public const DATASET = 'notes.note';
    public const PER_PAGE = 25;
    public const TITLE_MAX = 200;
    public const CONTENT_MAX = 100000;
    public const TAG_MAX = 60;
    public const TAGS_MAX_COUNT = 20;
    public const EXCERPT_LENGTH = 120;

    private ?NoteRepository $repository = null;

    public function routes(RouteCollection $r): void
    {
        // Vues
        $r->view('list', [$this, 'listNotes'], permission: 'open');
        $r->view('new', [$this, 'newNote'], permission: 'create');
        $r->view('edit/{id}', [$this, 'editNote'], permission: 'open');
        $r->view('trash', [$this, 'trashView'], permission: 'open');
        $r->view('assist', [$this, 'assistView'], permission: 'assist');

        // Actions (POST). « save » distingue création et modification dans le gestionnaire.
        $r->action('search', [$this, 'search'], permission: 'open');
        $r->action('save', [$this, 'save'], permission: 'open');
        $r->action('delete', [$this, 'deleteNote'], permission: 'delete');
        $r->action('restore', [$this, 'restoreNote'], permission: 'update');
        $r->action('purge', [$this, 'purgeNote'], permission: 'delete');
    }

    // =====================================================================
    // Vues
    // =====================================================================

    public function listNotes(Request $request, array $params): ModuleView
    {
        $userId = $this->ctx->userId();
        $search = trim((string) $request->query('search', ''));
        $sort = (string) $request->query('sort', 'updated_at');
        if (!NoteRepository::isSortable($sort)) {
            $sort = 'updated_at';
        }
        $direction = strtolower((string) $request->query('dir', $sort === 'title' ? 'asc' : 'desc')) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) $request->query('page', 1));

        $result = $this->repo()->paginate($userId, $search, $page, self::PER_PAGE, $sort, $direction);
        $notes = $this->decorate($result['rows']);
        $total = $result['total'];
        $countAll = $search === '' ? $total : $this->repo()->countActive($userId);
        $rights = $this->rights(['create', 'update', 'delete', 'assist']);

        $query = array_filter(['search' => $search, 'sort' => $sort, 'dir' => $direction], static fn ($v): bool => $v !== '' && $v !== null);
        $content = $this->render('list', [
            'notes' => $notes,
            'total' => $total,
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'query' => $query,
            'rights' => $rights,
        ]);

        return ModuleView::make('Mes notes')
            ->banner($this->banner('Bloc-notes', $this->plural($countAll, 'note'), $this->listActions($rights)))
            ->content($content)
            ->status($total === 0 ? 'Aucune note' : $this->plural(count($notes), 'note affichée', 'notes affichées') . ' sur ' . $total);
    }

    public function newNote(Request $request, array $params): ModuleView
    {
        $note = [
            'id' => null,
            'title' => '',
            'content' => '',
            'created_at' => null,
            'updated_at' => null,
        ];
        return $this->editorView($note, [], ['update' => true, 'delete' => false], true);
    }

    public function editNote(Request $request, array $params): ModuleView
    {
        $userId = $this->ctx->userId();
        $id = (int) ($params['id'] ?? 0);
        $note = $this->repo()->find($id, $userId);
        if ($note === null) {
            // Peut-être en corbeille : on oriente l'utilisateur.
            if ($this->repo()->findTrashed($id, $userId) !== null) {
                throw new NotFoundException('Cette note est dans la corbeille : restaurez-la pour la modifier.');
            }
            throw new NotFoundException('Note introuvable.');
        }
        $rights = $this->rights(['update', 'delete']);
        return $this->editorView($note, $this->tagNamesOf($id), $rights, false);
    }

    public function trashView(Request $request, array $params): ModuleView
    {
        $userId = $this->ctx->userId();
        $retention = $this->retentionDays();
        $notes = $this->repo()->trashed($userId, $retention);
        foreach ($notes as &$note) {
            $deletedAt = \Atelier\Support\Clock::parseUtc($note['deleted_at']);
            $note['expires_in_days'] = $deletedAt === null ? 0 : max(0, $retention - (int) $deletedAt->diff(\Atelier\Support\Clock::now())->days);
            $note['excerpt'] = $this->excerpt((string) ($note['content'] ?? ''));
        }
        unset($note);
        $rights = $this->rights(['update', 'delete']);

        $actions = '<a class="btn" href="#" data-route="list"><svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg> Mes notes</a>';
        return ModuleView::make('Corbeille')
            ->banner($this->banner('Corbeille', $this->plural(count($notes), 'note supprimée', 'notes supprimées'), $actions))
            ->content($this->render('trash', ['notes' => $notes, 'retention' => $retention, 'rights' => $rights]))
            ->status($this->plural(count($notes), 'note en corbeille', 'notes en corbeille'));
    }

    /**
     * Assistance : lecture seule des notes d'un autre utilisateur (assist?user=ID[&note=ID][&page=N]).
     * Chaque consultation est journalisée (notes.assist_read), sans le contenu.
     */
    public function assistView(Request $request, array $params): ModuleView
    {
        // Contrôle explicite en plus de la route : l'admin racine l'obtient par héritage de « admin ».
        $this->require('assist');
        $viewerId = $this->ctx->userId();
        $targetId = (int) $request->query('user', 0);
        $noteId = (int) $request->query('note', 0);
        $page = max(1, (int) $request->query('page', 1));

        $users = array_values(array_filter($this->ctx->users->all(), static fn (array $u): bool => (int) $u['id'] !== $viewerId));
        $target = null;
        foreach ($users as $candidate) {
            if ((int) $candidate['id'] === $targetId) {
                $target = $candidate;
                break;
            }
        }

        $vars = ['users' => $users, 'target' => $target, 'notes' => [], 'note' => null, 'tags' => [], 'total' => 0, 'page' => $page, 'perPage' => self::PER_PAGE];
        $subtitle = 'Lecture seule des notes d’un autre utilisateur';
        $status = 'Sélectionnez un utilisateur';

        if ($target !== null) {
            $ownerId = (int) $target['id'];
            if ($noteId > 0) {
                $note = $this->repo()->find($noteId, $ownerId);
                if ($note === null) {
                    throw new NotFoundException('Note introuvable pour cet utilisateur.');
                }
                $vars['note'] = $note;
                $vars['tags'] = $this->tagNamesOf($noteId);
                $this->log('notes.assist_read', 'success', 'note:' . $noteId, 'Lecture d’assistance d’une note de ' . $target['username'], ['owner_id' => $ownerId, 'title_length' => mb_strlen((string) $note['title'], 'UTF-8')]);
                $subtitle = 'Note de ' . $target['display_name'];
                $status = 'Lecture seule — note n° ' . $noteId;
            } else {
                $result = $this->repo()->paginate($ownerId, '', $page, self::PER_PAGE, 'updated_at', 'desc');
                $vars['notes'] = $this->decorate($result['rows']);
                $vars['total'] = $result['total'];
                $this->log('notes.assist_read', 'success', 'user:' . $ownerId, 'Consultation d’assistance de la liste des notes de ' . $target['username'], ['count' => $result['total'], 'page' => $page]);
                $subtitle = $this->plural($result['total'], 'note') . ' de ' . $target['display_name'];
                $status = 'Lecture seule — ' . $this->plural(count($vars['notes']), 'note affichée', 'notes affichées');
            }
        }

        $actions = '<a class="btn" href="#" data-route="list"><svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg> Mes notes</a>';
        return ModuleView::make('Assistance')
            ->banner($this->banner('Assistance', $subtitle, $actions, 'eye'))
            ->content($this->render('assist', $vars))
            ->status($status);
    }

    // =====================================================================
    // Actions
    // =====================================================================

    /** Formulaire de recherche : redirige vers la liste filtrée (tri conservé, page remise à 1). */
    public function search(Request $request, array $params): ActionResult
    {
        $query = array_filter([
            'search' => $request->string('search'),
            'sort' => $request->string('sort'),
            'dir' => $request->string('dir'),
        ], static fn (string $v): bool => $v !== '');
        return ActionResult::ok()->navigate('list' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    public function save(Request $request, array $params): ActionResult
    {
        $userId = $this->ctx->userId();
        $id = $request->int('id');
        $title = $request->string('title');
        $rawContent = $request->input('content', '');
        $content = is_scalar($rawContent) ? str_replace(["\r\n", "\r"], "\n", (string) $rawContent) : '';
        $tags = $this->parseTags($request->input('tags', ''));

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Le titre est obligatoire.';
        } elseif (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            $errors['title'] = 'Le titre ne doit pas dépasser ' . self::TITLE_MAX . ' caractères.';
        }
        if (mb_strlen($content, 'UTF-8') > self::CONTENT_MAX) {
            $errors['content'] = 'Le contenu ne doit pas dépasser ' . number_format(self::CONTENT_MAX, 0, ',', ' ') . ' caractères.';
        }
        if (count($tags) > self::TAGS_MAX_COUNT) {
            $errors['tags'] = 'Au maximum ' . self::TAGS_MAX_COUNT . ' tags par note.';
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

        $isNew = $id === null || $id <= 0;
        if ($isNew) {
            $this->require('create');
        } else {
            $this->require('update');
            $existing = $this->repo()->find($id, $userId);
            if ($existing === null) {
                throw new NotFoundException('Note introuvable ou déjà supprimée.');
            }
            // Contrôle de concurrence : l'horodatage envoyé par le formulaire doit être celui de la base.
            $sentUpdatedAt = $request->string('updated_at');
            if ($sentUpdatedAt !== '' && $sentUpdatedAt !== (string) $existing['updated_at']) {
                throw new ConflictException('Cette note a été modifiée entre-temps (autre onglet ou autre session). Rechargez-la avant d’enregistrer à nouveau.');
            }
        }

        $noteId = $this->ctx->db->transaction(function () use ($isNew, $id, $userId, $title, $content, $tags): int {
            $noteId = $isNew ? $this->repo()->create($userId, $title, $content) : (int) $id;
            if (!$isNew) {
                $this->repo()->update($noteId, $userId, $title, $content);
            }
            $infoId = $this->ctx->shared->registry->register(self::DATASET, (string) $noteId, $title, $userId);
            $this->ctx->shared->tags->replace($infoId, $tags, TagService::SHARED, $userId);
            return $noteId;
        });

        $this->log($isNew ? 'notes.create' : 'notes.update', 'success', 'note:' . $noteId, $isNew ? 'Note créée' : 'Note modifiée', ['tags' => count($tags)]);

        return ActionResult::ok(['id' => $noteId], 'Note enregistrée.')->navigate('edit/' . $noteId)->dirty(false);
    }

    public function deleteNote(Request $request, array $params): ActionResult
    {
        $userId = $this->ctx->userId();
        $id = (int) ($request->int('id') ?? 0);
        if ($id <= 0 || $this->repo()->find($id, $userId) === null) {
            throw new NotFoundException('Note introuvable ou déjà supprimée.');
        }
        $this->repo()->softDelete($id, $userId);
        $this->log('notes.delete', 'success', 'note:' . $id, 'Note placée dans la corbeille');
        return ActionResult::ok(['id' => $id], 'Note placée dans la corbeille.')->navigate('list')->dirty(false);
    }

    public function restoreNote(Request $request, array $params): ActionResult
    {
        $userId = $this->ctx->userId();
        $id = (int) ($request->int('id') ?? 0);
        if ($id <= 0 || $this->repo()->findTrashed($id, $userId) === null) {
            throw new NotFoundException('Cette note n’est pas dans la corbeille.');
        }
        $this->repo()->restore($id, $userId);
        $this->log('notes.restore', 'success', 'note:' . $id, 'Note restaurée depuis la corbeille');
        return ActionResult::ok(['id' => $id], 'Note restaurée.')->refresh();
    }

    public function purgeNote(Request $request, array $params): ActionResult
    {
        $userId = $this->ctx->userId();
        $id = (int) ($request->int('id') ?? 0);
        if ($id <= 0 || $this->repo()->findTrashed($id, $userId) === null) {
            throw new NotFoundException('Cette note n’est pas dans la corbeille.');
        }
        $this->ctx->db->transaction(function () use ($id, $userId): void {
            $this->repo()->purge($id, $userId);
            $this->ctx->shared->registry->unregister(self::DATASET, (string) $id);
        });
        $this->log('notes.purge', 'success', 'note:' . $id, 'Note supprimée définitivement');
        return ActionResult::ok(['id' => $id], 'Note supprimée définitivement.')->refresh();
    }

    // =====================================================================
    // Hooks
    // =====================================================================

    /** Données de démonstration : 3 notes pour alice, 2 pour admin, si la table est vide. */
    public function seed(): string
    {
        if ($this->repo()->countAll() > 0) {
            return 'notes d’exemple déjà présentes';
        }
        $samples = [
            'alice' => [
                ['Bienvenue dans le Bloc-notes', "Cette note est un exemple.\n\nUtilisez Ctrl+S pour enregistrer, la barre de recherche pour retrouver une note et les tags pour la classer."],
                ['Idées pour l’atelier', "- Préparer la démonstration\n- Relire le cahier des charges\n- Lister les questions ouvertes"],
                ['Liste de courses', "Café, papier, stylos, piles AA."],
            ],
            'admin' => [
                ['Procédure de sauvegarde', "Lancer `console backup:create` chaque vendredi, puis vérifier la taille de l’archive dans var/backups."],
                ['Comptes de démonstration', "alice, bruno et claire utilisent le mot de passe de démonstration. Bruno n’a pas accès au chat (exemple de refus explicite)."],
            ],
        ];
        $created = 0;
        foreach ($samples as $username => $notes) {
            $user = $this->ctx->users->findByUsername($username);
            if ($user === null) {
                continue;
            }
            $ownerId = (int) $user['id'];
            foreach ($notes as [$title, $content]) {
                $id = $this->repo()->create($ownerId, $title, $content);
                $infoId = $this->ctx->shared->registry->register(self::DATASET, (string) $id, $title, $ownerId);
                $this->ctx->shared->tags->replace($infoId, ['exemple', 'atelier'], TagService::SHARED, $ownerId);
                $created++;
            }
        }
        return $created . ' note(s) d’exemple créée(s)';
    }

    /** Rétention : suppression physique des notes en corbeille depuis plus de N jours. */
    public function purge(): string
    {
        $days = $this->retentionDays();
        $ids = $this->repo()->expiredTrashIds($days);
        foreach ($ids as $id) {
            $this->ctx->db->transaction(function () use ($id): void {
                $this->repo()->deleteById($id);
                $this->ctx->shared->registry->unregister(self::DATASET, (string) $id);
            });
            $this->log('notes.purge', 'success', 'note:' . $id, 'Note purgée par la rétention (' . $days . ' jours)');
        }
        return count($ids) . ' note(s) purgée(s) de la corbeille (> ' . $days . ' jours)';
    }

    // =====================================================================
    // Helpers privés
    // =====================================================================

    private function repo(): NoteRepository
    {
        return $this->repository ??= new NoteRepository($this->ctx->db);
    }

    private function retentionDays(): int
    {
        return max(1, $this->ctx->config->int('trash.retention_days', 30));
    }

    /**
     * Vue de l'éditeur (création ou modification).
     *
     * @param array<string, mixed> $note
     * @param list<string> $tags
     * @param array<string, bool> $rights
     */
    private function editorView(array $note, array $tags, array $rights, bool $isNew): ModuleView
    {
        $content = $this->render('edit', [
            'note' => $note,
            'tags' => $tags,
            'isNew' => $isNew,
            'canUpdate' => (bool) ($rights['update'] ?? false),
            'canDelete' => !$isNew && (bool) ($rights['delete'] ?? false),
            'titleMax' => self::TITLE_MAX,
            'contentMax' => self::CONTENT_MAX,
        ]);
        $title = $isNew ? 'Nouvelle note' : (string) $note['title'];
        $subtitle = $isNew ? 'Rédaction d’une nouvelle note' : 'Modifiée le ' . \Atelier\Support\Clock::formatDateTime($note['updated_at'] ?? null);
        $actions = '<a class="btn" href="#" data-route="list"><svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg> Mes notes</a>';
        return ModuleView::make($title)
            ->banner($this->banner($title, $subtitle, $actions, $isNew ? 'plus' : 'edit'))
            ->content($content)
            ->status($isNew ? 'Nouvelle note (Ctrl+S pour enregistrer)' : ($rights['update'] ?? false ? 'Ctrl+S pour enregistrer' : 'Lecture seule'));
    }

    /** @param array<string, bool> $rights */
    private function listActions(array $rights): string
    {
        $html = '';
        if ($rights['create'] ?? false) {
            $html .= '<a class="btn btn--primary" href="#" data-route="new"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg> Nouvelle note</a>';
        }
        $html .= '<a class="btn" href="#" data-route="trash" title="Notes supprimées récemment"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Corbeille</a>';
        if ($rights['assist'] ?? false) {
            $html .= '<a class="btn btn--ghost" href="#" data-route="assist" title="Lire les notes d’un autre utilisateur (journalisé)"><svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg> Assistance</a>';
        }
        return $html;
    }

    private function banner(string $title, string $subtitle, string $actions = '', string $icon = 'note'): string
    {
        return $this->renderCore('banner', ['icon' => $icon, 'title' => $title, 'subtitle' => $subtitle, 'actions' => $actions]);
    }

    /**
     * Ajoute extrait et tags aux lignes de notes.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['excerpt'] = $this->excerpt((string) ($row['content'] ?? ''));
            $row['tags'] = $this->tagNamesOf((int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    private function excerpt(string $content): string
    {
        $plain = \Atelier\View\BbCode::toText($content);
        $flat = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
        return Str::truncate($flat, self::EXCERPT_LENGTH);
    }

    /** @return list<string> noms des tags partagés d'une note */
    private function tagNamesOf(int $noteId): array
    {
        $info = $this->ctx->shared->registry->find(self::DATASET, (string) $noteId);
        if ($info === null) {
            return [];
        }
        return array_map(static fn (array $t): string => (string) $t['name'], $this->ctx->shared->tags->tagsOf((string) $info['id'], TagService::SHARED));
    }

    /**
     * Découpe une saisie « a, b, c » (ou un tableau) en liste de tags distincts, sans vides.
     *
     * @return list<string>
     */
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

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        $plural ??= $singular . 's';
        return $count . ' ' . ($count === 1 ? $singular : $plural);
    }
}
