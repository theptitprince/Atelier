<?php

declare(strict_types=1);

namespace Atelier\Modules\Explorer;

use Atelier\Error\ForbiddenException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Shared\AttachmentService;
use Atelier\Shared\RelationService;
use Atelier\Shared\TagService;
use Atelier\Support\Str;

/**
 * Explorateur : vue transversale « wiki » des informations partagées de tous les modules
 * (registre commun), de leurs tags, relations et pièces jointes.
 *
 * Règle de visibilité : une information n'est visible que si son jeu de données est partagé
 * ET lisible par l'utilisateur (catalog->readableCodes). Les jeux privés ne sont jamais listés,
 * même via un tag ou une relation. Toute modification (tags, relations, pièces jointes) exige
 * le droit « update » sur le jeu de données de l'information concernée.
 */
final class ExplorerModule extends AbstractModule
{
    public const PER_PAGE = 25;
    public const LOOKUP_LIMIT = 15;
    public const HISTORY_LIMIT = 10;
    public const TAG_MAX = 60;

    private ?ExplorerQueries $queries = null;

    /** @var list<string>|null codes des jeux visibles, calculés une fois par requête */
    private ?array $visibleCodes = null;

    /** @var array<string, array<string, mixed>>|null code => description du jeu (nom, module, openRoute…) */
    private ?array $datasetIndex = null;

    public function routes(RouteCollection $r): void
    {
        // Vues
        $r->view('search', [$this, 'searchView'], permission: 'open');
        $r->view('info/{id}', [$this, 'infoView'], permission: 'open');
        $r->view('datasets', [$this, 'datasetsView'], permission: 'open');
        $r->view('relations', [$this, 'relationsView'], permission: 'open');
        $r->view('attachments', [$this, 'attachmentsView'], permission: 'open');

        // Actions
        $r->action('search', [$this, 'searchAction'], permission: 'open');
        $r->action('lookup', [$this, 'lookup'], permission: 'open', methods: ['GET']);
        $r->action('tag-add', [$this, 'tagAdd'], permission: 'open');
        $r->action('tag-remove', [$this, 'tagRemove'], permission: 'open');
        $r->action('relate', [$this, 'relate'], permission: 'open');
        $r->action('unrelate', [$this, 'unrelate'], permission: 'open');
        $r->action('upload', [$this, 'upload'], permission: 'open');
        $r->action('attachment-delete', [$this, 'attachmentDelete'], permission: 'open');
    }

    // =====================================================================
    // Vues
    // =====================================================================

    /** Recherche : search?q=&dataset=&tag=&module=&page=&sort= */
    public function searchView(Request $request, array $params): ModuleView
    {
        $codes = $this->visibleCodes();
        $q = trim((string) $request->query('q', ''));
        $dataset = trim((string) $request->query('dataset', ''));
        $module = trim((string) $request->query('module', ''));
        $tagName = trim((string) $request->query('tag', ''));
        $sort = (string) $request->query('sort', 'label');
        if (!ExplorerQueries::isSortable($sort)) {
            $sort = 'label';
        }
        $page = max(1, (int) $request->query('page', 1));

        // Filtres : un jeu ou un module inconnu/non lisible ne renvoie rien (pas de repli silencieux).
        if ($dataset !== '' && !in_array($dataset, $codes, true)) {
            $codes = [];
        }
        $tag = null;
        $tagMissing = false;
        if ($tagName !== '') {
            $tag = $this->queries()->findTagByName(Str::normalizeTag($tagName));
            $tagMissing = $tag === null;
        }

        $result = $tagMissing
            ? ['rows' => [], 'total' => 0]
            : $this->queries()->searchInfos($codes, $q, $dataset ?: null, $module ?: null, $tag === null ? null : (int) $tag['id'], $page, self::PER_PAGE, $sort);
        $rows = $this->decorateInfos($result['rows']);

        $query = array_filter(['q' => $q, 'dataset' => $dataset, 'module' => $module, 'tag' => $tagName, 'sort' => $sort === 'label' ? '' : $sort], static fn (string $v): bool => $v !== '');
        $hasFilters = $q !== '' || $dataset !== '' || $module !== '' || $tagName !== '';

        $content = $this->render('search', [
            'rows' => $rows,
            'total' => $result['total'],
            'q' => $q,
            'dataset' => $dataset,
            'moduleFilter' => $module,
            'tagName' => $tagName,
            'sort' => $sort,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'query' => $query,
            'hasFilters' => $hasFilters,
            'datasets' => $this->datasetIndex(),
            'modules' => $this->moduleChoices(),
        ]);

        return ModuleView::make('Rechercher')
            ->banner($this->banner('Explorateur', $this->plural($result['total'], 'information'), $this->navLinks('search'), 'search'))
            ->content($content)
            ->status($this->plural($result['total'], 'information'));
    }

