<?php

declare(strict_types=1);

namespace Atelier\Modules\Geo;

use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleContext;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Modules\TrashProviderInterface;
use Atelier\Support\Clock;
use Atelier\Support\Str;

/**
 * Module « Coordonnées GPS » : référentiel de points géographiques nommés, partagé entre les
 * utilisateurs autorisés et exposé aux autres modules par GeoService (jeu partagé geo.point).
 *
 * Chaque point est inscrit au registre commun des informations : il peut recevoir des tags,
 * des pièces jointes et des relations, et sert de clé de rattachement (relation « located_at »)
 * aux informations des autres modules.
 *
 * Les points supprimés (corbeille du module) sont exposés à la corbeille globale (TrashProviderInterface).
 */
final class GeoModule extends AbstractModule implements TrashProviderInterface
{
    private const PER_PAGE_CHOICES = [25, 50, 100];
    private const NEAR_RADIUS_KM = 25.0;
    private const NEARBY_ON_SHOW = 5;
    private const IMPORT_MAX_ROWS = 5000;
    private const IMPORT_RESOURCE = 'action/import';
    private const EXPORT_RESOURCE = 'action/export';

    private const NAME_MAX = 200;
    private const CODE_MAX = 32;
    private const ADDRESS_MAX = 300;
    private const DESCRIPTION_MAX = 5000;
    private const ALTITUDE_MIN = -500.0;
    private const ALTITUDE_MAX = 9000.0;

    /** Colonnes reconnues à l'import CSV : champ => en-têtes acceptés (sans accent, minuscules). */
    private const IMPORT_HEADERS = [
        'name' => ['nom', 'name', 'libelle', 'titre', 'title', 'point'],
        'code' => ['code', 'id', 'identifiant', 'ref', 'reference'],
        'latitude' => ['latitude', 'lat', 'y'],
        'longitude' => ['longitude', 'lon', 'lng', 'long', 'x'],
        'coordinates' => ['coordonnees', 'coordinates', 'coords', 'gps', 'position'],
        'altitude' => ['altitude', 'alt', 'elevation', 'z'],
        'address' => ['adresse', 'address', 'lieu', 'lieu-dit', 'localisation'],
        'description' => ['description', 'desc', 'commentaire', 'comment', 'remarque', 'notes'],
    ];

    private ?GeoPointRepository $repository = null;
    private ?GeoService $serviceInstance = null;

    public function boot(ModuleContext $context): void
    {
        parent::boot($context);
        $this->repository = null;
        $this->serviceInstance = null;
    }

    public function routes(RouteCollection $r): void
    {
        $r->view('list', [$this, 'list'], permission: 'open');
        $r->view('new', [$this, 'new'], permission: 'create');
        $r->view('edit/{id}', [$this, 'edit'], permission: 'update');
        $r->view('show/{id}', [$this, 'show'], permission: 'open');
        $r->view('trash', [$this, 'trash'], permission: 'delete');
        $r->view('import', [$this, 'importForm'], permission: 'import', resource: self::IMPORT_RESOURCE);

        $r->action('filter', [$this, 'filter'], permission: 'open');
        $r->action('save', [$this, 'save'], permission: 'open'); // create ou update vérifié dans le gestionnaire
        $r->action('delete', [$this, 'delete'], permission: 'delete');
        $r->action('restore', [$this, 'restore'], permission: 'delete');
        $r->action('purge', [$this, 'destroy'], permission: 'delete');
        $r->action('link', [$this, 'link'], permission: 'update');
        $r->action('unlink', [$this, 'unlink'], permission: 'update');
        $r->action('search-info', [$this, 'searchInfo'], permission: 'update');
        $r->action('tag-add', [$this, 'tagAdd'], permission: 'update');
        $r->action('tag-remove', [$this, 'tagRemove'], permission: 'update');
        $r->action('parse', [$this, 'parse'], permission: 'open');
        $r->action('lookup', [$this, 'lookup'], permission: 'open', methods: ['GET', 'POST']);
        $r->action('import', [$this, 'importRun'], permission: 'import', resource: self::IMPORT_RESOURCE);

        $r->raw('export.csv', [$this, 'export'], permission: 'export', resource: self::EXPORT_RESOURCE);
    }

    /** Service intermodule du jeu partagé geo.point. */
    public function service(): ?object
    {
        return $this->serviceInstance ??= new GeoService($this->ctx);
    }

