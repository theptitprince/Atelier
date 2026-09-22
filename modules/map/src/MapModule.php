<?php

declare(strict_types=1);

namespace Atelier\Modules\Map;

use Atelier\Error\ForbiddenException;
use Atelier\Error\ModuleUnavailableException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;

/**
 * Module « Carte » : affiche les points GPS du module geo (jeu partagé geo.point) sur un fond
 * OpenStreetMap ou OpenSeaMap, avec ce qui leur est relié (informations rattachées, pièces jointes,
 * tags), des calques par tag et par type de lien, une liste triable et des préférences par utilisateur.
 * Le module ne possède aucune table : il consomme le service intermodule du module geo.
 */
final class MapModule extends AbstractModule
{
    public const BASE_LAYERS = ['osm' => 'OpenStreetMap', 'seamap' => 'OpenSeaMap (carte marine)'];
    public const SORTS = ['name' => 'Nom', 'updated' => 'Dernière modification', 'links' => 'Nombre de liens', 'distance' => 'Distance au centre de la carte'];

    /** Préférences enregistrées par utilisateur (portée « map »). */
    private const PREFERENCES = ['base' => 'osm', 'seamarks' => false, 'lat' => 46.6, 'lon' => 2.5, 'zoom' => 6, 'sort' => 'name'];

    public function routes(RouteCollection $r): void
    {
        $r->view('index', [$this, 'index'], permission: 'open');
        $r->action('points', [$this, 'points'], permission: 'open', methods: ['GET', 'POST']);
        $r->action('prefs', [$this, 'prefs'], permission: 'open');
    }

    public function index(Request $request, array $params): ModuleView
    {
        $available = $this->geoAvailable();
        $prefs = $this->preferences();
        $focus = $request->int('point');
        $content = $this->render('index', [
            'available' => $available,
            'geoModule' => $this->ctx->modules()->has('geo'),
            'baseLayers' => self::BASE_LAYERS,
            'sorts' => self::SORTS,
            'prefs' => $prefs,
        ]);
        $banner = $this->renderCore('banner', [
            'icon' => 'map',
            'title' => 'Carte',
            'subtitle' => $available === true ? 'Points GPS et informations reliées' : $available,
            'actions' => '<button type="button" class="btn btn--ghost" data-map-reload title="Recharger les points">' . $this->icon('refresh') . '<span>Actualiser</span></button>'
                . ($this->ctx->modules()->has('geo') ? '<a class="btn" href="#" data-open-module="geo" data-open-route="list">' . $this->icon('map-pin') . '<span>Points GPS</span></a>' : ''),
        ]);
        return ModuleView::make('Carte')->banner($banner)->content($content)->status('Carte des points GPS')
            ->state(['prefs' => $prefs, 'focus' => $focus, 'available' => $available === true, 'tileBase' => $this->ctx->baseUrl() . '/module-assets/map/assets/vendor/leaflet/']);
    }

    /**
     * Points avec ce qui leur est relié : { points: [{ id, name, code, lat, lon, altitude, address, description,
     * decimal, dms, updated_at, tags: [..], links: [{label, module, dataset, key}], attachments: n, geoInfoId }] }.
     *
     * @return array<string, mixed>
     */
    public function points(Request $request, array $params): array
    {
        $available = $this->geoAvailable();
        if ($available !== true) {
            throw new ForbiddenException($available, 'atelier/geo/data/point', 'read');
        }
        $geo = $this->ctx->moduleService('geo');
        $points = [];
        $registry = $this->ctx->shared->registry;
        foreach ($geo->all() as $point) {
            $info = $registry->find('geo.point', (string) $point['id']);
            $infoId = $info !== null ? (string) $info['id'] : null;
            $tags = $infoId !== null ? array_map(static fn (array $t): string => (string) $t['name'], $this->ctx->shared->tags->tagsOf($infoId)) : [];
            $links = [];
            foreach ($geo->infosAt((int) $point['id']) as $linked) {
                $links[] = ['label' => $linked['label'] !== '' ? $linked['label'] : $linked['dataset'] . ' #' . $linked['key'], 'module' => $linked['module'], 'dataset' => $linked['dataset'], 'key' => $linked['key']];
            }
            $points[] = [
                'id' => (int) $point['id'],
                'name' => (string) $point['name'],
                'code' => $point['code'],
                'lat' => (float) $point['latitude'],
                'lon' => (float) $point['longitude'],
                'altitude' => $point['altitude'],
                'address' => $point['address'],
                'description' => $point['description'] !== null ? mb_substr((string) $point['description'], 0, 300, 'UTF-8') : null,
                'decimal' => $point['decimal'],
                'dms' => $point['dms'],
                'updated_at' => $point['updated_at'],
                'tags' => $tags,
                'links' => $links,
                'attachments' => $infoId !== null ? $this->ctx->shared->attachments->countFor($infoId) : 0,
            ];
        }
        return ['points' => $points, 'modules' => $this->moduleLabels($points)];
    }

