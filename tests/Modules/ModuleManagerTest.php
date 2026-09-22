<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Kernel\Autoloader;
use Atelier\Kernel\Config;
use Atelier\Logging\Logger;
use Atelier\Modules\ModuleManager;
use Atelier\Support\Json;
use Atelier\Testing\TestCase;

/**
 * Découverte : un manifeste invalide n'empêche que son module ; les surcharges s'appliquent ;
 * l'ordre suit groupe > ordre > libellé.
 */
final class ModuleManagerTest extends TestCase
{
    private string $root;

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/atelier-mm-' . bin2hex(random_bytes(3));
        mkdir($this->root . '/modules', 0777, true);
        mkdir($this->root . '/var/config', 0777, true);
        $this->module('alpha', ['name' => 'Alpha', 'order' => 20, 'group' => 'tools']);
        $this->module('beta', ['name' => 'Beta', 'order' => 10, 'group' => 'tools', 'navigation' => [['id' => 'l', 'label' => 'Liste', 'route' => 'list']]]);
        mkdir($this->root . '/modules/broken');
        file_put_contents($this->root . '/modules/broken/manifest.json', '{"id":"broken","name":"Cassé"}');
        mkdir($this->root . '/modules/_ignored');
    }

    private function module(string $id, array $extra): void
    {
        $dir = $this->root . '/modules/' . $id;
        mkdir($dir . '/src', 0777, true);
        $class = ucfirst($id) . 'Module';
        file_put_contents($dir . '/src/' . $class . '.php', "<?php\nnamespace Atelier\\Modules\\" . ucfirst($id) . ";\nfinal class $class extends \\Atelier\\Modules\\AbstractModule { public function routes(\\Atelier\\Modules\\RouteCollection \$r): void {} }");
        Json::writeFile($dir . '/manifest.json', ['id' => $id, 'version' => '1.0.0', 'namespace' => 'Atelier\\Modules\\' . ucfirst($id), 'entry' => $class] + $extra);
    }

    private function manager(): ModuleManager
    {
        $config = Config::fromArray(['navigation' => ['groups' => ['tools' => ['label' => 'Outils', 'order' => 20]]]], $this->root);
        $logger = new Logger($this->root . '/var/logs', 'error');
        return new ModuleManager($this->root . '/modules', $this->root . '/var/config/modules.json', new Autoloader(), $config, $logger);
    }

    public function testDiscoveryIsolatesInvalidManifests(): void
    {
        $manager = $this->manager();
        $all = $manager->all();
        $this->assertCount(3, $all);
        $this->assertTrue($all['alpha']->isValid());
        $this->assertFalse($all['broken']->isValid());
        $this->assertSame('error', $all['broken']->state());
        $this->assertTrue(count($all['broken']->errors) > 0);
        $this->assertFalse(isset($all['_ignored']));
    }

    public function testSortingAndOverrides(): void
    {
        $manager = $this->manager();
        $ids = array_map(static fn ($d) => $d->id, $manager->sorted());
        $this->assertSame(['beta', 'alpha', 'broken'], $ids);

        $manager->setModuleOverride('alpha', 'order', 1);
        $manager->setModuleOverride('beta', 'status', 'maintenance');
        $manager->setNavigationOrder('beta', 'l', 5);
        $manager->setGroupOverride('tools', 'Boîte à outils', 3);

        $fresh = $this->manager();
        $this->assertSame(['alpha', 'beta', 'broken'], array_map(static fn ($d) => $d->id, $fresh->sorted()));
        $this->assertSame('maintenance', $fresh->get('beta')->state());
        $this->assertFalse($fresh->get('beta')->isUsable());
        $this->assertSame(5, $fresh->get('beta')->navigation()[0]['order']);
        $this->assertSame('Boîte à outils', $fresh->groups()['tools']['label']);
        $stored = Json::readFile($this->root . '/var/config/modules.json');
        $this->assertSame(1, $stored['modules']['alpha']['order']);
        $this->assertThrows(\InvalidArgumentException::class, fn () => $manager->setModuleOverride('alpha', 'status', 'bizarre'));
    }

    public function testNavigationTreeWithoutAclLocksEverything(): void
    {
        $tree = $this->manager()->navigationTree(null, null);
        $this->assertCount(1, $tree);
        foreach ($tree[0]['modules'] as $module) {
            $this->assertNotSame('active', $module['state']);
            $this->assertFalse($module['accessible']);
        }
    }

    public function testInstanceIsCreatedFromManifest(): void
    {
        $manager = $this->manager();
        $manager->all();
        $autoloader = new \ReflectionProperty(ModuleManager::class, 'autoloader');
        /** @var Autoloader $loader */
        $loader = $autoloader->getValue($manager);
        $loader->register();
        $module = $manager->instance('alpha');
        $this->assertSame('alpha', $module->manifest()->id());
        $this->assertThrows(\Atelier\Error\ModuleUnavailableException::class, fn () => $manager->requireUsable('broken'));
        $this->assertThrows(\Atelier\Error\NotFoundException::class, fn () => $manager->requireUsable('nope'));
    }
}
