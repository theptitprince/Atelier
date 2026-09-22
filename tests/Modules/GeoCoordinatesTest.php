<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Modules\Geo\Coordinates;
use Atelier\Testing\TestCase;

final class GeoCoordinatesTest extends TestCase
{
    public function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/modules/geo/src/Coordinates.php';
    }

    public function testDecimalFormats(): void
    {
        $cases = [
            '48.8566, 2.3522',
            '48.8566 2.3522',
            '48.8566;2.3522',
            '48,8566, 2,3522',
            '48,8566 2,3522',
            '48.8566N 2.3522E',
            'N48.8566 E2.3522',
            '2.3522E 48.8566N',
            'lat 48.8566 lon 2.3522',
        ];
        foreach ($cases as $input) {
            $c = Coordinates::parse($input);
            $this->assertSame('48.856600, 2.352200', $c->decimal(), 'saisie : ' . $input);
        }
        $c = Coordinates::parse('-33.8688, 151.2093');
        $this->assertSame(-33.8688, $c->latitude);
        $this->assertSame(151.2093, $c->longitude);
        $c = Coordinates::parse('33.8688S 151.2093E');
        $this->assertSame(-33.8688, $c->latitude);
        $c = Coordinates::parse('48.0375 N 4.7381 O');
        $this->assertSame(-4.7381, $c->longitude, 'O = ouest');
    }

    public function testDmsAndDmFormats(): void
    {
        $expected = Coordinates::parse('48.856667, 2.350833');
        foreach (['48°51\'24"N 2°21\'03"E', '48° 51′ 24″ N, 2° 21′ 3″ E', '48 51 24 N 2 21 3 E', '48°51\'24" 2°21\'03"', '48 51 24 2 21 3'] as $input) {
            $c = Coordinates::parse($input);
            $this->assertTrue(abs($c->latitude - $expected->latitude) < 1e-6, $input);
            $this->assertTrue(abs($c->longitude - $expected->longitude) < 1e-6, $input);
        }
        $c = Coordinates::parse('N 48°51.400\' E 2°21.050\'');
        $this->assertTrue(abs($c->latitude - 48.856667) < 1e-5);
        $this->assertTrue(abs($c->longitude - 2.350833) < 1e-5);
        $c = Coordinates::parse('48°02\'15"N 4°44\'17"W');
        $this->assertTrue($c->longitude < 0);
        $this->assertSame('48°02′15.0″N 4°44′17.0″W', $c->dms());
        $this->assertSame('48°51′23.8″N 2°21′07.9″E', Coordinates::parse('48.8566, 2.3522')->dms());
    }

    public function testInvalidInputs(): void
    {
        foreach (['', 'Paris', '48.8566', '91, 2', '48, 181', '48°61\'N 2°E', '48 51 24 2 21', 'N 48 N 2', '48N 2N', '-48S 2E', '48.5°30\'N 2°E'] as $input) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => Coordinates::parse($input), null);
            $this->assertNull(Coordinates::tryParse($input), 'devrait être invalide : ' . $input);
        }
    }

    public function testDistancesAndBoundingBox(): void
    {
        $paris = new Coordinates(48.8566, 2.3522);
        $lyon = new Coordinates(45.7640, 4.8357);
        $distance = $paris->distanceTo($lyon);
        $this->assertTrue($distance > 391 && $distance < 394, 'Paris–Lyon ≈ 392 km, obtenu ' . $distance);
        $this->assertSame('392 km', Coordinates::formatDistance(391.6));
        $this->assertSame('750 m', Coordinates::formatDistance(0.75));
        $this->assertSame('2,50 km', Coordinates::formatDistance(2.5));
        [$latMin, $latMax, $lonMin, $lonMax] = $paris->boundingBox(10);
        $this->assertTrue($latMin < 48.8566 && $latMax > 48.8566 && $lonMin < 2.3522 && $lonMax > 2.3522);
        $this->assertTrue(($latMax - $latMin) > 0.17 && ($latMax - $latMin) < 0.19);
        $this->assertStringContains('mlat=48.8566', $paris->openStreetMapUrl());
        $this->assertSame('geo:48.856600,2.352200', $paris->geoUri());
    }
}
