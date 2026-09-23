<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Kernel\Autoloader;
use Atelier\Kernel\Config;
use Atelier\Logging\Logger;
use Atelier\Modules\ModuleManager;
use Atelier\Modules\ModuleSynchronizer;
use Atelier\Persistence\Database;
use Atelier\Persistence\Migrator;
use Atelier\Support\Files;
use Atelier\Support\Json;
use Atelier\Testing\TestCase;

/**
 * Isolation d'un module dont l'installation échoue : sa migration défectueuse ne doit pas
 * empêcher les autres modules de s'installer ni mettre l'application en erreur
 * (cahier des charges §6.2.1, critère de recette §35).
 */
final class ModuleFailureTest extends TestCase
{
    private string $root;

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/atelier-failure-' . bin2hex(random_bytes(4));
        Files::ensureDirectory($this->root . '/var/cache');
        Files::ensureDirectory($this->root . '/var/config');
        $this->module('sain', 'CREATE TABLE sain_item (id INTEGER PRIMARY KEY)');
        $this->module('casse', 'ALTER TABLE table_absente ADD COLUMN x TEXT');
    }

    public function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function module(string $id, string $sql): void
    {
        $directory = $this->root . '/modules/' . $id;
        Files::ensureDirectory($directory . '/src');
        Files::ensureDirectory($directory . '/migrations');
        $class = ucfirst($id) . 'Module';
        file_put_contents($directory . '/src/' . $class . '.php', "<?php\nnamespace Atelier\\Modules\\" . ucfirst($id) . ";\nfinal class $class extends \\Atelier\\Modules\\AbstractModule { public function routes(\\Atelier\\Modules\\RouteCollection \$r): void {} }");
        Json::writeFile($directory . '/manifest.json', [
            'id' => $id,
            'name' => ucfirst($id),
            'version' => '1.0.0',
            'namespace' => 'Atelier\\Modules\\' . ucfirst($id),
            'entry' => $class,
        ]);
        file_put_contents($directory . '/migrations/001_init.php', "<?php\nreturn static function (\\Atelier\\Persistence\\Database \$db): void {\n    \$db->execute(" . var_export($sql, true) . ");\n};\n");
    }

    private function manager(Database $db): ModuleManager
    {
        $config = Config::fromArray(['paths' => ['cache' => $this->root . '/var/cache']], $this->root);
        return new ModuleManager($this->root . '/modules', $this->root . '/var/config/modules.json', new Autoloader(), $config, new Logger($this->root . '/var/logs', 'error'));
    }

    public function testBrokenMigrationIsolatesOnlyItsModule(): void
    {
        $db = $this->database();
        $manager = $this->manager($db);
        $synchronizer = new ModuleSynchronizer($db, $manager, dirname(__DIR__, 2) . '/src/Persistence/migrations/core', $this->root . '/var/cache');

        $log = $synchronizer->syncAll();

        // Le module sain est installé et utilisable.
        $this->assertTrue($db->tableExists('sain_item'));
        $this->assertTrue($manager->get('sain')->isUsable());
        $this->assertSame(1, (new Migrator($db))->currentVersion('sain'));

        // Le module défectueux est isolé, avec un motif exploitable.
        $broken = $manager->get('casse');
        $this->assertFalse($broken->isUsable());
        $this->assertSame('error', $broken->state());
        $this->assertStringContains('Installation impossible', $broken->errors[0]);
        $this->assertSame(0, (new Migrator($db))->currentVersion('casse'));
        $this->assertTrue(in_array('ÉCHEC du module casse : ' . explode(' : ', $broken->errors[0], 2)[1], $log, true) || str_contains(implode("\n", $log), 'ÉCHEC du module casse'));

        // L'échec survit à une nouvelle découverte : le module reste isolé sans tout rejouer.
        $again = $this->manager($db);
        $this->assertFalse($again->get('casse')->isUsable());
        $this->assertTrue($again->get('sain')->isUsable());

        // Ouvrir le module défectueux donne une indisponibilité claire, pas une erreur serveur.
        $exception = $this->assertThrows(\Atelier\Error\ModuleUnavailableException::class, static fn () => $again->requireUsable('casse'));
        $this->assertStringContains('Installation impossible', $exception->getMessage());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
