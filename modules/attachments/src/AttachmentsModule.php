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
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Shared\AttachmentService;
use Atelier\Shared\TagService;
use Atelier\Support\Str;

/**
 * Module « Fichiers joints » : interface du mécanisme transversal de pièces jointes du noyau.
 *
 * Les fichiers sont stockés chiffrés (AES-256-GCM) hors du répertoire public sous un nom interne
 * imprévisible ; leur téléchargement passe par PHP, après contrôle des droits du module et des
 * règles d'accès par fichier (AttachmentAccess), et est journalisé. Un fichier peut être rattaché
 * à toute information inscrite au registre commun (point GPS, fiche d'un autre module…).
 *
 * Depuis 1.1.0 : nom d'affichage libre (le nom d'origine reste celui du téléchargement), tags
 * partagés portés par le fichier (inscrit au registre sous attachments.file) et dossiers virtuels
 * (arborescence en base, les fichiers ne bougent jamais sur le disque).
 */
final class AttachmentsModule extends AbstractModule
{
    public const DATASET = FileIndexRepository::DATASET;

    private const PER_PAGE_CHOICES = [25, 50, 100];
    private const DESCRIPTION_MAX = 500;
    private const NAME_MAX = 200;
    private const LABEL_MAX = 200;
    private const TAGS_MAX_COUNT = 20;
    private const TAG_MAX = 60;

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
        $r->action('tags-save', [$this, 'tagsSave'], permission: 'update');
        $r->action('move', [$this, 'move'], permission: 'update');
        $r->action('folder-create', [$this, 'folderCreate'], permission: 'update');
        $r->action('folder-rename', [$this, 'folderRename'], permission: 'update');
        $r->action('folder-move', [$this, 'folderMove'], permission: 'update');
        $r->action('folder-delete', [$this, 'folderDelete'], permission: 'update');
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
        $adminId = $admin !== null ? (int) $admin['id'] : null;
        $content = "Bienvenue dans Atelier.\r\n\r\nCe fichier de démonstration a été téléversé par les données d’exemple.\r\n"
            . "Les fichiers joints sont chiffrés au repos et téléchargés uniquement après contrôle des droits.\r\n";
        $record = $service->storeContent($content, 'bienvenue.txt', null, $adminId, 'Fichier de démonstration');
        $service->setLabel((string) $record['id'], 'Bienvenue');
        $infoId = $this->ctx->shared->registry->register(self::DATASET, (string) $record['id'], 'Bienvenue', $adminId);
        $this->ctx->shared->tags->replace($infoId, ['exemple'], TagService::SHARED, $adminId);
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
        $folderId = $this->folderIdFromQuery((string) $request->query('folder', ''));
        $folder = is_int($folderId) ? $this->ctx->shared->folders->find($folderId) : null;
        $content = $this->render('upload', [
            'target' => $target,
            'maxFileSize' => $service->maxFileSize(),
            'allowedMimes' => $service->allowedMimes(),
            'remaining' => max(0, $service->maxPerUser() - $service->usageOfUser($this->ctx->userId())),
            'folders' => $this->folderOptions(),
            'currentFolderId' => $folder !== null ? (int) $folder['id'] : null,
        ]);
        $subtitle = $target !== null ? 'Rattachés à « ' . $target['label'] . ' »' : Str::humanSize($service->maxFileSize()) . ' au plus par fichier';
        if ($folder !== null) {
            $subtitle .= ' · dossier ' . $folder['path'];
        }
        $back = $folder !== null ? 'list?folder=' . (int) $folder['id'] : 'list';
        $banner = $this->renderCore('banner', [
            'icon' => 'upload',
            'title' => 'Téléverser des fichiers',
            'subtitle' => $subtitle,
            'actions' => '<a class="btn btn--ghost" href="#" data-route="' . $this->e($back) . '">' . $this->icon('chevron-left') . '<span>Mes fichiers</span></a>',
        ]);
        return ModuleView::make('Téléverser des fichiers')->banner($banner)->content($content)->status('Téléversement');
    }

    public function show(Request $request, array $params): ModuleView
    {
        $attachment = $this->requireReadable((string) ($params['id'] ?? ''), true);
        $access = $this->access();
        $rights = $this->rights(['read', 'update', 'delete']);
        $manage = $access->canManage($attachment);
        $displayName = AttachmentService::displayName($attachment);
        $folders = $this->ctx->shared->folders;
        $folderId = $attachment['folder_id'] !== null ? (int) $attachment['folder_id'] : null;
        $content = $this->render('show', [
            'attachment' => $attachment,
            'displayName' => $displayName,
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
            'tags' => $this->tagNamesOf($attachment),
            'folderPath' => $folderId !== null ? $folders->ancestors($folderId) : [],
            'folders' => $this->folderOptions(),
            'listRoute' => $folderId !== null ? 'list?folder=' . $folderId : 'list',
        ]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="' . ($attachment['deleted_at'] !== null ? 'trash' : 'list') . '">' . $this->icon('chevron-left') . '<span>Retour</span></a>';
        if ($rights['read'] && $attachment['deleted_at'] === null) {
            $actions .= '<a class="btn btn--primary" href="' . $this->e($this->url('download/' . $attachment['id'])) . '" download>' . $this->icon('download') . '<span>Télécharger</span></a>';
        }
        $subtitle = Str::humanSize((int) $attachment['size']) . ' · ' . $attachment['mime'];
        if ($displayName !== (string) $attachment['original_name']) {
            $subtitle = 'Fichier ' . $attachment['original_name'] . ' · ' . $subtitle;
        }
        $banner = $this->renderCore('banner', [
            'icon' => 'paperclip',
            'title' => $displayName,
            'subtitle' => $subtitle . ($attachment['deleted_at'] !== null ? ' · en corbeille' : ''),
            'actions' => $actions,
        ]);
        return ModuleView::make('Fichier · ' . $displayName)->banner($banner)->content($content)->status($displayName);
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
            'actions' => '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Mes fichiers</span></a>'
                . '<a class="btn btn--ghost" href="#" data-open-module="trash">' . $this->icon('trash') . '<span>Corbeille globale</span></a>',
        ]);
        return ModuleView::make('Corbeille · fichiers')->banner($banner)->content($content)->status($result['total'] . ' fichier(s) en corbeille');
    }

    // =====================================================================
    // Actions : liste et téléversement
    // =====================================================================

    public function filter(Request $request, array $params): ActionResult
    {
        $scope = $this->scopeFrom($request);
        $query = $this->listQuery($request->all());
        $query['page'] = 1;
        return ActionResult::ok()->navigate($this->listRoute($scope, $query));
    }

    /**
     * Téléversement d'un ou plusieurs fichiers (champ files[] ou file), avec nom d'affichage, tags,
     * dossier, description et rattachement facultatifs. Chaque fichier est traité indépendamment :
     * les refus sont rapportés. Le nom d'affichage s'applique tel quel à un fichier unique ; pour
     * plusieurs fichiers dans le même envoi, il est suffixé d'un numéro d'ordre (« Nom (1) », « Nom (2) »…).
     */
    public function upload(Request $request, array $params): ActionResult
    {
        $uploads = array_merge($request->fileList('files'), $request->fileList('file'));
        if ($uploads === []) {
            throw ValidationException::single('files', 'Choisissez au moins un fichier.');
        }
        $description = $this->validateDescription($request->string('description'));
        $label = $this->validateLabel($request->string('label'));
        $tags = $this->validateTags($request->input('tags', ''));
        $folderId = $this->validateFolderId($request->input('folder_id'));
        $infoId = $this->validateInfoId($request->string('info_id'));
        $info = $infoId !== null ? $this->ctx->shared->registry->get($infoId) : null;

        $service = $this->ctx->shared->attachments;
        $userId = $this->ctx->userId();
        $stored = [];
        $errors = [];
        $multiple = count($uploads) > 1;
        foreach ($uploads as $index => $upload) {
            $name = (string) ($upload['name'] ?? 'fichier');
            $fileLabel = $label !== null && $multiple ? $label . ' (' . ($index + 1) . ')' : $label;
            try {
                $record = $service->store($upload, $infoId, $userId, $description, $fileLabel);
                $id = (string) $record['id'];
                if ($folderId !== null) {
                    $this->ctx->shared->folders->moveAttachment($id, $folderId);
                }
                $fileInfoId = $this->ctx->shared->registry->register(self::DATASET, $id, AttachmentService::displayName($record), $userId);
                if ($tags !== []) {
                    $this->ctx->shared->tags->replace($fileInfoId, $tags, TagService::SHARED, $userId);
                }
                $stored[] = ['id' => $id, 'name' => $record['original_name'], 'label' => $record['label'], 'display_name' => AttachmentService::displayName($record), 'size' => (int) $record['size'], 'mime' => $record['mime'], 'folder_id' => $folderId, 'tags' => $tags];
                $this->log('attachment.upload', 'success', 'attachment:' . $id, 'Fichier téléversé : ' . AttachmentService::displayName($record), [
                    'original_name' => $record['original_name'],
                    'size' => (int) $record['size'],
                    'mime' => $record['mime'],
                    'sha256' => $record['sha256'],
                    'encrypted' => $record['cipher'] !== null,
                    'info_id' => $infoId,
                    'folder_id' => $folderId,
                    'tags' => count($tags),
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

    // =====================================================================
    // Actions : fichier
    // =====================================================================

    /**
     * Renommage : nom de fichier (extension conservée), nom d'affichage et description.
     * Le nom d'affichage n'est modifié que si le champ « label » est transmis (vide = effacé).
     */
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
        $label = $request->input('label') === null ? null : ($this->validateLabel($request->string('label')) ?? '');
        $this->ctx->shared->attachments->rename((string) $attachment['id'], $name, $description, $label);
        $updated = $this->ctx->shared->attachments->find((string) $attachment['id']) ?? $attachment;
        $displayName = AttachmentService::displayName($updated);
        $this->ctx->shared->registry->register(self::DATASET, (string) $attachment['id'], $displayName, $this->ctx->userId());
        $this->log('attachment.rename', 'success', 'attachment:' . $attachment['id'], 'Fichier renommé : ' . $displayName, ['previous' => $attachment['original_name'], 'previous_label' => $attachment['label'], 'name' => $updated['original_name'], 'label' => $updated['label']]);
        return ActionResult::ok(['id' => $attachment['id'], 'display_name' => $displayName], 'Fichier enregistré.')->refresh()->dirty(false);
    }

    /** Remplace les tags partagés du fichier (champ « tags », liste séparée par des virgules). */
    public function tagsSave(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'));
        $tags = $this->validateTags($request->input('tags', ''));
        $userId = $this->ctx->userId();
        $infoId = $this->infoIdOf($attachment);
        $this->ctx->shared->tags->replace($infoId, $tags, TagService::SHARED, $userId);
        $this->log('attachment.tags', 'success', 'attachment:' . $attachment['id'], 'Tags du fichier mis à jour : ' . AttachmentService::displayName($attachment), ['tags' => $tags]);
        return ActionResult::ok(['id' => $attachment['id'], 'tags' => $tags], $tags === [] ? 'Tags retirés.' : count($tags) . ' tag(s) enregistré(s).')->refresh()->dirty(false);
    }

    /** Range un ou plusieurs fichiers (id ou ids[]) dans un dossier (folder_id vide = racine). */
    public function move(Request $request, array $params): ActionResult
    {
        $ids = $request->arrayInput('ids');
        $single = $request->string('id');
        if ($single !== '') {
            $ids[] = $single;
        }
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $ids), static fn (string $v): bool => $v !== '')));
        if ($ids === []) {
            throw ValidationException::single('ids', 'Sélectionnez au moins un fichier à déplacer.');
        }
        $folderId = $this->validateFolderId($request->input('folder_id'));
        $folder = $folderId !== null ? $this->ctx->shared->folders->require($folderId) : null;
        $moved = [];
        foreach ($ids as $id) {
            $attachment = $this->requireManageable($id);
            $moved[] = (string) $attachment['id'];
        }
        $count = $this->ctx->shared->folders->moveAttachments($moved, $folderId);
        $destination = $folder !== null ? '« ' . $folder['path'] . ' »' : 'la racine (non rangés)';
        $this->log('attachment.move', 'success', count($moved) === 1 ? 'attachment:' . $moved[0] : null, $count . ' fichier(s) déplacé(s) vers ' . $destination, ['ids' => $moved, 'folder_id' => $folderId]);
        return ActionResult::ok(['moved' => $count, 'folder_id' => $folderId], $count . ' fichier(s) déplacé(s) vers ' . $destination . '.')->refresh();
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

    /** Recherche d'informations rattachables dans les jeux partagés lisibles (les fichiers eux-mêmes sont exclus). */
    public function searchInfo(Request $request, array $params): ActionResult
    {
        $term = $request->string('q');
        if (mb_strlen($term, 'UTF-8') < 2) {
            return ActionResult::ok(['items' => []]);
        }
        $codes = array_values(array_filter($this->ctx->shared->catalog->readableCodes($this->ctx->userId()), static fn (string $c): bool => $c !== self::DATASET));
        $items = [];
        foreach ($this->ctx->shared->registry->search($term, $codes, 15) as $row) {
            $items[] = ['id' => (string) $row['id'], 'label' => (string) ($row['label'] ?? $row['local_key']), 'dataset' => (string) $row['dataset_code'], 'module' => (string) $row['module_id']];
        }
        return ActionResult::ok(['items' => $items]);
    }

    public function delete(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'));
        $displayName = AttachmentService::displayName($attachment);
        $this->ctx->shared->attachments->softDelete((string) $attachment['id']);
        $this->log('attachment.delete', 'success', 'attachment:' . $attachment['id'], 'Fichier mis à la corbeille : ' . $displayName, ['original_name' => $attachment['original_name']]);
        $back = $attachment['folder_id'] !== null ? 'list?folder=' . (int) $attachment['folder_id'] : 'list';
        return ActionResult::ok(null, 'Fichier « ' . $displayName . ' » mis à la corbeille.')->navigate($back);
    }

    public function restore(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'), true);
        if ($attachment['deleted_at'] === null) {
            throw new NotFoundException('Ce fichier n’est pas dans la corbeille.');
        }
        $displayName = AttachmentService::displayName($attachment);
        $this->ctx->shared->attachments->restore((string) $attachment['id']);
        $this->log('attachment.restore', 'success', 'attachment:' . $attachment['id'], 'Fichier restauré : ' . $displayName);
        return ActionResult::ok(null, 'Fichier « ' . $displayName . ' » restauré.')->refresh();
    }

    /** Suppression définitive d'un fichier de la corbeille (action « purge ») ; son entrée du registre (tags) disparaît avec lui. */
    public function destroy(Request $request, array $params): ActionResult
    {
        $attachment = $this->requireManageable($request->string('id'), true);
        if ($attachment['deleted_at'] === null) {
            throw new NotFoundException('Ce fichier n’est pas dans la corbeille.');
        }
        $displayName = AttachmentService::displayName($attachment);
        if (!$this->ctx->shared->attachments->purge((string) $attachment['id'])) {
            throw new \RuntimeException('Le fichier n’a pas pu être supprimé du stockage.');
        }
        $this->ctx->shared->registry->unregister(self::DATASET, (string) $attachment['id']);
        $this->log('attachment.purge', 'success', 'attachment:' . $attachment['id'], 'Fichier supprimé définitivement : ' . $displayName, ['original_name' => $attachment['original_name']]);
        return ActionResult::ok(null, 'Fichier « ' . $displayName . ' » supprimé définitivement.')->refresh();
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
    // Actions : dossiers virtuels (partagés entre les utilisateurs disposant de « update »)
    // =====================================================================

    public function folderCreate(Request $request, array $params): ActionResult
    {
        $name = $request->string('name');
        $parentId = $this->validateFolderId($request->input('parent_id'), 'parent_id');
        $id = $this->ctx->shared->folders->create($name, $parentId, $this->ctx->userId());
        $folder = $this->ctx->shared->folders->require($id);
        $this->log('attachment.folder.create', 'success', 'folder:' . $id, 'Dossier créé : ' . $folder['path'], ['parent_id' => $parentId]);
        return ActionResult::ok(['id' => $id, 'path' => $folder['path']], 'Dossier « ' . $folder['name'] . ' » créé.')->navigate($this->listBase($this->scopeFrom($request)) . '?folder=' . $id);
    }

    public function folderRename(Request $request, array $params): ActionResult
    {
        $id = $this->requireFolderId($request);
        $previous = $this->ctx->shared->folders->require($id);
        $this->ctx->shared->folders->rename($id, $request->string('name'));
        $folder = $this->ctx->shared->folders->require($id);
        $this->log('attachment.folder.rename', 'success', 'folder:' . $id, 'Dossier renommé : ' . $folder['path'], ['previous' => $previous['path']]);
        return ActionResult::ok(['id' => $id, 'path' => $folder['path']], 'Dossier renommé en « ' . $folder['name'] . ' ».')->refresh();
    }

    /** Déplace un dossier (et son contenu) sous un autre dossier (parent_id vide = racine). */
    public function folderMove(Request $request, array $params): ActionResult
    {
        $id = $this->requireFolderId($request);
        $parentId = $this->validateFolderId($request->input('parent_id'), 'parent_id');
        $previous = $this->ctx->shared->folders->require($id);
        $this->ctx->shared->folders->move($id, $parentId);
        $folder = $this->ctx->shared->folders->require($id);
        $this->log('attachment.folder.move', 'success', 'folder:' . $id, 'Dossier déplacé : ' . $folder['path'], ['previous' => $previous['path'], 'parent_id' => $parentId]);
        return ActionResult::ok(['id' => $id, 'path' => $folder['path']], 'Dossier déplacé vers « ' . $folder['path'] . ' ».')->refresh();
    }

    /** Supprime un dossier : ses fichiers et sous-dossiers remontent dans le dossier parent, rien n'est supprimé sur le disque. */
    public function folderDelete(Request $request, array $params): ActionResult
    {
        $id = $this->requireFolderId($request);
        $folders = $this->ctx->shared->folders;
        $folder = $folders->require($id);
        $fileCount = $this->index()->folderCounts()[$id] ?? 0;
        $childCount = count($folders->children($id));
        $parentId = $folder['parent_id'] !== null ? (int) $folder['parent_id'] : null;
        $parent = $parentId !== null ? $folders->require($parentId) : null;
        $folders->delete($id);
        $this->log('attachment.folder.delete', 'success', 'folder:' . $id, 'Dossier supprimé : ' . $folder['path'], ['files_moved' => $fileCount, 'folders_moved' => $childCount, 'parent_id' => $parentId]);
        $destination = $parent !== null ? '« ' . $parent['path'] . ' »' : 'la racine (non rangés)';
        $message = 'Dossier « ' . $folder['name'] . ' » supprimé';
        $moved = [];
        if ($fileCount > 0) {
            $moved[] = $fileCount . ' fichier(s)';
        }
        if ($childCount > 0) {
            $moved[] = $childCount . ' sous-dossier(s)';
        }
        $message .= $moved !== [] ? ' ; ' . implode(' et ', $moved) . ' remonté(s) dans ' . $destination . '.' : ' (il était vide).';
        $base = $this->listBase($this->scopeFrom($request));
        return ActionResult::ok(['files_moved' => $fileCount, 'folders_moved' => $childCount], $message)->navigate($parentId !== null ? $base . '?folder=' . $parentId : $base);
    }

    // =====================================================================
    // Téléchargement
    // =====================================================================

    /** Téléchargement après contrôle des droits ; le nom d'origine est conservé, le contenu déchiffré à la volée, jamais mis en cache. */
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

    /** @param array<string, mixed> $attachment */
    public static function displayName(array $attachment): string
    {
        return AttachmentService::displayName($attachment);
    }

    // =====================================================================
    // Interne
    // =====================================================================

    private function listView(Request $request, string $scope): ModuleView
    {
        $access = $this->access();
        $service = $this->ctx->shared->attachments;
        $folders = $this->ctx->shared->folders;
        $userId = $this->ctx->userId();
        $query = $this->listQuery($request->allQuery());
        $filters = ['search' => $query['q'], 'kind' => $query['kind']];
        if ($query['linked'] !== '') {
            $filters['linked'] = $query['linked'] === '1';
        }
        if ($query['tag'] !== '') {
            $filters['tag'] = $query['tag'];
        }
        $currentFolder = null;
        if ($query['folder'] === 'root') {
            $filters['folder_id'] = 'root';
        } elseif ($query['folder'] !== '') {
            $currentFolder = $folders->require((int) $query['folder']);
            $filters['folder_id'] = (int) $currentFolder['id'];
        }
        if ($scope === 'mine') {
            $filters['uploaded_by'] = $userId;
        }
        $result = $service->paginate($filters, $query['page'], $query['per_page'], $query['sort'], $query['dir']);
        $rights = $this->rights(['read', 'create', 'update', 'delete']);
        $mine = $service->stats($userId);
        $global = $access->isAssist() ? $service->stats() : null;
        $index = $this->index();
        $counts = $index->folderCounts($scope === 'mine' ? $userId : null);
        $ids = array_map(static fn (array $r): string => (string) $r['id'], $result['rows']);

        $content = $this->render('list', [
            'rows' => $result['rows'],
            'rowTags' => $index->tagsOfFiles($ids),
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
            'folderTree' => $this->folderTree($counts),
            'folderOptions' => $this->folderOptions(),
            'folderCounts' => ['all' => array_sum($counts), 'root' => $counts[0] ?? 0],
            'currentFolder' => $currentFolder,
            'breadcrumb' => $currentFolder !== null ? $folders->ancestors((int) $currentFolder['id']) : [],
            'fileTags' => $index->fileTags($scope === 'mine' ? $userId : null),
        ]);

        $uploadRoute = 'upload' . ($currentFolder !== null ? '?folder=' . (int) $currentFolder['id'] : '');
        $actions = '<a class="btn btn--ghost" href="#" data-route="' . $this->e($this->listRoute($scope, $query)) . '" title="Actualiser">' . $this->icon('refresh') . '<span>Actualiser</span></a>';
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="' . $this->e($uploadRoute) . '">' . $this->icon('upload') . '<span>Téléverser</span></a>';
        }
        $title = $scope === 'all' ? 'Tous les fichiers' : 'Mes fichiers';
        $filtered = $query['q'] !== '' || $query['kind'] !== '' || $query['linked'] !== '' || $query['tag'] !== '';
        $place = $currentFolder !== null ? ' dans ' . $currentFolder['path'] : ($query['folder'] === 'root' ? ' non rangés' : '');
        $subtitle = sprintf('%d fichier(s)%s%s · %s utilisés sur %s', $result['total'], $place, $filtered ? ' (filtrés)' : '', Str::humanSize($mine['size']), Str::humanSize($service->maxPerUser()));
        $banner = $this->renderCore('banner', ['icon' => 'paperclip', 'title' => $title, 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make($title)->banner($banner)->content($content)->status($subtitle)->route($this->listRoute($scope, $query));
    }

    /**
     * Arbre des dossiers (liste triée par chemin → structure imbriquée), avec le nombre de fichiers
     * de la portée courante.
     *
     * @param array<int, int> $counts
     * @return list<array<string, mixed>> chaque nœud : id, name, path, depth, file_count, children
     */
    private function folderTree(array $counts): array
    {
        $nodes = [];
        foreach ($this->ctx->shared->folders->all() as $folder) {
            $id = (int) $folder['id'];
            $nodes[$id] = [
                'id' => $id,
                'parent_id' => $folder['parent_id'] !== null ? (int) $folder['parent_id'] : null,
                'name' => (string) $folder['name'],
                'path' => (string) $folder['path'],
                'depth' => max(0, substr_count((string) $folder['path'], '/') - 1),
                'file_count' => $counts[$id] ?? 0,
                'children' => [],
            ];
        }
        $roots = [];
        foreach (array_keys($nodes) as $id) {
            $parentId = $nodes[$id]['parent_id'];
            if ($parentId !== null && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$nodes[$id];
            } else {
                $roots[] = &$nodes[$id];
            }
        }
        return $roots;
    }

    /**
     * Dossiers à plat pour les listes déroulantes (triés par chemin, avec profondeur).
     *
     * @return list<array{id: int, name: string, path: string, depth: int}>
     */
    private function folderOptions(): array
    {
        $options = [];
        foreach ($this->ctx->shared->folders->all() as $folder) {
            $options[] = ['id' => (int) $folder['id'], 'name' => (string) $folder['name'], 'path' => (string) $folder['path'], 'depth' => max(0, substr_count((string) $folder['path'], '/') - 1)];
        }
        return $options;
    }

    private function index(): FileIndexRepository
    {
        return new FileIndexRepository($this->ctx->db);
    }

    /** Identifiant du fichier dans le registre commun, créé au premier besoin. @param array<string, mixed> $attachment */
    private function infoIdOf(array $attachment): string
    {
        $owner = $attachment['uploaded_by'] !== null ? (int) $attachment['uploaded_by'] : $this->ctx->userId();
        return $this->ctx->shared->registry->register(self::DATASET, (string) $attachment['id'], AttachmentService::displayName($attachment), $owner);
    }

    /** @param array<string, mixed> $attachment @return list<string> */
    private function tagNamesOf(array $attachment): array
    {
        return $this->index()->tagsOfFiles([(string) $attachment['id']])[(string) $attachment['id']] ?? [];
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
        $this->assertUtf8('description', $description);
        if (mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX) {
            throw ValidationException::single('description', sprintf('La description ne peut dépasser %d caractères.', self::DESCRIPTION_MAX));
        }
        return $description !== '' ? $description : null;
    }

    private function validateLabel(string $label): ?string
    {
        $this->assertUtf8('label', $label);
        if (mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
            throw ValidationException::single('label', sprintf('Le nom d’affichage ne peut dépasser %d caractères.', self::LABEL_MAX));
        }
        return AttachmentService::cleanLabel($label);
    }

    /** Une saisie mal encodée (client non UTF-8) rendrait les réponses JSON impossibles : refus explicite. */
    private function assertUtf8(string $field, string $value): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::single($field, 'Texte mal encodé : l’application attend de l’UTF-8.');
        }
    }

    /**
     * Découpe une saisie « a, b, c » (ou un tableau) en liste de tags distincts et valides.
     *
     * @return list<string>
     */
    private function validateTags(mixed $input): array
    {
        $parts = is_array($input) ? $input : explode(',', is_scalar($input) ? (string) $input : '');
        $tags = [];
        $seen = [];
        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }
            $this->assertUtf8('tags', (string) $part);
            $name = trim(ltrim(trim((string) $part), '#'));
            $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
            $normalized = Str::normalizeTag($name);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            if (mb_strlen($name, 'UTF-8') > self::TAG_MAX) {
                throw ValidationException::single('tags', 'Chaque tag doit comporter au plus ' . self::TAG_MAX . ' caractères.');
            }
            $seen[$normalized] = true;
            $tags[] = $name;
        }
        if (count($tags) > self::TAGS_MAX_COUNT) {
            throw ValidationException::single('tags', 'Au maximum ' . self::TAGS_MAX_COUNT . ' tags par fichier.');
        }
        return $tags;
    }

    /** Identifiant de dossier saisi : vide, 0 ou « root » = racine (null) ; sinon un dossier existant. */
    private function validateFolderId(mixed $input, string $field = 'folder_id'): ?int
    {
        if ($input === null || $input === '' || $input === 'root' || $input === 0 || $input === '0') {
            return null;
        }
        if (!is_scalar($input) || !ctype_digit((string) $input)) {
            throw ValidationException::single($field, 'Dossier invalide.');
        }
        if ($this->ctx->shared->folders->find((int) $input) === null) {
            throw ValidationException::single($field, 'Dossier introuvable.');
        }
        return (int) $input;
    }

    private function requireFolderId(Request $request): int
    {
        $id = $request->int('id');
        if ($id === null || $id <= 0) {
            throw ValidationException::single('id', 'Dossier invalide.');
        }
        return $id;
    }

    /** Valeur normalisée du paramètre de requête « folder » : '' (tout), 'root' (non rangés) ou identifiant. */
    private function folderIdFromQuery(string $value): int|string
    {
        $value = trim($value);
        if ($value === 'root') {
            return 'root';
        }
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : '';
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
        if ((string) $info['dataset_code'] === self::DATASET) {
            throw ValidationException::single('info_id', 'Un fichier ne peut pas être rattaché à un autre fichier.');
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

    /** Portée demandée par une action (« all » exige la permission assist). */
    private function scopeFrom(Request $request): string
    {
        return $request->string('scope') === 'all' && $this->can('assist') ? 'all' : 'mine';
    }

    private function listBase(string $scope): string
    {
        return $scope === 'all' ? 'all' : 'list';
    }

    /**
     * @param array<string, mixed> $input
     * @return array{q: string, kind: string, linked: string, tag: string, folder: string, sort: string, dir: string, page: int, per_page: int}
     */
    private function listQuery(array $input): array
    {
        $default = (int) $this->ctx->settings->preference($this->ctx->userId(), 'pageSize', 25);
        $perPage = (int) ($input['per_page'] ?? $default);
        $sort = (string) ($input['sort'] ?? 'created_at');
        $kind = (string) ($input['kind'] ?? '');
        $linked = (string) ($input['linked'] ?? '');
        $folder = $this->folderIdFromQuery(is_scalar($input['folder'] ?? null) ? (string) $input['folder'] : '');
        return [
            'q' => is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '',
            'kind' => isset(AttachmentService::KINDS[$kind]) ? $kind : '',
            'linked' => in_array($linked, ['0', '1'], true) ? $linked : '',
            'tag' => is_scalar($input['tag'] ?? null) ? mb_substr(trim((string) $input['tag']), 0, self::TAG_MAX, 'UTF-8') : '',
            'folder' => (string) $folder,
            'sort' => AttachmentService::isSortable($sort) ? $sort : 'created_at',
            'dir' => strtolower((string) ($input['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PER_PAGE_CHOICES, true) ? $perPage : (in_array($default, self::PER_PAGE_CHOICES, true) ? $default : 25),
        ];
    }

    /** @param array{q: string, kind: string, linked: string, tag: string, folder: string, sort: string, dir: string, page: int, per_page: int} $query */
    private function listRoute(string $scope, array $query, array $overrides = []): string
    {
        $base = $this->listBase($scope);
        $params = array_filter($overrides + [
            'folder' => $query['folder'],
            'q' => $query['q'],
            'kind' => $query['kind'],
            'linked' => $query['linked'],
            'tag' => $query['tag'],
            'sort' => $query['sort'] !== 'created_at' ? $query['sort'] : null,
            'dir' => $query['dir'] !== 'desc' ? $query['dir'] : null,
            'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null,
            'page' => $query['page'] > 1 ? $query['page'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return $params === [] ? $base : $base . '?' . http_build_query($params);
    }
}
