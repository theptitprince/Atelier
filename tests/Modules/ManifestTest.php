<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Modules\Manifest;
use Atelier\Modules\ManifestException;
use Atelier\Testing\TestCase;

final class ManifestTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/atelier-manifest-' . bin2hex(random_bytes(3)) . '/sample';
        mkdir($this->dir . '/src', 0777, true);
        file_put_contents($this->dir . '/src/SampleModule.php', '<?php');
    }

    private function valid(): array
    {
        return [
            'id' => 'sample', 'name' => 'Exemple', 'version' => '1.0.0', 'namespace' => 'Atelier\\Modules\\Sample',
            'entry' => 'SampleModule', 'defaultRoute' => 'index',
            'navigation' => [
                ['id' => 'list', 'label' => 'Liste', 'route' => 'list', 'order' => 1],
                ['id' => 'sub', 'label' => 'Sous', 'route' => 'list/sub', 'parent' => 'list'],
            ],
            'datasets' => [['code' => 'sample.item', 'name' => 'Items', 'visibility' => 'shared', 'tables' => ['sample_item']]],
        ];
    }

    public function testValidManifestIsNormalized(): void
    {
        $manifest = Manifest::fromArray($this->valid(), $this->dir);
        $this->assertSame('sample', $manifest->id());
        $this->assertSame('Atelier\\Modules\\Sample\\SampleModule', $manifest->entryClass());
        $this->assertSame('screen/list', $manifest->navigation()[0]['resource']);
        $this->assertSame('open', $manifest->navigation()[0]['permission']);
        $this->assertSame(['read'], $manifest->datasets()[0]['operations']);
        $this->assertSame('tools', $manifest->get('group'));
    }

    public function testIdMustMatchDirectory(): void
    {
        $data = $this->valid();
        $data['id'] = 'other';
        $e = $this->assertThrows(ManifestException::class, fn () => Manifest::fromArray($data, $this->dir));
        $this->assertStringContains('répertoire', implode(' ', $e->errors));
    }

    public function testMissingEntryFileIsReported(): void
    {
        $data = $this->valid();
        $data['entry'] = 'Missing';
        $this->assertThrows(ManifestException::class, fn () => Manifest::fromArray($data, $this->dir), 'introuvable');
    }

    public function testDatasetVisibilityIsMandatoryAndPrefixed(): void
    {
        $data = $this->valid();
        $data['datasets'] = [['code' => 'sample.x', 'name' => 'X']];
        $this->assertThrows(ManifestException::class, fn () => Manifest::fromArray($data, $this->dir), 'visibility');
        $data['datasets'] = [['code' => 'other.x', 'name' => 'X', 'visibility' => 'shared']];
        $this->assertThrows(ManifestException::class, fn () => Manifest::fromArray($data, $this->dir), 'préfixé');
    }

    public function testDuplicateNavigationIdAndUnknownParentAreErrors(): void
    {
        $data = $this->valid();
        $data['navigation'][] = ['id' => 'list', 'label' => 'Doublon', 'route' => 'x'];
        $this->assertThrows(ManifestException::class, fn () => Manifest::fromArray($data, $this->dir), 'dupliquée');
        $data = $this->valid();
        $data['navigation'][1]['parent'] = 'nope';
        $this->assertThrows(ManifestException::class, fn () => Manifest::fromArray($data, $this->dir), 'inconnu');
    }

    public function testInvalidRouteAndAssetPathsAreRejected(): void
    {
        $data = $this->valid();
        $data['navigation'][0]['route'] = '../etc';
        $this->assertThrows(ManifestException::class, fn () => Manifest::fromArray($data, $this->dir), 'route invalide');
        $data = $this->valid();
        $data['assets'] = ['css' => ['/absolute.css']];
        $this->assertThrows(ManifestException::class, fn () => Manifest::fromArray($data, $this->dir), 'relatif');
    }

    public function testNormalizeRoute(): void
    {
        $this->assertSame('index', Manifest::normalizeRoute(''));
        $this->assertSame('a/b', Manifest::normalizeRoute('/a/b/'));
        $this->assertNull(Manifest::normalizeRoute('a/../b'));
        $this->assertNull(Manifest::normalizeRoute('a b'));
    }
}
