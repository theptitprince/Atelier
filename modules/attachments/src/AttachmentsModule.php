<?php

declare(strict_types=1);

namespace Atelier\Modules\Attachments;

use Atelier\Error\ForbiddenException;
use Atelier\Error\ModuleUnavailableException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleContext;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Shared\AttachmentService;
use Atelier\Support\Str;

/**
 * Module « Fichiers joints » : interface du mécanisme transversal de pièces jointes du noyau.
 *
 * Les fichiers sont stockés chiffrés (AES-256-GCM) hors du répertoire public sous un nom interne
 * imprévisible ; leur téléchargement passe par PHP, après contrôle des droits du module et des
 * règles d'accès par fichier (AttachmentAccess), et est journalisé. Un fichier peut être rattaché
 * à toute information inscrite au registre commun (point GPS, fiche d'un autre module…).
 */
final class AttachmentsModule extends AbstractModule
{
    private const PER_PAGE_CHOICES = [25, 50, 100];
    private const DESCRIPTION_MAX = 500;
    private const NAME_MAX = 200;

    public function routes(RouteCollection $r): void
    {
        $r->view('list', [$this, 'list'], permission: 'open');
        $r->view('all', [$this, 'all'], permission: 'assist');
        $r->view('upload', [$this, 'uploadForm'], permission: 'create');
        $r->view('show/{id}', [$this, 'show'], permission: 'open');
        $r->view('trash', [$this, 'trash'], permission: 'delete');

        $r->action('filter', [$this, 'filter'], permission: 'open');
        $r->action('upload', [$this, 'upload'], permission: 'create');
        $r->action('rename', [$this, 'rename'], permission: 'update');
        $r->action('link', [$this, 'link'], permission: 'update');
        $r->action('unlink', [$this, 'unlink'], permission: 'update');
        $r->action('search-info', [$this, 'searchInfo'], permission: 'update');
        $r->action('delete', [$this, 'delete'], permission: 'delete');
        $r->action('restore', [$this, 'restore'], permission: 'delete');
        $r->action('purge', [$this, 'destroy'], permission: 'delete');
        $r->action('verify', [$this, 'verify'], permission: 'open');

        $r->raw('download/{id}', [$this, 'download'], permission: 'read');
    }

    /** Données de démonstration : un fichier texte de bienvenue pour le compte administrateur. */
    public function seed(): string
    {
        $service = $this->ctx->shared->attachments;
        if ($service->stats()['count'] > 0) {
            return 'fichiers déjà présents';
        }
        $admin = $this->ctx->users->findByUsername('admin');
        $content = "Bienvenue dans Atelier.\r\n\r\nCe fichier de démonstration a été téléversé par les données d’exemple.\r\n"
            . "Les fichiers joints sont chiffrés au repos et téléchargés uniquement après contrôle des droits.\r\n";
        $service->storeContent($content, 'bienvenue.txt', null, $admin !== null ? (int) $admin['id'] : null, 'Fichier de démonstration');
        return '1 fichier d’exemple créé';
    }

    // =====================================================================
    // Vues
    // =====================================================================

    public function list(Request $request, array $params): ModuleView
    {
        return $this->listView($request, 'mine');
    }

    public function all(Request $request, array $params): ModuleView
    {
        return $this->listView($request, 'all');
    }

    public function uploadForm(Request $request, array $params): ModuleView
    {
        $target = $this->resolveUploadTarget($request);
        $service = $this->ctx->shared->attachments;
        $content = $this->render('upload', [
            'target' => $target,
            'maxFileSize' => $service->maxFileSize(),
            'allowedMimes' => $service->allowedMimes(),
            'remaining' => max(0, $service->maxPerUser() - $service->usageOfUser($this->ctx->userId())),
        ]);
        $banner = $this->renderCore('banner', [
            'icon' => 'upload',
            'title' => 'Téléverser des fichiers',
            'subtitle' => $target !== null ? 'Rattachés à « ' . $target['label'] . ' »' : Str::humanSize($service->maxFileSize()) . ' au plus par fichier',
            'actions' => '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Mes fichiers</span></a>',
        ]);
        return ModuleView::make('Téléverser des fichiers')->banner($banner)->content($content)->status('Téléversement');
    }