    /** Données de démonstration : quelques points connus, si la table est vide. */
    public function seed(): string
    {
        $repository = $this->repository();
        if ($repository->countAll() > 0) {
            return 'points déjà présents';
        }
        $samples = [
            ['TOUR-EIFFEL', 'Tour Eiffel', 48.858370, 2.294481, 330, 'Champ de Mars, 75007 Paris', 'Monument emblématique de Paris.'],
            ['NOTRE-DAME', 'Cathédrale Notre-Dame de Paris', 48.852968, 2.349902, 35, 'Île de la Cité, 75004 Paris', null],
            ['LYON-FOURVIERE', 'Basilique de Fourvière', 45.762365, 4.822621, 287, '8 place de Fourvière, 69005 Lyon', null],
            ['MARSEILLE-VP', 'Vieux-Port de Marseille', 43.295000, 5.374000, 2, 'Quai des Belges, 13001 Marseille', null],
            ['MONT-BLANC', 'Sommet du Mont Blanc', 45.832622, 6.865175, 4806, 'Chamonix-Mont-Blanc', 'Point culminant des Alpes.'],
            ['POINTE-RAZ', 'Pointe du Raz', 48.037500, -4.738056, 70, 'Plogoff, Finistère', null],
            ['STRASBOURG-CATH', 'Cathédrale de Strasbourg', 48.581944, 7.750833, 142, 'Place de la Cathédrale, 67000 Strasbourg', null],
        ];
        $userId = $this->ctx->auth->userId();
        foreach ($samples as [$code, $name, $lat, $lon, $alt, $address, $description]) {
            $id = $repository->create(['code' => $code, 'name' => $name, 'latitude' => $lat, 'longitude' => $lon, 'altitude' => $alt, 'address' => $address, 'description' => $description], $userId);
            $this->registerInfo($id, $name, $code);
        }
        return count($samples) . ' points d’exemple créés';
    }

    /** Rétention de la corbeille (trash.retention_days). */
    public function purge(): string
    {
        $days = $this->retentionDays();
        $count = 0;
        foreach ($this->repository()->expiredTrashIds($days) as $id) {
            $this->ctx->shared->registry->unregister(GeoService::DATASET, (string) $id);
            $this->repository()->deleteById($id);
            $count++;
        }
        return $count . ' point(s) purgé(s) de la corbeille (> ' . $days . ' jours)';
    }

    // =====================================================================
    // Vues
    // =====================================================================

    public function list(Request $request, array $params): ModuleView
    {
        $query = $this->listQuery($request->allQuery());
        $center = $query['q'] !== '' ? Coordinates::tryParse($query['q']) : null;
        $rights = $this->rights(['create', 'update', 'delete', 'import', 'export']);
        $rights['import'] = $rights['import'] && $this->can('import', self::IMPORT_RESOURCE);
        $rights['export'] = $rights['export'] && $this->can('export', self::EXPORT_RESOURCE);

        if ($center !== null) {
            $all = $this->repository()->nearby($center, self::NEAR_RADIUS_KM, 500);
            $total = count($all);
            $rows = array_slice($all, ($query['page'] - 1) * $query['per_page'], $query['per_page']);
        } else {
            $result = $this->repository()->paginate($query['q'], $query['page'], $query['per_page'], $query['sort'], $query['dir']);
            $rows = $result['rows'];
            $total = (int) $result['total'];
        }
        $rows = array_map([$this, 'decorate'], $rows);

        $content = $this->render('list', [
            'rows' => $rows,
            'total' => $total,
            'query' => $query,
            'center' => $center,
            'radiusKm' => self::NEAR_RADIUS_KM,
            'rights' => $rights,
            'perPageChoices' => self::PER_PAGE_CHOICES,
            'route' => $this->listRoute($query),
        ]);

        $actions = '<a class="btn btn--ghost" href="#" data-route="' . $this->e($this->listRoute($query)) . '" title="Actualiser">' . $this->icon('refresh') . '<span>Actualiser</span></a>';
        if ($rights['export']) {
            $actions .= '<a class="btn" href="' . $this->e($this->url('export.csv')) . '" download title="Exporter tous les points au format CSV">' . $this->icon('download') . '<span>Exporter CSV</span></a>';
        }
        if ($rights['import']) {
            $actions .= '<a class="btn" href="#" data-route="import">' . $this->icon('upload') . '<span>Importer</span></a>';
        }
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="new">' . $this->icon('plus') . '<span>Nouveau point</span></a>';
        }
        $subtitle = $center !== null
            ? sprintf('%d point(s) à moins de %d km de %s', $total, (int) self::NEAR_RADIUS_KM, $center->dms())
            : $this->countLabel($total, $query['q'] !== '');
        $banner = $this->renderCore('banner', ['icon' => 'map-pin', 'title' => 'Coordonnées GPS', 'subtitle' => $subtitle, 'actions' => $actions]);

