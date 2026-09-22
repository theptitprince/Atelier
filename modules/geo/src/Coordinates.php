<?php

declare(strict_types=1);

namespace Atelier\Modules\Geo;

use InvalidArgumentException;

/**
 * Couple latitude/longitude (WGS 84, degrés décimaux), immuable.
 *
 * Formats de saisie acceptés par parse() :
 *   - degrés décimaux : "48.8566, 2.3522", "48,8566 2,3522", "48.8566N 2.3522E", "-33.86 151.21" ;
 *   - degrés minutes secondes : "48°51'24\"N 2°21'03\"E", "48 51 24 N, 2 21 3 E" ;
 *   - degrés minutes décimales : "N 48°51.400' E 2°21.050'".
 * Les lettres N/S/E/W (et O pour ouest) fixent le signe et l'axe ; sans lettre, l'ordre est latitude puis longitude.
 */
final class Coordinates
{
    /** Rayon moyen de la Terre en kilomètres (IUGG). */
    public const EARTH_RADIUS_KM = 6371.0088;

    public function __construct(public readonly float $latitude, public readonly float $longitude)
    {
        if (!is_finite($latitude) || $latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('La latitude doit être comprise entre -90 et 90.');
        }
        if (!is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('La longitude doit être comprise entre -180 et 180.');
        }
    }

