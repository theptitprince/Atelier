<?php

declare(strict_types=1);

namespace Atelier\Modules\Geo;

use Atelier\Error\ForbiddenException;
use Atelier\Error\NotFoundException;
use Atelier\Modules\ModuleContext;

/**
 * Service intermodule du jeu partagé « geo.point » : les autres modules l'obtiennent par
 * $this->ctx->moduleService('geo') et l'utilisent comme clé de rattachement géographique.
 *
 * Deux façons de relier une information à un point :
 *   1. stocker l'identifiant du point (colonne geo_point_id) et le résoudre avec find()/label() ;
 *   2. relier l'information du registre commun au point avec attach() (relation « located_at »),
 *      ce qui la fait apparaître sur la fiche du point et permet pointsOf() / infosAt().
 *
 * Chaque méthode vérifie que l'utilisateur connecté dispose du droit de lecture sur le jeu
 * (ressource atelier/geo/data/point) via le catalogue ; aucune méthode n'écrit dans geo_point.
 */
final class GeoService
{
    public const DATASET = 'geo.point';

    /** Type de relation : information (source) → point GPS (cible). */
    public const RELATION = 'located_at';

    private const PUBLIC_FIELDS = ['id', 'code', 'name', 'latitude', 'longitude', 'altitude', 'address', 'description', 'updated_at'];

    private ?GeoPointRepository $repository = null;

    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    // ----- Lecture -----

    /** @return array<string, mixed>|null point actif (champs publics + decimal, dms) */
    public function find(int $id): ?array
    {
        $this->assertReadable();
        $row = $this->repository()->find($id);
        return $row === null ? null : $this->publicRow($row);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        $this->assertReadable();
        $row = $this->repository()->findByCode($code);
        return $row === null ? null : $this->publicRow($row);
    }

    /** @return array<string, mixed> lève NotFoundException si le point n'existe pas */
    public function get(int $id): array
    {
        $point = $this->find($id);
        if ($point === null) {
            throw new NotFoundException('Point GPS introuvable (n° ' . $id . ').');
        }
        return $point;
    }

    /**
     * Points par identifiants, indexés par identifiant (les identifiants inconnus sont absents).
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        $this->assertReadable();
        return array_map([$this, 'publicRow'], $this->repository()->findMany($ids));
    }

    /** Libellé court d'un point (« Nom [code] »), ou « Point n° id » s'il a disparu. */
    public function label(int $id): string
    {
        $point = $this->find($id);
        return $point === null ? 'Point n° ' . $id : self::labelOf($point);
    }

    /** @param array<string, mixed> $point */
    public static function labelOf(array $point): string
    {
        $label = (string) $point['name'];
        if (!empty($point['code'])) {
            $label .= ' [' . $point['code'] . ']';
        }
        return $label;
    }

    /**
     * Recherche par nom, code ou adresse ; si le terme est lui-même une coordonnée,
     * retourne les points à moins de $radiusKm (avec distance_km).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, int $limit = 20, float $radiusKm = 25.0): array
    {
        $this->assertReadable();
        $center = Coordinates::tryParse($term);
        $rows = $center !== null ? $this->repository()->nearby($center, $radiusKm, $limit) : $this->repository()->search($term, $limit);
        return array_map([$this, 'publicRow'], $rows);
    }

    /** @return list<array<string, mixed>> points à moins de $radiusKm, du plus proche au plus éloigné (distance_km) */
    public function nearby(float $latitude, float $longitude, float $radiusKm = 10.0, int $limit = 20): array
    {
        $this->assertReadable();
        return array_map([$this, 'publicRow'], $this->repository()->nearby(new Coordinates($latitude, $longitude), $radiusKm, $limit));
    }