    /** Formulaire de recherche (data-auto-submit) : redirige vers la vue filtrée, page 1. */
    public function searchAction(Request $request, array $params): ActionResult
    {
        $query = array_filter([
            'q' => trim($request->string('q')),
            'dataset' => trim($request->string('dataset')),
            'module' => trim($request->string('module')),
            'tag' => trim($request->string('tag')),
            'sort' => trim($request->string('sort')) === 'label' ? '' : trim($request->string('sort')),
        ], static fn (string $v): bool => $v !== '');
        return ActionResult::ok()->navigate('search' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    /** Détail d'une information : info/{id}. */
    public function infoView(Request $request, array $params): ModuleView
    {
        $info = $this->requireVisibleInfo((string) ($params['id'] ?? ''));
        $infoId = (string) $info['id'];
        $shared = $this->ctx->shared;
        $canUpdate = $this->canUpdate((string) $info['dataset_code']);

        $relations = $shared->relations->relationsOf($infoId);
        foreach ($relations as &$relation) {
            $relation['other_visible'] = $this->isVisibleCode((string) $relation['other_dataset']);
            if (!$relation['other_visible']) {
                // Jamais de fuite : ni libellé, ni clé, ni jeu de données d'une information non accessible.
                $relation['other_label'] = null;
                $relation['other_key'] = null;
                $relation['other_dataset'] = null;
                $relation['other_module'] = null;
            }
        }
        unset($relation);

        $history = $this->ctx->activity->paginate(['resource_ref' => 'info:' . $infoId], 1, self::HISTORY_LIMIT);
        $creator = $info['created_by'] === null ? null : $this->ctx->users->find((int) $info['created_by']);
        $decorated = $this->decorateInfos([$info])[0];
        $attachments = $shared->attachments->listFor($infoId);

        $content = $this->render('info', [
            'info' => $decorated,
            'creator' => $creator,
            'canUpdate' => $canUpdate,
            'tags' => $shared->tags->tagsOf($infoId, TagService::SHARED),
            'relations' => $relations,
            'relationTypes' => RelationService::DEFAULT_TYPES,
            'attachments' => $attachments,
            'inlineMimes' => AttachmentService::INLINE_MIMES,
            'history' => $history['rows'],
            'historyTotal' => $history['total'],
        ]);

        $label = (string) ($info['label'] ?? $info['local_key']);
        $actions = $this->navLinks(null);
        if ($decorated['open_route'] !== null) {
            $actions = '<a class="btn btn--primary" href="#" data-open-module="' . $this->e($info['module_id']) . '" data-open-route="' . $this->e($decorated['open_route']) . '">'
                . $this->icon('external') . ' Ouvrir dans le module</a>' . $actions;
        }
        return ModuleView::make(Str::truncate($label, 40))
            ->banner($this->banner($label, $decorated['dataset_name'] . ' · ' . $decorated['module_name'], $actions, 'file'))
            ->content($content)
            ->status(sprintf('%s · %s · %s', $this->plural(count($relations), 'relation'), $this->plural(count($attachments), 'pièce jointe', 'pièces jointes'), $canUpdate ? 'modification autorisée' : 'lecture seule'));
    }

    /** Jeux de données partagés lisibles. */
    public function datasetsView(Request $request, array $params): ModuleView
    {
        $codes = $this->visibleCodes();
        $counts = $this->queries()->countByDataset($codes);
        $datasets = [];
        foreach ($this->ctx->shared->catalog->shared() as $dataset) {
            if (!in_array($dataset['code'], $codes, true)) {
                continue;
            }
            $meta = $this->datasetIndex()[$dataset['code']] ?? null;
            $dataset['module_name'] = $meta['module_name'] ?? $dataset['module_id'];
            $dataset['open_route'] = $meta['open_route'] ?? null;
            $dataset['info_count'] = $counts[$dataset['code']] ?? 0;
            $dataset['can_update'] = $this->canUpdate((string) $dataset['code']);
            $datasets[] = $dataset;
        }
        $total = array_sum(array_column($datasets, 'info_count'));

        return ModuleView::make('Jeux de données')
            ->banner($this->banner('Jeux de données', $this->plural(count($datasets), 'jeu partagé lisible', 'jeux partagés lisibles'), $this->navLinks('datasets'), 'database'))
            ->content($this->render('datasets', ['datasets' => $datasets]))
            ->status($this->plural((int) $total, 'information enregistrée', 'informations enregistrées'));
    }

    /** Relations entre informations visibles : relations?type=&page=. */
    public function relationsView(Request $request, array $params): ModuleView
    {
        $codes = $this->visibleCodes();
        $type = trim((string) $request->query('type', ''));
        if ($type !== '' && !Str::isSlug($type, 32)) {
            $type = '';
        }
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->queries()->relations($codes, $type ?: null, $page, self::PER_PAGE);
        $index = $this->datasetIndex();
        $rows = $result['rows'];
        foreach ($rows as &$row) {
            $row['type_label'] = RelationService::DEFAULT_TYPES[$row['type']] ?? $row['type'];
            $row['from_dataset_name'] = $index[$row['from_dataset']]['name'] ?? $row['from_dataset'];
            $row['to_dataset_name'] = $index[$row['to_dataset']]['name'] ?? $row['to_dataset'];
            $row['can_remove'] = $this->canUpdate((string) $row['from_dataset']);
        }
        unset($row);
        $types = $this->queries()->relationTypes($codes);

        $content = $this->render('relations', [
            'rows' => $rows,
            'total' => $result['total'],
            'type' => $type,
            'types' => $types,
            'typeLabels' => RelationService::DEFAULT_TYPES,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'query' => $type === '' ? [] : ['type' => $type],
        ]);
        return ModuleView::make('Relations')
            ->banner($this->banner('Relations', $this->plural($result['total'], 'relation'), $this->navLinks('relations'), 'link'))
            ->content($content)
            ->status($this->plural($result['total'], 'relation'));
    }

    /** Pièces jointes des informations visibles : attachments?dataset=&page=. */
    public function attachmentsView(Request $request, array $params): ModuleView
    {
        $codes = $this->visibleCodes();
        $dataset = trim((string) $request->query('dataset', ''));
        if ($dataset !== '' && !in_array($dataset, $codes, true)) {
            $codes = [];
        }
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->queries()->attachments($codes, $dataset ?: null, $page, self::PER_PAGE);
        $index = $this->datasetIndex();
        $rows = $result['rows'];
        foreach ($rows as &$row) {
            $row['info_dataset_name'] = $index[$row['info_dataset']]['name'] ?? $row['info_dataset'];
            $row['can_delete'] = $this->canUpdate((string) $row['info_dataset']);
            $row['inline'] = in_array($row['mime'], AttachmentService::INLINE_MIMES, true);
        }
        unset($row);

        $content = $this->render('attachments', [
            'rows' => $rows,
            'total' => $result['total'],
            'size' => $result['size'],
            'dataset' => $dataset,
            'datasets' => $index,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'query' => $dataset === '' ? [] : ['dataset' => $dataset],
        ]);
        $subtitle = $this->plural($result['total'], 'fichier') . ' · ' . Str::humanSize($result['size']);
        return ModuleView::make('Pièces jointes')
            ->banner($this->banner('Pièces jointes', $subtitle, $this->navLinks('attachments'), 'paperclip'))
            ->content($content)
            ->status($subtitle);
    }

    // =====================================================================
    // Actions
    // =====================================================================

    /**
     * Recherche de cibles pour une relation : lookup?q=&exclude= (GET, JSON).
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
                $results[] = [
                    'id' => (string) $row['id'],
                    'label' => (string) ($row['label'] ?? $row['local_key']),
                    'dataset' => (string) $row['dataset_code'],
                    'dataset_name' => $index[$row['dataset_code']]['name'] ?? $row['dataset_code'],
                    'module' => (string) $row['module_id'],
                ];
                if (count($results) >= self::LOOKUP_LIMIT) {
                    break;
                }
            }
        }
        return ['results' => $results];
    }

    /** Ajout de tags partagés : { info, tags: "a, b" }. */
    public function tagAdd(Request $request, array $params): ActionResult
    {
        $info = $this->requireUpdatableInfo($request->string('info'));
        $names = $this->parseTags($request->input('tags', ''));
        if ($names === []) {
            throw ValidationException::single('tags', 'Saisissez au moins un tag.');
        }
        $userId = $this->ctx->userId();
        $added = [];
        foreach ($names as $name) {
            if (mb_strlen($name, 'UTF-8') > self::TAG_MAX) {
                throw ValidationException::single('tags', 'Un tag comporte au plus ' . self::TAG_MAX . ' caractères.');
            }
            $added[] = (string) $this->ctx->shared->tags->attach((string) $info['id'], $name, TagService::SHARED, $userId)['name'];
        }
        $this->log('explorer.tag_add', 'success', 'info:' . $info['id'], 'Tags ajoutés : ' . implode(', ', $added), ['dataset' => $info['dataset_code'], 'tags' => $added], 'data');
        return ActionResult::ok(['tags' => $added], count($added) === 1 ? 'Tag « ' . $added[0] . ' » ajouté.' : count($added) . ' tags ajoutés.')->refresh();
    }

    /** Retrait d'un tag : { info, tag_id }. */
    public function tagRemove(Request $request, array $params): ActionResult
    {
        $info = $this->requireUpdatableInfo($request->string('info'));
        $tagId = (int) ($request->int('tag_id') ?? 0);
        $tag = $tagId > 0 ? $this->ctx->shared->tags->find($tagId) : null;
        if ($tag === null) {
            throw new NotFoundException('Tag introuvable.');
        }
        $this->ctx->shared->tags->detach((string) $info['id'], $tagId);
        $this->log('explorer.tag_remove', 'success', 'info:' . $info['id'], 'Tag retiré : ' . $tag['name'], ['dataset' => $info['dataset_code'], 'tag' => $tag['name']], 'data');
        return ActionResult::ok(null, 'Tag « ' . $tag['name'] . ' » retiré.')->refresh();
    }

    /** Création d'une relation : { info, type, to, comment }. */
    public function relate(Request $request, array $params): ActionResult
    {
        $from = $this->requireUpdatableInfo($request->string('info'));
        $type = $request->string('type', 'related');
        if (!isset(RelationService::DEFAULT_TYPES[$type])) {
            throw ValidationException::single('type', 'Type de relation inconnu.');
        }
        $toId = trim($request->string('to'));
        if ($toId === '') {
            throw ValidationException::single('to', 'Choisissez une information cible.');
        }
        $to = $this->ctx->shared->registry->get($toId);
        if ($to === null || !$this->isVisibleCode((string) $to['dataset_code'])) {
            throw ValidationException::single('to', 'Information cible introuvable ou non accessible.');
        }
        $comment = trim($request->string('comment'));
        if (mb_strlen($comment, 'UTF-8') > 200) {
            throw ValidationException::single('comment', 'Le commentaire ne doit pas dépasser 200 caractères.');
        }
        $userId = $this->ctx->userId();
        // RelationService lève ValidationException si from === to.
        $relationId = $this->ctx->shared->relations->relate($type, (string) $from['id'], (string) $to['id'], $userId, $comment !== '' ? $comment : null);
        $this->log('explorer.relate', 'success', 'info:' . $from['id'], 'Relation « ' . RelationService::DEFAULT_TYPES[$type] . ' » vers ' . ($to['label'] ?? $to['local_key']), ['relation_id' => $relationId, 'type' => $type, 'to' => $to['id'], 'to_dataset' => $to['dataset_code']], 'data');
        return ActionResult::ok(['id' => $relationId], 'Relation « ' . RelationService::DEFAULT_TYPES[$type] . ' » créée.')->refresh();
    }

    /** Suppression d'une relation : { id }. Exige « update » sur l'information source. */
    public function unrelate(Request $request, array $params): ActionResult
    {
        $id = (int) ($request->int('id') ?? 0);
        $relation = $id > 0 ? $this->ctx->shared->relations->find($id) : null;
        if ($relation === null) {
            throw new NotFoundException('Relation introuvable (déjà supprimée ?).');
        }
        $from = $this->requireUpdatableInfo((string) $relation['from_info']);
        $to = $this->ctx->shared->registry->get((string) $relation['to_info']);
        if ($to === null || !$this->isVisibleCode((string) $to['dataset_code'])) {
            throw new ForbiddenException('L’autre information de cette relation n’est pas accessible.');
        }
        $this->ctx->shared->relations->remove($id);
        $this->log('explorer.unrelate', 'success', 'info:' . $from['id'], 'Relation supprimée', ['relation_id' => $id, 'type' => $relation['type'], 'to' => $relation['to_info']], 'data');
        return ActionResult::ok(null, 'Relation supprimée.')->refresh();
    }

    /** Téléversement multipart : champ « file » + « info ». */
    public function upload(Request $request, array $params): ActionResult
    {
        $info = $this->requireUpdatableInfo($request->string('info'));
        $file = $this->ctx->request()->file('file');
        if ($file === null) {
            throw ValidationException::single('file', 'Choisissez un fichier à joindre.');
        }
        $userId = $this->ctx->userId();
        // AttachmentService contrôle type MIME, extension, taille et quotas (ValidationException sinon).
        $record = $this->ctx->shared->attachments->store($file, (string) $info['id'], $userId);
        $this->log('explorer.upload', 'success', 'info:' . $info['id'], 'Pièce jointe ajoutée : ' . $record['original_name'], ['attachment_id' => $record['id'], 'size' => $record['size'], 'mime' => $record['mime']], 'data');
        return ActionResult::ok(['id' => $record['id']], 'Fichier « ' . $record['original_name'] . ' » joint (' . Str::humanSize((int) $record['size']) . ').')->refresh();
    }

    /** Suppression logique d'une pièce jointe : { id }. */
    public function attachmentDelete(Request $request, array $params): ActionResult
    {
        $id = trim($request->string('id'));
        $attachment = $id === '' ? null : $this->ctx->shared->attachments->find($id);
        if ($attachment === null || $attachment['info_id'] === null) {
            throw new NotFoundException('Pièce jointe introuvable.');
        }
        $info = $this->requireUpdatableInfo((string) $attachment['info_id']);
        $this->ctx->shared->attachments->softDelete($id);
        $this->log('explorer.attachment_delete', 'success', 'info:' . $info['id'], 'Pièce jointe supprimée : ' . $attachment['original_name'], ['attachment_id' => $id], 'data');
        return ActionResult::ok(null, 'Pièce jointe « ' . $attachment['original_name'] . ' » supprimée (purge par la maintenance).')->refresh();
    }

    // =====================================================================
    // Visibilité et droits
    // =====================================================================

    /** @return list<string> codes des jeux partagés lisibles par l'utilisateur (une fois par requête) */
    private function visibleCodes(): array
    {
        return $this->visibleCodes ??= $this->ctx->shared->catalog->readableCodes($this->ctx->userId());
    }

    private function isVisibleCode(string $code): bool
    {
        return in_array($code, $this->visibleCodes(), true);
    }

    private function canUpdate(string $code): bool
    {
        return $this->isVisibleCode($code) && $this->ctx->shared->catalog->canAccess($this->ctx->userId(), $code, 'update');
    }

    /**
     * Information du registre visible par l'utilisateur ; sinon 404 (sans révéler son existence).
     *
     * @return array<string, mixed>
     */
    private function requireVisibleInfo(string $infoId): array
    {
        $info = $infoId === '' ? null : $this->ctx->shared->registry->get($infoId);
        if ($info === null || !$this->isVisibleCode((string) $info['dataset_code'])) {
            throw new NotFoundException('Information introuvable ou non accessible.');
        }
        if ($info['trashed_at'] !== null) {
            // L'élément est en corbeille dans son module : on ne le présente plus comme vivant,
            // on dit où le retrouver.
            throw new NotFoundException('Cette information est en corbeille. Restaurez-la depuis la Corbeille pour la consulter.');
        }
        return $info;
    }

    /** @return array<string, mixed> */
    private function requireUpdatableInfo(string $infoId): array
    {
        $info = $this->requireVisibleInfo($infoId);
        if (!$this->canUpdate((string) $info['dataset_code'])) {
            throw new ForbiddenException('Vous ne pouvez pas modifier les informations du jeu « ' . $info['dataset_code'] . ' ».', \Atelier\Shared\DatasetCatalog::resource((string) $info['dataset_code']), 'update');
        }
        return $info;
    }

    // =====================================================================
    // Décoration et helpers
    // =====================================================================

    /**
     * Index des jeux partagés : code => nom, module, nom du module, openRoute.
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
                'module_usable' => $descriptor !== null && $descriptor->isUsable(),
                'open_route' => $openRoute,
            ];
        }
        return $this->datasetIndex = $index;
    }

    /** @return array<string, string> module => nom */
    private function moduleChoices(): array
    {
        $choices = [];
        foreach ($this->datasetIndex() as $meta) {
            $choices[$meta['module']] = $meta['module_name'];
        }
        asort($choices);
        return $choices;
    }

    /**
     * Ajoute noms de jeu/module, route d'ouverture et tags aux lignes du registre.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decorateInfos(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $index = $this->datasetIndex();
        $tags = $this->queries()->tagsOfMany(array_map(static fn (array $r): string => (string) $r['id'], $rows));
        foreach ($rows as &$row) {
            $meta = $index[$row['dataset_code']] ?? null;
            $row['dataset_name'] = $meta['name'] ?? $row['dataset_code'];
            $row['module_name'] = $meta['module_name'] ?? $row['module_id'];
            $row['open_route'] = $meta !== null && $meta['open_route'] !== null && $meta['module_usable']
                ? str_replace('{key}', rawurlencode((string) $row['local_key']), (string) $meta['open_route'])
                : null;
            $row['tags'] = $tags[(string) $row['id']] ?? [];
            $row['relation_count'] = (int) ($row['relation_count'] ?? 0);
            $row['attachment_count'] = (int) ($row['attachment_count'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    /** @return list<string> */
    private function parseTags(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $result = [];
        $seen = [];
        foreach ($parts as $part) {
            $name = is_scalar($part) ? trim(ltrim(trim((string) $part), '#')) : '';
            $normalized = Str::normalizeTag($name);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $result[] = $name;
        }
        return $result;
    }

    private function queries(): ExplorerQueries
    {
        return $this->queries ??= new ExplorerQueries($this->ctx->db);
    }

    private function banner(string $title, string $subtitle, string $actions = '', string $icon = 'layers'): string
    {
        return $this->renderCore('banner', ['icon' => $icon, 'title' => $title, 'subtitle' => $subtitle, 'actions' => $actions]);
    }

    /** Liens de navigation entre les écrans, affichés dans le bandeau. */
    private function navLinks(?string $current): string
    {
        $links = [
            'search' => ['Rechercher', 'search'],
            'datasets' => ['Jeux de données', 'database'],
            'relations' => ['Relations', 'link'],
            'attachments' => ['Pièces jointes', 'paperclip'],
        ];
        $html = '<div class="btn-group">';
        foreach ($links as $route => [$label, $icon]) {
            $html .= '<a class="btn btn--sm' . ($route === $current ? ' is-active' : '') . '" href="#" data-route="' . $route . '">' . $this->icon($icon) . ' ' . $this->e($label) . '</a>';
        }
        return $html . '</div>';
    }

    private function icon(string $name): string
    {
        return '<svg class="icon" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        $plural ??= $singular . 's';
        return $count . ' ' . ($count === 1 ? $singular : $plural);
    }
}