    public static function tryParse(string $input): ?self
    {
        try {
            return self::parse($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Interprète une saisie libre ; lève InvalidArgumentException avec un message lisible. */
    public static function parse(string $input): self
    {
        $text = trim($input);
        if ($text === '') {
            throw new InvalidArgumentException('Indiquez des coordonnées.');
        }
        $text = str_replace(['′', '’', '‘', '´', '`'], "'", $text);
        $text = str_replace(['″', '”', '“', "''"], '"', $text);
        $text = str_replace(['º', '˚'], '°', $text);
        $text = mb_strtoupper($text, 'UTF-8');
        // Virgule décimale (usage français) : seulement si aucun point n'est présent et si la virgule sépare deux chiffres.
        if (!str_contains($text, '.')) {
            $text = preg_replace('/(?<=\d),(?=\d)/', '.', $text) ?? $text;
        }

        preg_match_all('/(?<![A-Z])([NSEWO])(?![A-Z])|(-?\d+(?:\.\d+)?)/u', $text, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            throw new InvalidArgumentException('Coordonnées illisibles : aucun nombre trouvé.');
        }

        $hasLetters = false;
        foreach ($matches as $m) {
            if (($m[1] ?? '') !== '') {
                $hasLetters = true;
                break;
            }
        }

        $groups = [];
        if (!$hasLetters) {
            $numbers = array_map(static fn (array $m): float => (float) $m[2], $matches);
            $count = count($numbers);
            if (!in_array($count, [2, 4, 6], true)) {
                throw new InvalidArgumentException('Indiquez une latitude puis une longitude (2, 4 ou 6 nombres).');
            }
            $half = intdiv($count, 2);
            $groups[] = ['prefix' => null, 'numbers' => array_slice($numbers, 0, $half), 'suffix' => null];
            $groups[] = ['prefix' => null, 'numbers' => array_slice($numbers, $half), 'suffix' => null];
        } else {
            $current = ['prefix' => null, 'numbers' => [], 'suffix' => null];
            foreach ($matches as $m) {
                $letter = $m[1] ?? '';
                if ($letter !== '') {
                    if ($current['numbers'] === []) {
                        if ($current['prefix'] !== null) {
                            throw new InvalidArgumentException('Coordonnées illisibles : lettres consécutives.');
                        }
                        $current['prefix'] = $letter;
                    } elseif ($current['prefix'] !== null) {
                        // "N 48.85 E 2.35" : la lettre ouvre la coordonnée suivante
                        $groups[] = $current;
                        $current = ['prefix' => $letter, 'numbers' => [], 'suffix' => null];
                    } else {
                        // "48.85N 2.35E" : la lettre clôt la coordonnée courante
                        $current['suffix'] = $letter;
                        $groups[] = $current;
                        $current = ['prefix' => null, 'numbers' => [], 'suffix' => null];
                    }
                    continue;
                }
                $current['numbers'][] = (float) $m[2];
            }
            if ($current['numbers'] !== []) {
                $groups[] = $current;
            } elseif ($current['prefix'] !== null) {
                throw new InvalidArgumentException('Coordonnées illisibles : lettre sans valeur.');
            }
        }

        if (count($groups) !== 2) {
            throw new InvalidArgumentException('Indiquez exactement une latitude et une longitude.');
        }

        $parsed = array_map([self::class, 'groupValue'], $groups);
        $axes = array_map(static fn (array $g): ?string => $g['axis'], $parsed);

        if ($axes[0] === 'lon' || $axes[1] === 'lat') {
            if ($axes[0] === $axes[1]) {
                throw new InvalidArgumentException('Les deux valeurs désignent le même axe (N/S ou E/W).');
            }
            $parsed = [$parsed[1], $parsed[0]];
        } elseif ($axes[0] === 'lat' && $axes[1] === 'lat') {
            throw new InvalidArgumentException('Les deux valeurs désignent une latitude.');
        }

        return new self($parsed[0]['value'], $parsed[1]['value']);
    }

    /**
     * @param array{prefix: ?string, numbers: list<float>, suffix: ?string} $group
     * @return array{value: float, axis: ?string}
     */
    private static function groupValue(array $group): array
    {
        $numbers = $group['numbers'];
        $count = count($numbers);
        if ($count < 1 || $count > 3) {
            throw new InvalidArgumentException('Chaque coordonnée comporte au plus trois nombres (degrés, minutes, secondes).');
        }
        $letter = $group['suffix'] ?? $group['prefix'];
        if ($group['suffix'] !== null && $group['prefix'] !== null && $group['suffix'] !== $group['prefix']) {
            throw new InvalidArgumentException('Lettres contradictoires pour une même coordonnée.');
        }
        $negative = $numbers[0] < 0;
        $degrees = abs($numbers[0]);
        $minutes = $numbers[1] ?? 0.0;
        $seconds = $numbers[2] ?? 0.0;
        if ($minutes < 0 || $minutes >= 60 || $seconds < 0 || $seconds >= 60) {
            throw new InvalidArgumentException('Les minutes et secondes doivent être comprises entre 0 et 60.');
        }
        if ($count > 1 && $degrees !== floor($degrees)) {
            throw new InvalidArgumentException('Les degrés doivent être entiers lorsque des minutes sont indiquées.');
        }
        if ($count > 2 && $minutes !== floor($minutes)) {
            throw new InvalidArgumentException('Les minutes doivent être entières lorsque des secondes sont indiquées.');
        }
        $value = $degrees + $minutes / 60 + $seconds / 3600;
        $axis = null;
        if ($letter !== null) {
            $axis = in_array($letter, ['N', 'S'], true) ? 'lat' : 'lon';
            if ($negative) {
                throw new InvalidArgumentException('N’indiquez pas à la fois un signe négatif et une lettre d’hémisphère.');
            }
            $negative = in_array($letter, ['S', 'W', 'O'], true);
        }
        return ['value' => $negative ? -$value : $value, 'axis' => $axis];
    }

    // ----- Formats -----

    /** "48.856600, 2.352200" */
    public function decimal(int $precision = 6, string $separator = ', '): string
    {
        return number_format($this->latitude, $precision, '.', '') . $separator . number_format($this->longitude, $precision, '.', '');
    }

    /** "48°51′23.8″N 2°21′03.0″E" */
    public function dms(): string
    {
        return self::dmsComponent($this->latitude, 'N', 'S') . ' ' . self::dmsComponent($this->longitude, 'E', 'W');
    }

    public function geoUri(): string
    {
        return 'geo:' . $this->decimal(6, ',');
    }

    public function openStreetMapUrl(int $zoom = 15): string
    {
        return sprintf('https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=%d/%s/%s', $this->fmt($this->latitude), $this->fmt($this->longitude), $zoom, $this->fmt($this->latitude), $this->fmt($this->longitude));
    }

    public function googleMapsUrl(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($this->decimal(6, ','));
    }

    /** @return array{latitude: float, longitude: float, decimal: string, dms: string} */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude, 'decimal' => $this->decimal(), 'dms' => $this->dms()];
    }

    // ----- Distances -----

    /** Distance orthodromique en kilomètres (formule de haversine). */
    public function distanceTo(self $other): float
    {
        return self::haversineKm($this->latitude, $this->longitude, $other->latitude, $other->longitude);
    }

    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * Boîte englobante approximative pour une recherche à $radiusKm : [latMin, latMax, lonMin, lonMax].
     * La longitude n'est pas bornée près des pôles (cos ≈ 0) : la boîte couvre alors toute la longitude.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function boundingBox(float $radiusKm): array
    {
        $dLat = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        $cos = cos(deg2rad($this->latitude));
        $dLon = $cos < 1e-6 ? 180.0 : rad2deg($radiusKm / (self::EARTH_RADIUS_KM * $cos));
        return [
            max(-90.0, $this->latitude - $dLat),
            min(90.0, $this->latitude + $dLat),
            max(-180.0, $this->longitude - $dLon),
            min(180.0, $this->longitude + $dLon),
        ];
    }

    public static function formatDistance(float $km): string
    {
        if ($km < 1) {
            return number_format(round($km * 1000), 0, ',', ' ') . ' m';
        }
        return number_format($km, $km < 10 ? 2 : ($km < 100 ? 1 : 0), ',', ' ') . ' km';
    }

    private static function dmsComponent(float $value, string $positive, string $negative): string
    {
        $hemisphere = $value < 0 ? $negative : $positive;
        $abs = abs($value);
        $degrees = (int) floor($abs);
        $minutesFloat = ($abs - $degrees) * 60;
        $minutes = (int) floor($minutesFloat);
        $seconds = round(($minutesFloat - $minutes) * 60, 1);
        if ($seconds >= 60) {
            $seconds -= 60;
            $minutes++;
        }
        if ($minutes >= 60) {
            $minutes -= 60;
            $degrees++;
        }
        return sprintf('%d°%02d′%04.1f″%s', $degrees, $minutes, $seconds, $hemisphere);
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