    /**
     * Options pour un sélecteur : [['id' => 1, 'label' => 'Nom [code]'], ...], triées par nom.
     *
     * @return list<array{id: int, label: string}>
     */
    public function options(): array
    {
        $this->assertReadable();
        return array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'label' => self::labelOf($p)], $this->repository()->all());
    }

    /**
     * Tous les points actifs (cartes, exports), triés par nom.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $this->assertReadable();
        return array_map([$this, 'publicRow'], $this->repository()->all());
    }

    /** Coordonnées d'un point sous forme d'objet (formats, distances). */
    public function coordinates(int $id): Coordinates
    {
        $point = $this->get($id);
        return new Coordinates((float) $point['latitude'], (float) $point['longitude']);
    }

    public function distanceKm(int $fromId, int $toId): float
    {
        return $this->coordinates($fromId)->distanceTo($this->coordinates($toId));
    }

    // ----- Rattachement par le registre commun -----

    /** Identifiant global (registre) d'un point, créé si nécessaire. */
    public function infoId(int $pointId): string
    {
        $point = $this->get($pointId);
        return $this->ctx->shared->registry->register(self::DATASET, (string) $pointId, self::labelOf($point), $this->ctx->auth->userId());
    }

    /** Relie une information du registre (UUID) à un point : relation « located_at ». */
    public function attach(string $infoId, int $pointId, ?string $comment = null): int
    {
        $this->assertReadable();
        if ($this->ctx->shared->registry->get($infoId) === null) {
            throw new NotFoundException('Information introuvable dans le registre commun.');
        }
        return $this->ctx->shared->relations->relate(self::RELATION, $infoId, $this->infoId($pointId), $this->ctx->auth->userId(), $comment);
    }

    public function detach(string $infoId, int $pointId): void
    {
        $this->assertReadable();
        $target = $this->ctx->shared->registry->find(self::DATASET, (string) $pointId);
        if ($target === null) {
            return;
        }
        // Relations en corbeille incluses : un détachement explicite doit aboutir même si le point
        // est à la corbeille (sinon la relation réapparaîtrait à sa restauration).
        foreach ($this->ctx->shared->relations->relationsOf($infoId, true) as $relation) {
            if ($relation['type'] === self::RELATION && $relation['direction'] === 'out' && $relation['other_id'] === $target['id']) {
                $this->ctx->shared->relations->remove((int) $relation['id']);
            }
        }
    }

    /**
     * Points auxquels une information est rattachée (relation « located_at »), avec relation_id.
     *
     * @return list<array<string, mixed>>
     */
    public function pointsOf(string $infoId): array
    {
        $this->assertReadable();
        $ids = [];
        $relationIds = [];
        foreach ($this->ctx->shared->relations->relationsOf($infoId) as $relation) {
            if ($relation['type'] === self::RELATION && $relation['direction'] === 'out' && $relation['other_dataset'] === self::DATASET) {
                $id = (int) $relation['other_key'];
                $ids[] = $id;
                $relationIds[$id] = (int) $relation['id'];
            }
        }
        $points = [];
        foreach ($this->repository()->findMany($ids) as $id => $row) {
            $point = $this->publicRow($row);
            $point['relation_id'] = $relationIds[$id] ?? null;
            $points[] = $point;
        }
        usort($points, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        return $points;
    }

    /**
     * Informations rattachées à un point, limitées aux jeux partagés que l'utilisateur peut lire
     * (un jeu privé n'est jamais exposé, conformément au catalogue).
     *
     * @return list<array{relation_id: int, info_id: string, label: string, dataset: string, module: string, key: string, comment: ?string}>
     */
    public function infosAt(int $pointId): array
    {
        $this->assertReadable();
        $target = $this->ctx->shared->registry->find(self::DATASET, (string) $pointId);
        if ($target === null) {
            return [];
        }
        $readable = array_flip($this->ctx->shared->catalog->readableCodes($this->viewerId()));
        $result = [];
        foreach ($this->ctx->shared->relations->relationsOf((string) $target['id']) as $relation) {
            if ($relation['type'] !== self::RELATION || $relation['direction'] !== 'in' || !isset($readable[$relation['other_dataset']])) {
                continue;
            }
            $result[] = [
                'relation_id' => (int) $relation['id'],
                'info_id' => (string) $relation['other_id'],
                'label' => (string) ($relation['other_label'] ?? ''),
                'dataset' => (string) $relation['other_dataset'],
                'module' => (string) $relation['other_module'],
                'key' => (string) $relation['other_key'],
                'comment' => $relation['comment'] !== null ? (string) $relation['comment'] : null,
            ];
        }
        return $result;
    }

    // ----- Interne -----

    private function assertReadable(): void
    {
        if (!$this->ctx->shared->catalog->canAccess($this->viewerId(), self::DATASET, 'read')) {
            throw new ForbiddenException('Accès au jeu de données « ' . self::DATASET . ' » refusé.', 'atelier/geo/data/point', 'read');
        }
    }

    private function viewerId(): int
    {
        return $this->ctx->userId();
    }

    private function repository(): GeoPointRepository
    {
        return $this->repository ??= new GeoPointRepository($this->ctx->db);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        $public = array_intersect_key($row, array_flip(self::PUBLIC_FIELDS));
        $public['id'] = (int) $public['id'];
        $public['latitude'] = (float) $public['latitude'];
        $public['longitude'] = (float) $public['longitude'];
        $public['altitude'] = $public['altitude'] === null ? null : (float) $public['altitude'];
        $coordinates = new Coordinates($public['latitude'], $public['longitude']);
        $public['decimal'] = $coordinates->decimal();
        $public['dms'] = $coordinates->dms();
        $public['label'] = self::labelOf($public);
        if (isset($row['distance_km'])) {
            $public['distance_km'] = (float) $row['distance_km'];
        }
        return $public;
    }
}