    public function show(Request $request, array $params): ModuleView
    {
        $attachment = $this->requireReadable((string) ($params['id'] ?? ''), true);
        $access = $this->access();
        $rights = $this->rights(['read', 'update', 'delete']);
        $manage = $access->canManage($attachment);
        $content = $this->render('show', [
            'attachment' => $attachment,
            'kind' => AttachmentService::kindOf((string) $attachment['mime']),
            'kinds' => AttachmentService::KINDS,
            'canDownload' => $rights['read'],
            'canManage' => $manage,
            'canUpdate' => $manage && $rights['update'],
            'canDelete' => $manage && $rights['delete'],
            'inline' => in_array($attachment['mime'], AttachmentService::INLINE_MIMES, true),
            'downloadUrl' => $this->url('download/' . $attachment['id']),
            'encryption' => $this->ctx->shared->attachments->isEncryptionEnabled(),
            'openModule' => $attachment['info_module'] !== null && $this->ctx->modules()->has((string) $attachment['info_module']),
        ]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="' . ($attachment['deleted_at'] !== null ? 'trash' : 'list') . '">' . $this->icon('chevron-left') . '<span>Retour</span></a>';
        if ($rights['read'] && $attachment['deleted_at'] === null) {
            $actions .= '<a class="btn btn--primary" href="' . $this->e($this->url('download/' . $attachment['id'])) . '" download>' . $this->icon('download') . '<span>Télécharger</span></a>';
        }
        $banner = $this->renderCore('banner', [
            'icon' => 'paperclip',
            'title' => (string) $attachment['original_name'],
            'subtitle' => Str::humanSize((int) $attachment['size']) . ' · ' . $attachment['mime'] . ($attachment['deleted_at'] !== null ? ' · en corbeille' : ''),
            'actions' => $actions,
        ]);
        return ModuleView::make('Fichier · ' . $attachment['original_name'])->banner($banner)->content($content)->status((string) $attachment['original_name']);
    }

    public function trash(Request $request, array $params): ModuleView
    {
        $access = $this->access();
        $filters = ['deleted' => true];
        if (!$access->isAssist()) {
            $filters['uploaded_by'] = $this->ctx->userId();
        }
        $result = $this->ctx->shared->attachments->paginate($filters, 1, 500, 'deleted_at', 'desc');
        $days = $this->ctx->config->int('trash.retention_days', 30);
        $content = $this->render('trash', ['rows' => $result['rows'], 'retentionDays' => $days, 'assist' => $access->isAssist()]);
        $banner = $this->renderCore('banner', [
            'icon' => 'trash',
            'title' => 'Corbeille des fichiers',
            'subtitle' => $result['total'] . ' fichier(s) · purge automatique après ' . $days . ' jours',
            'actions' => '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Mes fichiers</span></a>',
        ]);
        return ModuleView::make('Corbeille · fichiers')->banner($banner)->content($content)->status($result['total'] . ' fichier(s) en corbeille');
    }

    // =====================================================================
    // Actions
    // =====================================================================

    public function filter(Request $request, array $params): ActionResult
    {
        $scope = $request->string('scope') === 'all' && $this->can('assist') ? 'all' : 'mine';
        $query = $this->listQuery($request->all());
        $query['page'] = 1;
        return ActionResult::ok()->navigate($this->listRoute($scope, $query));
    }

    /**
     * Téléversement d'un ou plusieurs fichiers (champ files[] ou file), avec description et
     * rattachement facultatifs. Chaque fichier est traité indépendamment : les refus sont rapportés.
     */
    public function upload(Request $request, array $params): ActionResult
    {
        $uploads = array_merge($request->fileList('files'), $request->fileList('file'));
        if ($uploads === []) {
            throw ValidationException::single('files', 'Choisissez au moins un fichier.');
        }
        $description = $this->validateDescription($request->string('description'));
        $infoId = $this->validateInfoId($request->string('info_id'));
        $info = $infoId !== null ? $this->ctx->shared->registry->get($infoId) : null;

        $service = $this->ctx->shared->attachments;
        $userId = $this->ctx->userId();
        $stored = [];
        $errors = [];
        foreach ($uploads as $upload) {
            $name = (string) ($upload['name'] ?? 'fichier');
            try {
                $record = $service->store($upload, $infoId, $userId, $description);
                $stored[] = ['id' => $record['id'], 'name' => $record['original_name'], 'size' => (int) $record['size'], 'mime' => $record['mime']];
                $this->log('attachment.upload', 'success', 'attachment:' . $record['id'], 'Fichier téléversé : ' . $record['original_name'], [
                    'size' => (int) $record['size'],
                    'mime' => $record['mime'],
                    'sha256' => $record['sha256'],
                    'encrypted' => $record['cipher'] !== null,
                    'info_id' => $infoId,
                ]);
            } catch (ValidationException $e) {
                $message = implode(' ', $e->fieldErrors()) ?: $e->getMessage();
                $errors[] = ['name' => $name, 'message' => $message];
                $this->log('attachment.upload', 'failure', null, 'Fichier refusé : ' . $name, ['reason' => $message]);
            }
        }
        $summary = count($stored) . ' fichier(s) téléversé(s)' . ($errors !== [] ? ', ' . count($errors) . ' refusé(s)' : '') . ($info !== null ? ' et rattaché(s) à « ' . ($info['label'] ?? $infoId) . ' »' : '') . '.';
        $data = ['files' => $stored, 'errors' => $errors];
        if ($stored === []) {
            throw new ValidationException(['files' => $errors[0]['message'] ?? 'Aucun fichier accepté.'], $summary);
        }
        return $errors === [] ? ActionResult::ok($data, $summary) : ActionResult::warning($data, $summary);
    }

    public function rename(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'));
        $name = $request->string('name');
        if ($name === '' || mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw ValidationException::single('name', sprintf('Le nom est obligatoire et ne peut dépasser %d caractères.', self::NAME_MAX));
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower(pathinfo((string) $attachment['original_name'], PATHINFO_EXTENSION))) {
            throw ValidationException::single('name', 'L’extension du fichier ne peut pas être modifiée.');
        }
        $description = $this->validateDescription($request->string('description'));
        $this->ctx->shared->attachments->rename((string) $attachment['id'], $name, $description);
        $this->log('attachment.rename', 'success', 'attachment:' . $attachment['id'], 'Fichier renommé : ' . $name, ['previous' => $attachment['original_name']]);
        return ActionResult::ok(['id' => $attachment['id']], 'Fichier enregistré.')->refresh();
    }

    public function link(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'));
        $infoId = $this->validateInfoId($request->string('info_id'));
        if ($infoId === null) {
            throw ValidationException::single('info_id', 'Choisissez une information à rattacher.');
        }
        $info = $this->ctx->shared->registry->get($infoId);
        $this->ctx->shared->attachments->attach((string) $attachment['id'], $infoId);
        $this->log('attachment.link', 'success', 'attachment:' . $attachment['id'], 'Fichier rattaché à « ' . ($info['label'] ?? $infoId) . ' »', ['info_id' => $infoId, 'dataset' => $info['dataset_code'] ?? null]);
        return ActionResult::ok(['id' => $attachment['id']], 'Fichier rattaché à « ' . ($info['label'] ?? 'l’information') . ' ».')->refresh();
    }

    public function unlink(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'));
        $this->ctx->shared->attachments->attach((string) $attachment['id'], null);
        $this->log('attachment.unlink', 'success', 'attachment:' . $attachment['id'], 'Rattachement retiré', ['info_id' => $attachment['info_id']]);
        return ActionResult::ok(['id' => $attachment['id']], 'Rattachement retiré.')->refresh();
    }

    /** Recherche d'informations rattachables dans les jeux partagés lisibles. */
    public function searchInfo(Request $request, array $params): ActionResult
    {
        $term = $request->string('q');
        if (mb_strlen($term, 'UTF-8') < 2) {
            return ActionResult::ok(['items' => []]);
        }
        $codes = $this->ctx->shared->catalog->readableCodes($this->ctx->userId());
        $items = [];
        foreach ($this->ctx->shared->registry->search($term, $codes, 15) as $row) {
            $items[] = ['id' => (string) $row['id'], 'label' => (string) ($row['label'] ?? $row['local_key']), 'dataset' => (string) $row['dataset_code'], 'module' => (string) $row['module_id']];
        }
        return ActionResult::ok(['items' => $items]);
    }

    public function delete(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'));
        $this->ctx->shared->attachments->softDelete((string) $attachment['id']);
        $this->log('attachment.delete', 'success', 'attachment:' . $attachment['id'], 'Fichier mis à la corbeille : ' . $attachment['original_name']);
        return ActionResult::ok(null, 'Fichier « ' . $attachment['original_name'] . ' » mis à la corbeille.')->navigate('list');
    }

    public function restore(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'), true);
        if ($attachment['deleted_at'] === null) {
            throw new NotFoundException('Ce fichier n’est pas dans la corbeille.');
        }
        $this->ctx->shared->attachments->restore((string) $attachment['id']);
        $this->log('attachment.restore', 'success', 'attachment:' . $attachment['id'], 'Fichier restauré : ' . $attachment['original_name']);
        return ActionResult::ok(null, 'Fichier « ' . $attachment['original_name'] . ' » restauré.')->refresh();
    }

    /** Suppression définitive d'un fichier de la corbeille (action « purge »). */
    public function destroy(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'), true);
        if ($attachment['deleted_at'] === null) {
            throw new NotFoundException('Ce fichier n’est pas dans la corbeille.');
        }
        if (!$this->ctx->shared->attachments->purge((string) $attachment['id'])) {
            throw new \RuntimeException('Le fichier n’a pas pu être supprimé du stockage.');
        }
        $this->log('attachment.purge', 'success', 'attachment:' . $attachment['id'], 'Fichier supprimé définitivement : ' . $attachment['original_name']);
        return ActionResult::ok(null, 'Fichier « ' . $attachment['original_name'] . ' » supprimé définitivement.')->refresh();
    }

    /** Contrôle d'intégrité à la demande (authentification du chiffré et empreinte). */
    public function verify(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireReadable($request->string('id'), true);
        $result = $this->ctx->shared->attachments->verify((string) $attachment['id']);
        $this->log('attachment.verify', $result['ok'] ? 'success' : 'failure', 'attachment:' . $attachment['id'], $result['message']);
        return $result['ok'] ? ActionResult::ok($result, 'Intégrité confirmée : ' . $result['message']) : ActionResult::warning($result, 'Intégrité compromise : ' . $result['message']);
    }

    // =====================================================================
    // Téléchargement
    // =====================================================================

    /** Téléchargement après contrôle des droits ; le contenu est déchiffré à la volée, jamais mis en cache. */
    public function download(Request $request, array $params): Response
    {
        $attachment = $this->requireReadable((string) ($params['id'] ?? ''));
        $inline = $request->query('inline') === '1';
        $service = $this->ctx->shared->attachments;
        $service->recordDownload((string) $attachment['id']);
        $this->log('attachment.download', 'success', 'attachment:' . $attachment['id'], (string) $attachment['original_name'], ['inline' => $inline, 'size' => (int) $attachment['size']]);
        return $service->download((string) $attachment['id'], $inline);
    }

    // =====================================================================
    // Helpers publics (gabarits)
    // =====================================================================

    public function icon(string $name, string $extra = ''): string
    {
        return '<svg class="icon' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    public static function kindIcon(string $mime): string
    {
        return match (AttachmentService::kindOf($mime)) {
            'image' => 'image',
            'pdf' => 'book',
            'document' => 'note',
            'text' => 'list',
            default => 'file',
        };
    }

    public function humanSize(int $bytes): string
    {
        return Str::humanSize($bytes);
    }

    // =====================================================================
    // Interne
    // =====================================================================

    private function listView(Request $request, string $scope): ModuleView
    {
        $access = $this->access();
        $service = $this->ctx->shared->attachments;
        $userId = $this->ctx->userId();
        $query = $this->listQuery($request->allQuery());
        $filters = ['search' => $query['q'], 'kind' => $query['kind']];
        if ($query['linked'] !== '') {
            $filters['linked'] = $query['linked'] === '1';
        }
        if ($scope === 'mine') {
            $filters['uploaded_by'] = $userId;
        }
        $result = $service->paginate($filters, $query['page'], $query['per_page'], $query['sort'], $query['dir']);
        $rights = $this->rights(['read', 'create', 'update', 'delete']);
        $mine = $service->stats($userId);
        $global = $access->isAssist() ? $service->stats() : null;

        $content = $this->render('list', [
            'rows' => $result['rows'],
            'total' => (int) $result['total'],
            'query' => $query,
            'scope' => $scope,
            'rights' => $rights,
            'assist' => $access->isAssist(),
            'currentUserId' => $userId,
            'kinds' => AttachmentService::KINDS,
            'perPageChoices' => self::PER_PAGE_CHOICES,
            'route' => $this->listRoute($scope, $query),
            'usage' => ['mine' => $mine, 'quota' => $service->maxPerUser(), 'global' => $global, 'max_total' => $service->maxTotal()],
            'encryption' => $service->isEncryptionEnabled(),
        ]);

        $actions = '<a class="btn btn--ghost" href="#" data-route="' . $this->e($this->listRoute($scope, $query)) . '" title="Actualiser">' . $this->icon('refresh') . '<span>Actualiser</span></a>';
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="upload">' . $this->icon('upload') . '<span>Téléverser</span></a>';
        }
        $title = $scope === 'all' ? 'Tous les fichiers' : 'Mes fichiers';
        $subtitle = sprintf('%d fichier(s)%s · %s utilisés sur %s', $result['total'], $query['q'] !== '' || $query['kind'] !== '' || $query['linked'] !== '' ? ' (filtrés)' : '', Str::humanSize($mine['size']), Str::humanSize($service->maxPerUser()));
        $banner = $this->renderCore('banner', ['icon' => 'paperclip', 'title' => $title, 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make($title)->banner($banner)->content($content)->status($subtitle)->route($this->listRoute($scope, $query));
    }

    /**
     * Cible de rattachement d'un téléversement : ?info=<uuid> ou ?point=<id> (module geo).
     *
     * @return array{id: string, label: string, dataset: string, module: string}|null
     */
    private function resolveUploadTarget(Request $request): ?array
    {
        $infoId = trim((string) $request->query('info', ''));
        $pointId = (int) $request->query('point', 0);
        if ($infoId === '' && $pointId > 0) {
            try {
                $geo = $this->ctx->moduleService('geo');
                $infoId = $geo->infoId($pointId);
            } catch (ModuleUnavailableException | NotFoundException | ForbiddenException) {
                return null;
            }
        }
        if ($infoId === '') {
            return null;
        }
        $info = $this->ctx->shared->registry->get($infoId);
        if ($info === null || !$this->access()->canLinkTo($info)) {
            return null;
        }
        return ['id' => (string) $info['id'], 'label' => (string) ($info['label'] ?? $info['local_key']), 'dataset' => (string) $info['dataset_code'], 'module' => (string) $info['module_id']];
    }

    private function validateDescription(string $description): ?string
    {
        if (mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX) {
            throw ValidationException::single('description', sprintf('La description ne peut dépasser %d caractères.', self::DESCRIPTION_MAX));
        }
        return $description !== '' ? $description : null;
    }

    /** Vérifie qu'un identifiant de registre est rattachable par l'utilisateur ; null si vide. */
    private function validateInfoId(string $infoId): ?string
    {
        if ($infoId === '') {
            return null;
        }
        if (!preg_match('/^[0-9a-f-]{36}$/', $infoId)) {
            throw ValidationException::single('info_id', 'Information invalide.');
        }
        $info = $this->ctx->shared->registry->get($infoId);
        if ($info === null) {
            throw ValidationException::single('info_id', 'Information introuvable dans le registre commun.');
        }
        if (!$this->access()->canLinkTo($info)) {
            throw ValidationException::single('info_id', 'Vous ne pouvez pas rattacher de fichier à cette information.');
        }
        return $infoId;
    }

    /** @return array<string, mixed> */
    private function requireReadable(string $id, bool $includeDeleted = false): array
    {
        $attachment = $this->requireAttachment($id, $includeDeleted);
        if (!$this->access()->canRead($attachment)) {
            $this->log('attachment.access', 'denied', 'attachment:' . $attachment['id'], 'Accès refusé au fichier');
            throw new ForbiddenException('Vous ne pouvez pas accéder à ce fichier.', $this->resource(), 'read');
        }
        return $attachment;
    }

    /** @return array<string, mixed> */
    private function requireManageable(string $id, bool $includeDeleted = false): array
    {
        $attachment = $this->requireAttachment($id, $includeDeleted);
        if (!$this->access()->canManage($attachment)) {
            $this->log('attachment.access', 'denied', 'attachment:' . $attachment['id'], 'Gestion refusée du fichier');
            throw new ForbiddenException('Vous ne pouvez pas gérer ce fichier : il appartient à un autre utilisateur.', $this->resource(), 'update');
        }
        return $attachment;
    }

    /** @return array<string, mixed> */
    private function requireAttachment(string $id, bool $includeDeleted): array
    {
        $attachment = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->ctx->shared->attachments->find($id, $includeDeleted) : null;
        if ($attachment === null) {
            throw new NotFoundException('Fichier introuvable.');
        }
        return $attachment;
    }

    /** Règles d'accès évaluées pour l'utilisateur courant (recalculées à chaque appel : l'instance du module peut survivre à la requête). */
    private function access(): AttachmentAccess
    {
        return new AttachmentAccess($this->ctx, $this->can('assist'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{q: string, kind: string, linked: string, sort: string, dir: string, page: int, per_page: int}
     */
    private function listQuery(array $input): array
    {
        $default = (int) $this->ctx->settings->preference($this->ctx->userId(), 'pageSize', 25);
        $perPage = (int) ($input['per_page'] ?? $default);
        $sort = (string) ($input['sort'] ?? 'created_at');
        $kind = (string) ($input['kind'] ?? '');
        $linked = (string) ($input['linked'] ?? '');
        return [
            'q' => is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '',
            'kind' => isset(AttachmentService::KINDS[$kind]) ? $kind : '',
            'linked' => in_array($linked, ['0', '1'], true) ? $linked : '',
            'sort' => AttachmentService::isSortable($sort) ? $sort : 'created_at',
            'dir' => strtolower((string) ($input['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PER_PAGE_CHOICES, true) ? $perPage : (in_array($default, self::PER_PAGE_CHOICES, true) ? $default : 25),
        ];
    }

    /** @param array{q: string, kind: string, linked: string, sort: string, dir: string, page: int, per_page: int} $query */
    private function listRoute(string $scope, array $query, array $overrides = []): string
    {
        $base = $scope === 'all' ? 'all' : 'list';
        $params = array_filter($overrides + [
            'q' => $query['q'],
            'kind' => $query['kind'],
            'linked' => $query['linked'],
            'sort' => $query['sort'] !== 'created_at' ? $query['sort'] : null,
            'dir' => $query['dir'] !== 'desc' ? $query['dir'] : null,
            'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null,
            'page' => $query['page'] > 1 ? $query['page'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return $params === [] ? $base : $base . '?' . http_build_query($params);
    }
}