        return ModuleView::make('Coordonnées GPS')->banner($banner)->content($content)->status($subtitle)->route($this->listRoute($query));
    }

    public function new(Request $request, array $params): ModuleView
    {
        $point = ['id' => null, 'code' => '', 'name' => '', 'coordinates' => '', 'altitude' => '', 'address' => '', 'description' => ''];
        $prefill = trim((string) $request->query('coordinates', ''));
        if ($prefill !== '') {
            $point['coordinates'] = $prefill;
        }
        $content = $this->render('form', ['point' => $point, 'isNew' => true, 'rights' => $this->rights(['delete'])]);
        $banner = $this->renderCore('banner', [
            'icon' => 'map-pin',
            'title' => 'Nouveau point GPS',
            'subtitle' => 'Référencer un lieu',
            'actions' => '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Retour à la liste</span></a>',
        ]);
        return ModuleView::make('Nouveau point GPS')->banner($banner)->content($content)->status('Création d’un point');
    }

    public function edit(Request $request, array $params): ModuleView
    {
        $point = $this->requirePoint((int) ($params['id'] ?? 0));
        $coordinates = new Coordinates((float) $point['latitude'], (float) $point['longitude']);
        $point['coordinates'] = $coordinates->decimal();
        $content = $this->render('form', ['point' => $point, 'isNew' => false, 'rights' => $this->rights(['delete'])]);
        $banner = $this->renderCore('banner', [
            'icon' => 'map-pin',
            'title' => (string) $point['name'],
            'subtitle' => 'Modification · ' . $coordinates->dms(),
            'actions' => '<a class="btn btn--ghost" href="#" data-route="show/' . (int) $point['id'] . '">' . $this->icon('chevron-left') . '<span>Fiche du point</span></a>',
        ]);
        return ModuleView::make('Point GPS · ' . $point['name'])->banner($banner)->content($content)->status('Modification de « ' . $point['name'] . ' »');
    }

    public function show(Request $request, array $params): ModuleView
    {
        $point = $this->decorate($this->requirePoint((int) ($params['id'] ?? 0)));
        $id = (int) $point['id'];
        $rights = $this->rights(['update', 'delete']);
        $info = $this->ctx->shared->registry->find(GeoService::DATASET, (string) $id);
        $infoId = $info !== null ? (string) $info['id'] : null;

        $tags = $infoId !== null ? $this->ctx->shared->tags->tagsOf($infoId) : [];
        $attachments = $infoId !== null ? $this->ctx->shared->attachments->listFor($infoId) : [];
        $linked = [];
        try {
            $linked = $this->geoService()->infosAt($id);
        } catch (\Atelier\Error\ForbiddenException) {
            // l'utilisateur ouvre le module sans droit de lecture sur le jeu partagé : liste vide
        }
        $nearby = array_map([$this, 'decorate'], $this->repository()->nearby($point['coords'], self::NEAR_RADIUS_KM, self::NEARBY_ON_SHOW, $id));

        $content = $this->render('show', [
            'point' => $point,
            'infoId' => $infoId,
            'tags' => $tags,
            'linked' => $linked,
            'attachments' => $attachments,
            'nearby' => $nearby,
            'radiusKm' => self::NEAR_RADIUS_KM,
            'rights' => $rights,
            'author' => $point['created_by'] !== null ? $this->ctx->users->find((int) $point['created_by']) : null,
            'attachmentsModule' => $this->ctx->modules()->has('attachments'),
        ]);

        $actions = '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Liste</span></a>';
        if ($rights['update']) {
            $actions .= '<a class="btn" href="#" data-route="edit/' . $id . '">' . $this->icon('edit') . '<span>Modifier</span></a>';
        }
        if ($rights['delete']) {
            $actions .= '<button type="button" class="btn btn--outline-danger" data-action="delete" data-params=\'{"id":' . $id . '}\' data-confirm="Mettre ce point à la corbeille ? Les rattachements sont conservés jusqu’à la purge." data-danger>' . $this->icon('trash') . '<span>Supprimer</span></button>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'map-pin', 'title' => (string) $point['name'], 'subtitle' => $point['dms'], 'actions' => $actions]);
        return ModuleView::make('Point GPS · ' . $point['name'])->banner($banner)->content($content)->status($point['name'] . ' · ' . $point['decimal']);
    }

    public function trash(Request $request, array $params): ModuleView
    {
        $days = $this->retentionDays();
        $rows = array_map([$this, 'decorate'], $this->repository()->trashed($days));
        $content = $this->render('trash', ['rows' => $rows, 'retentionDays' => $days]);
        $actions = '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Liste</span></a>';
        if ($this->ctx->modules()->has('trash')) {
            $actions .= '<a class="btn btn--ghost" href="#" data-open-module="trash" title="Corbeille globale : tous les modules et les pièces jointes">' . $this->icon('trash') . '<span>Voir toute la corbeille</span></a>';
        }
        $banner = $this->renderCore('banner', [
            'icon' => 'trash',
            'title' => 'Corbeille des points GPS',
            'subtitle' => count($rows) . ' point(s) · purge automatique après ' . $days . ' jours',
            'actions' => $actions,
        ]);
        return ModuleView::make('Corbeille · points GPS')->banner($banner)->content($content)->status(count($rows) . ' point(s) en corbeille');
    }

    public function importForm(Request $request, array $params): ModuleView
    {
        $content = $this->render('import', ['maxRows' => self::IMPORT_MAX_ROWS, 'headers' => self::IMPORT_HEADERS]);
        $banner = $this->renderCore('banner', [
            'icon' => 'upload',
            'title' => 'Importer des points GPS',
            'subtitle' => 'Fichier CSV (UTF-8, séparateur ; ou ,)',
            'actions' => '<a class="btn btn--ghost" href="#" data-route="list">' . $this->icon('chevron-left') . '<span>Liste</span></a>',
        ]);
        return ModuleView::make('Import de points GPS')->banner($banner)->content($content)->status('Import CSV');
    }

    // =====================================================================
    // Actions
    // =====================================================================

    public function filter(Request $request, array $params): ActionResult
    {
        $query = $this->listQuery($request->all());
        $query['page'] = 1;
        return ActionResult::ok()->navigate($this->listRoute($query));
    }

    public function save(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        if ($id === null || $id <= 0) {
            $this->require('create', null, 'Vous n’avez pas le droit de créer des points.');
            $data = $this->validate($request->all(), null);
            $id = $this->repository()->create($data, $this->ctx->userId());
            $this->registerInfo($id, $data['name'], $data['code']);
            $this->log('geo.create', 'success', 'geo_point:' . $id, 'Point créé : ' . $data['name'], ['latitude' => $data['latitude'], 'longitude' => $data['longitude']]);
            return ActionResult::ok(['id' => $id], 'Point « ' . $data['name'] . ' » créé.')->navigate('show/' . $id);
        }

        $this->require('update', null, 'Vous n’avez pas le droit de modifier des points.');
        $this->requirePoint($id);
        $data = $this->validate($request->all(), $id);
        $this->repository()->update($id, $data);
        $this->registerInfo($id, $data['name'], $data['code']);
        $this->log('geo.update', 'success', 'geo_point:' . $id, 'Point modifié : ' . $data['name'], ['latitude' => $data['latitude'], 'longitude' => $data['longitude']]);
        return ActionResult::ok(['id' => $id], 'Point « ' . $data['name'] . ' » enregistré.')->navigate('show/' . $id);
    }

    public function delete(Request $request, array $params): ActionResult
    {
        $point = $this->requirePoint($this->requireId($request));
        $this->repository()->softDelete((int) $point['id']);
        $this->log('geo.delete', 'success', 'geo_point:' . $point['id'], 'Point mis à la corbeille : ' . $point['name']);
        return ActionResult::ok(['id' => (int) $point['id']], 'Point « ' . $point['name'] . ' » mis à la corbeille.')->navigate('list');
    }

    public function restore(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $point = $this->restoreTrashed($id, 'Point restauré');
        return ActionResult::ok(['id' => $id], 'Point « ' . $point['name'] . ' » restauré.')->refresh();
    }

    /** Suppression définitive d'un point de la corbeille (action « purge »). */
    public function destroy(Request $request, array $params): ActionResult
    {
        $point = $this->purgeTrashed($this->requireId($request), 'Point supprimé définitivement');
        return ActionResult::ok(null, 'Point « ' . $point['name'] . ' » supprimé définitivement.')->refresh();
    }

    /** Rattache une information du registre (UUID) au point. */
    public function link(Request $request, array $params): ActionResult
    {
        $point = $this->requirePoint($this->requireId($request));
        $infoId = $request->string('info_id');
        if ($infoId === '' || !preg_match('/^[0-9a-f-]{36}$/', $infoId)) {
            throw ValidationException::single('info_id', 'Choisissez une information à rattacher.');
        }
        $info = $this->ctx->shared->registry->get($infoId);
        if ($info === null || !in_array($info['dataset_code'], $this->ctx->shared->catalog->readableCodes($this->ctx->userId()), true)) {
            throw ValidationException::single('info_id', 'Cette information n’est pas accessible.');
        }
        $this->geoService()->attach($infoId, (int) $point['id'], $request->string('comment') ?: null);
        $this->log('geo.link', 'success', 'geo_point:' . $point['id'], 'Information rattachée : ' . ($info['label'] ?? $infoId), ['info_id' => $infoId, 'dataset' => $info['dataset_code']]);
        return ActionResult::ok(['id' => (int) $point['id']], '« ' . ($info['label'] ?? 'Information') . ' » rattachée au point.')->refresh();
    }

    public function unlink(Request $request, array $params): ActionResult
    {
        $point = $this->requirePoint($this->requireId($request));
        $relationId = $this->requireId($request, 'relation_id');
        $relation = $this->ctx->shared->relations->find($relationId);
        $target = $this->ctx->shared->registry->find(GeoService::DATASET, (string) $point['id']);
        if ($relation === null || $target === null || $relation['to_info'] !== $target['id'] || $relation['type'] !== GeoService::RELATION) {
            throw new NotFoundException('Ce rattachement n’existe pas pour ce point.');
        }
        $this->ctx->shared->relations->remove($relationId);
        $this->log('geo.unlink', 'success', 'geo_point:' . $point['id'], 'Rattachement retiré', ['relation_id' => $relationId]);
        return ActionResult::ok(null, 'Rattachement retiré.')->refresh();
    }

    /** Recherche d'informations rattachables (jeux partagés lisibles, hors points GPS). */
    public function searchInfo(Request $request, array $params): ActionResult
    {
        $term = $request->string('q');
        if (mb_strlen($term, 'UTF-8') < 2) {
            return ActionResult::ok(['items' => []]);
        }
        $codes = array_values(array_filter($this->ctx->shared->catalog->readableCodes($this->ctx->userId()), static fn (string $c): bool => $c !== GeoService::DATASET));
        $items = [];
        foreach ($this->ctx->shared->registry->search($term, $codes, 15) as $row) {
            $items[] = ['id' => (string) $row['id'], 'label' => (string) ($row['label'] ?? $row['local_key']), 'dataset' => (string) $row['dataset_code'], 'module' => (string) $row['module_id']];
        }
        return ActionResult::ok(['items' => $items]);
    }

    public function tagAdd(Request $request, array $params): ActionResult
    {
        $point = $this->requirePoint($this->requireId($request));
        $names = array_values(array_filter(array_map('trim', explode(',', $request->string('tag'))), static fn (string $n): bool => $n !== ''));
        if ($names === []) {
            throw ValidationException::single('tag', 'Indiquez au moins un tag.');
        }
        $infoId = $this->geoService()->infoId((int) $point['id']);
        $added = [];
        foreach (array_slice($names, 0, 5) as $name) {
            $added[] = $this->ctx->shared->tags->attach($infoId, $name, \Atelier\Shared\TagService::SHARED, $this->ctx->userId())['name'];
        }
        $this->log('geo.tag_add', 'success', 'geo_point:' . $point['id'], 'Tag(s) ajouté(s) : ' . implode(', ', $added));
        return ActionResult::ok(['tags' => $added], 'Tag(s) ajouté(s) : ' . implode(', ', $added) . '.')->refresh();
    }

    public function tagRemove(Request $request, array $params): ActionResult
    {
        $point = $this->requirePoint($this->requireId($request));
        $tagId = $this->requireId($request, 'tag_id');
        $info = $this->ctx->shared->registry->find(GeoService::DATASET, (string) $point['id']);
        if ($info !== null) {
            $this->ctx->shared->tags->detach((string) $info['id'], $tagId);
        }
        $this->log('geo.tag_remove', 'success', 'geo_point:' . $point['id'], 'Tag retiré', ['tag_id' => $tagId]);
        return ActionResult::ok(null, 'Tag retiré.')->refresh();
    }

    /** Interprète une saisie de coordonnées (aperçu dans le formulaire). */
    public function parse(Request $request, array $params): ActionResult
    {
        try {
            $coordinates = Coordinates::parse($request->string('coordinates'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::single('coordinates', $e->getMessage());
        }
        $nearby = array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'distance' => Coordinates::formatDistance((float) $p['distance_km'])], $this->repository()->nearby($coordinates, 5.0, 3, $request->int('id')));
        return ActionResult::ok($coordinates->toArray() + ['osm' => $coordinates->openStreetMapUrl(), 'geo' => $coordinates->geoUri(), 'nearby' => $nearby]);
    }

    /** Recherche pour les sélecteurs des autres modules (GET lookup?q=…&limit=…). */
    public function lookup(Request $request, array $params): array
    {
        $term = $request->string('q');
        $limit = max(1, min(50, $request->int('limit', 10) ?? 10));
        $items = [];
        foreach ($this->geoService()->search($term, $limit) as $point) {
            $items[] = [
                'id' => $point['id'],
                'label' => $point['label'],
                'decimal' => $point['decimal'],
                'dms' => $point['dms'],
                'distance' => isset($point['distance_km']) ? Coordinates::formatDistance((float) $point['distance_km']) : null,
            ];
        }
        return ['items' => $items];
    }

    /** Import CSV : une ligne par point ; les lignes invalides sont rapportées sans bloquer les autres. */
    public function importRun(Request $request, array $params): ActionResult
    {
        $this->require('import', self::IMPORT_RESOURCE, 'Vous n’êtes pas autorisé à importer des points.');
        $upload = $request->file('file');
        if ($upload === null || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ValidationException::single('file', 'Choisissez un fichier CSV.');
        }
        $rows = $this->readCsv((string) $upload['tmp_name']);
        if ($rows === []) {
            throw ValidationException::single('file', 'Le fichier ne contient aucune ligne exploitable (en-tête attendu : nom ; latitude ; longitude …).');
        }
        $created = 0;
        $errors = [];
        $userId = $this->ctx->userId();
        foreach ($rows as $line => $row) {
            $input = $row;
            if (($input['coordinates'] ?? '') === '' && (($input['latitude'] ?? '') !== '' || ($input['longitude'] ?? '') !== '')) {
                $input['coordinates'] = trim((string) ($input['latitude'] ?? '')) . ', ' . trim((string) ($input['longitude'] ?? ''));
            }
            try {
                $data = $this->validate($input, null);
                $id = $this->repository()->create($data, $userId);
                $this->registerInfo($id, $data['name'], $data['code']);
                $created++;
            } catch (ValidationException $e) {
                $errors[] = ['line' => $line, 'name' => (string) ($input['name'] ?? ''), 'message' => implode(' ', $e->fieldErrors())];
            }
        }
        $this->log('geo.import', $errors === [] ? 'success' : 'failure', 'geo_point', sprintf('Import CSV : %d créé(s), %d erreur(s)', $created, count($errors)), ['created' => $created, 'errors' => count($errors), 'file' => (string) ($upload['name'] ?? '')]);
        $message = sprintf('%d point(s) importé(s)%s.', $created, $errors !== [] ? ', ' . count($errors) . ' ligne(s) rejetée(s)' : '');
        return $errors === [] ? ActionResult::ok(['created' => $created, 'errors' => []], $message) : ActionResult::warning(['created' => $created, 'errors' => $errors], $message);
    }

    // =====================================================================
    // Export
    // =====================================================================

    public function export(Request $request, array $params): Response
    {
        $this->require('export', self::EXPORT_RESOURCE, 'Vous n’êtes pas autorisé à exporter les points.');
        $rows = $this->repository()->all();
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de préparer l’export.');
        }
        $write = static function (array $fields) use ($handle): void {
            fputcsv($handle, $fields, ';', '"', '', "\r\n");
        };
        $write(['id', 'code', 'nom', 'latitude', 'longitude', 'dms', 'altitude', 'adresse', 'description', 'cree_le', 'modifie_le']);
        foreach ($rows as $row) {
            $coordinates = new Coordinates((float) $row['latitude'], (float) $row['longitude']);
            $write([
                (string) $row['id'],
                (string) ($row['code'] ?? ''),
                (string) $row['name'],
                number_format((float) $row['latitude'], 6, '.', ''),
                number_format((float) $row['longitude'], 6, '.', ''),
                $coordinates->dms(),
                $row['altitude'] === null ? '' : (string) (float) $row['altitude'],
                (string) ($row['address'] ?? ''),
                (string) ($row['description'] ?? ''),
                Clock::formatDateTime($row['created_at'] ?? null),
                Clock::formatDateTime($row['updated_at'] ?? null),
            ]);
        }
        rewind($handle);
        $csv = "\xEF\xBB\xBF" . (string) stream_get_contents($handle);
        fclose($handle);
        $this->log('geo.export', 'success', 'geo_point', sprintf('Export CSV de %d point(s)', count($rows)), ['count' => count($rows)]);
        $fileName = 'points-gps-' . Clock::now()->setTimezone(new \DateTimeZone($this->ctx->config->string('app.timezone', 'Europe/Paris')))->format('Ymd-His') . '.csv';
        return Response::raw($csv, 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->withHeader('Content-Length', (string) strlen($csv))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    // =====================================================================
    // Corbeille globale (TrashProviderInterface) : les points sont communs, la règle du module
    // s'applique (restaurer comme purger exigent « delete », comme la vue « trash »)
    // =====================================================================

    public function trashItems(): array
    {
        $retention = $this->retentionDays();
        $canDelete = $this->can('delete');
        $items = [];
        foreach ($this->repository()->trashed($retention) as $point) {
            $deletedAt = (string) $point['deleted_at'];
            $purgeAt = Clock::parseUtc($deletedAt)?->modify('+' . $retention . ' days');
            $items[] = [
                'id' => (string) $point['id'],
                'label' => GeoService::labelOf($point),
                'dataset' => GeoService::DATASET,
                'deleted_at' => $deletedAt,
                'deleted_by' => null, // la table ne conserve pas l'auteur de la suppression
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
        $this->restoreTrashed((int) $id, 'Point restauré depuis la corbeille globale');
    }

    public function purgeTrashItem(string $id): void
    {
        $this->require('delete');
        $this->purgeTrashed((int) $id, 'Point supprimé définitivement depuis la corbeille globale');
    }

    // =====================================================================
    // Helpers publics (gabarits)
    // =====================================================================

    public function icon(string $name, string $extra = ''): string
    {
        return '<svg class="icon' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    public function formatDistance(float $km): string
    {
        return Coordinates::formatDistance($km);
    }

    // =====================================================================
    // Interne
    // =====================================================================

    /** Restaure un point en corbeille (action « restore » et corbeille globale). @return array<string, mixed> le point */
    private function restoreTrashed(int $id, string $message): array
    {
        $point = $this->requireTrashed($id);
        if (!$this->repository()->restore($id)) {
            throw new NotFoundException('Ce point n’est pas dans la corbeille.');
        }
        $this->log('geo.restore', 'success', 'geo_point:' . $id, $message . ' : ' . $point['name']);
        return $point;
    }

    /** Supprime définitivement un point en corbeille et le retire du registre commun. @return array<string, mixed> le point */
    private function purgeTrashed(int $id, string $message): array
    {
        $point = $this->requireTrashed($id);
        $this->ctx->db->transaction(function () use ($id): void {
            $this->ctx->shared->registry->unregister(GeoService::DATASET, (string) $id);
            $this->repository()->purge($id);
        });
        $this->log('geo.purge', 'success', 'geo_point:' . $id, $message . ' : ' . $point['name']);
        return $point;
    }

    /** @return array<string, mixed> point en corbeille, NotFoundException sinon */
    private function requireTrashed(int $id): array
    {
        $point = $id > 0 ? $this->repository()->find($id, true) : null;
        if ($point === null || $point['deleted_at'] === null) {
            throw new NotFoundException('Ce point n’est pas dans la corbeille.');
        }
        return $point;
    }

    private function retentionDays(): int
    {
        return max(1, $this->ctx->config->int('trash.retention_days', 30));
    }

    /**
     * Validation d'une saisie (formulaire ou ligne d'import) ; retourne les données prêtes pour le dépôt.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        $name = $string('name');
        if ($name === '') {
            $errors['name'] = 'Le nom du point est obligatoire.';
        } elseif (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            $errors['name'] = sprintf('Le nom ne peut dépasser %d caractères.', self::NAME_MAX);
        } elseif (preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            $errors['name'] = 'Le nom contient des caractères non autorisés.';
        }

        $code = $string('code');
        if ($code !== '') {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,' . (self::CODE_MAX - 1) . '}$/', $code)) {
                $errors['code'] = sprintf('Le code comporte de 1 à %d caractères : lettres, chiffres, point, tiret, souligné.', self::CODE_MAX);
            } elseif ($this->repository()->codeExists($code, $exceptId)) {
                $errors['code'] = 'Ce code est déjà utilisé par un autre point.';
            }
        }

        $latitude = null;
        $longitude = null;
        try {
            $coordinates = Coordinates::parse($string('coordinates'));
            $latitude = $coordinates->latitude;
            $longitude = $coordinates->longitude;
        } catch (\InvalidArgumentException $e) {
            $errors['coordinates'] = $e->getMessage();
        }

        $altitude = null;
        $altitudeInput = str_replace([' ', ','], ['', '.'], $string('altitude'));
        if ($altitudeInput !== '') {
            if (!is_numeric($altitudeInput)) {
                $errors['altitude'] = 'L’altitude doit être un nombre de mètres.';
            } elseif ((float) $altitudeInput < self::ALTITUDE_MIN || (float) $altitudeInput > self::ALTITUDE_MAX) {
                $errors['altitude'] = sprintf('L’altitude doit être comprise entre %d et %d m.', (int) self::ALTITUDE_MIN, (int) self::ALTITUDE_MAX);
            } else {
                $altitude = round((float) $altitudeInput, 1);
            }
        }

        $address = $string('address');
        if (mb_strlen($address, 'UTF-8') > self::ADDRESS_MAX) {
            $errors['address'] = sprintf('L’adresse ne peut dépasser %d caractères.', self::ADDRESS_MAX);
        }
        $description = $string('description');
        if (mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX) {
            $errors['description'] = sprintf('La description ne peut dépasser %d caractères.', self::DESCRIPTION_MAX);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'name' => $name,
            'code' => $code !== '' ? $code : null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude' => $altitude,
            'address' => $address !== '' ? $address : null,
            'description' => $description !== '' ? $description : null,
        ];
    }

    /**
     * Lit un CSV (UTF-8 avec ou sans BOM, séparateur ; , ou tabulation) et retourne les lignes
     * indexées par numéro de ligne, avec des clés normalisées (name, code, latitude…).
     *
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);
            return [];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $delimiter = ';';
        $best = substr_count($first, ';');
        foreach ([',' => substr_count($first, ','), "\t" => substr_count($first, "\t")] as $candidate => $count) {
            if ($count > $best) {
                $best = $count;
                $delimiter = (string) $candidate;
            }
        }
        $headers = str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', '');
        $map = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);
            foreach (self::IMPORT_HEADERS as $field => $aliases) {
                if (in_array($normalized, $aliases, true) && !isset($map[$field])) {
                    $map[$field] = $index;
                }
            }
        }
        if (!isset($map['name'])) {
            fclose($handle);
            return [];
        }
        $rows = [];
        $line = 1;
        while (($fields = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($fields === [null] || $fields === [] || implode('', array_map('strval', $fields)) === '') {
                continue;
            }
            $row = [];
            foreach ($map as $field => $index) {
                $row[$field] = trim((string) ($fields[$index] ?? ''));
            }
            $rows[$line] = $row;
            if (count($rows) >= self::IMPORT_MAX_ROWS) {
                break;
            }
        }
        fclose($handle);
        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header), 'UTF-8');
        $header = strtr($header, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']);
        return trim(preg_replace('/[^a-z0-9-]+/', '-', $header) ?? $header, '-');
    }

    /** Inscrit ou met à jour le point dans le registre commun (libellé « Nom [code] »). */
    private function registerInfo(int $id, string $name, ?string $code): void
    {
        $this->ctx->shared->registry->register(GeoService::DATASET, (string) $id, GeoService::labelOf(['name' => $name, 'code' => $code]), $this->ctx->auth->userId());
    }

    /**
     * Ajoute les formats dérivés à une ligne : coords (objet), decimal, dms, links (nombre de relations).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        $coordinates = new Coordinates((float) $row['latitude'], (float) $row['longitude']);
        $row['coords'] = $coordinates;
        $row['decimal'] = $coordinates->decimal();
        $row['dms'] = $coordinates->dms();
        $info = $this->ctx->shared->registry->find(GeoService::DATASET, (string) $row['id']);
        $row['links'] = $info === null ? 0 : $this->ctx->shared->relations->countFor((string) $info['id']);
        $row['attachments'] = $info === null ? 0 : $this->ctx->shared->attachments->countFor((string) $info['id']);
        return $row;
    }

    /** @return array<string, mixed>|null */
    private function requirePoint(int $id): array
    {
        $point = $id > 0 ? $this->repository()->find($id) : null;
        if ($point === null) {
            throw new NotFoundException('Point GPS introuvable.');
        }
        return $point;
    }

    private function requireId(Request $request, string $key = 'id'): int
    {
        $id = $request->int($key);
        if ($id === null || $id <= 0) {
            throw ValidationException::single($key, 'Identifiant manquant.');
        }
        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{q: string, sort: string, dir: string, page: int, per_page: int}
     */
    private function listQuery(array $input): array
    {
        $default = (int) $this->ctx->settings->preference($this->ctx->userId(), 'pageSize', 25);
        $perPage = (int) ($input['per_page'] ?? $default);
        $sort = (string) ($input['sort'] ?? 'name');
        return [
            'q' => is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '',
            'sort' => GeoPointRepository::isSortable($sort) ? $sort : 'name',
            'dir' => strtolower((string) ($input['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PER_PAGE_CHOICES, true) ? $perPage : (in_array($default, self::PER_PAGE_CHOICES, true) ? $default : 25),
        ];
    }

    /** @param array{q: string, sort: string, dir: string, page: int, per_page: int} $query */
    private function listRoute(array $query, array $overrides = []): string
    {
        $params = array_filter($overrides + [
            'q' => $query['q'],
            'sort' => $query['sort'] !== 'name' ? $query['sort'] : null,
            'dir' => $query['dir'] !== 'asc' ? $query['dir'] : null,
            'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null,
            'page' => $query['page'] > 1 ? $query['page'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return $params === [] ? 'list' : 'list?' . http_build_query($params);
    }

    private function countLabel(int $total, bool $filtered): string
    {
        $label = $total === 1 ? '1 point' : number_format($total, 0, ',', ' ') . ' points';
        return $filtered ? $label . ' (filtrés)' : $label;
    }

    private function repository(): GeoPointRepository
    {
        return $this->repository ??= new GeoPointRepository($this->ctx->db);
    }

    private function geoService(): GeoService
    {
        /** @var GeoService $service */
        $service = $this->service();
        return $service;
    }
}