    /** Enregistre les préférences de carte de l'utilisateur (fond, balisage, position, tri). */
    public function prefs(Request $request, array $params): ActionResult
    {
        $userId = $this->ctx->userId();
        $base = $request->string('base', 'osm');
        if (!isset(self::BASE_LAYERS[$base])) {
            throw ValidationException::single('base', 'Fond de carte inconnu.');
        }
        $sort = $request->string('sort', 'name');
        $values = [
            'base' => $base,
            'seamarks' => $request->bool('seamarks'),
            'lat' => max(-90.0, min(90.0, (float) $request->input('lat', self::PREFERENCES['lat']))),
            'lon' => max(-180.0, min(180.0, (float) $request->input('lon', self::PREFERENCES['lon']))),
            'zoom' => max(1, min(19, $request->int('zoom', self::PREFERENCES['zoom']) ?? self::PREFERENCES['zoom'])),
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'name',
        ];
        foreach ($values as $name => $value) {
            $this->ctx->settings->setPreference($userId, $name, $value, $this->id());
        }
        return ActionResult::ok($values);
    }

    public function icon(string $name, string $extra = ''): string
    {
        return '<svg class="icon' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    // ----- Interne -----

    /** true si le service geo est disponible et lisible, sinon le message à afficher. */
    private function geoAvailable(): true|string
    {
        try {
            $this->ctx->moduleService('geo');
        } catch (ModuleUnavailableException) {
            return 'Le module Coordonnées GPS est indisponible : aucun point à afficher.';
        }
        if (!$this->ctx->shared->catalog->canAccess($this->ctx->userId(), 'geo.point', 'read')) {
            return 'Vous n’avez pas le droit de lecture sur le jeu de données « Points GPS » (atelier/geo/data/point).';
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function preferences(): array
    {
        $stored = $this->ctx->settings->preferencesOf($this->ctx->userId(), $this->id());
        $prefs = [];
        foreach (self::PREFERENCES as $name => $default) {
            $value = array_key_exists($name, $stored) ? $stored[$name] : $default;
            $prefs[$name] = match ($name) {
                'seamarks' => (bool) $value,
                'lat', 'lon' => (float) $value,
                'zoom' => (int) $value,
                'base' => isset(self::BASE_LAYERS[(string) $value]) ? (string) $value : 'osm',
                default => isset(self::SORTS[(string) $value]) ? (string) $value : 'name',
            };
        }
        return $prefs;
    }

    /** Libellés des modules cités dans les liens (pour les calques par module). @param list<array<string, mixed>> $points @return array<string, string> */
    private function moduleLabels(array $points): array
    {
        $labels = [];
        foreach ($points as $point) {
            foreach ($point['links'] as $link) {
                $id = (string) $link['module'];
                if (!isset($labels[$id])) {
                    $descriptor = $this->ctx->modules()->get($id);
                    $labels[$id] = $descriptor !== null ? $descriptor->name() : $id;
                }
            }
        }
        return $labels;
    }
}
