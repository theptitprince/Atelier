<?php

declare(strict_types=1);

namespace Atelier\Tests\Kernel;

use Atelier\Http\Request;
use Atelier\Kernel\ModuleScaffolder;
use Atelier\Modules\TrashProviderInterface;
use Atelier\Security\Acl\AclService;
use Atelier\Testing\TestCase;

/**
 * Le squelette produit par `module:create` doit être un module complet du point de vue des
 * services communs : inscrit au registre, taguable, ouvert depuis les vues transversales, et
 * dont la corbeille est connue du registre. Un nouveau module hérite ainsi d'emblée du lieur
 * universel qu'est le tag et de la corbeille globale.
 */
final class ModuleScaffolderTest extends TestCase
{
    private string $modules;

    public function setUp(): void
    {
        $this->modules = sys_get_temp_dir() . '/atelier-scaffold-' . bin2hex(random_bytes(4));
        mkdir($this->modules, 0777, true);
    }

    public function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->modules, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->modules);
    }

    public function testGeneratedModuleIsTaggableAndItsTrashIsKnownToTheRegistry(): void
    {
        $files = (new ModuleScaffolder($this->modules))->create('essai', ['name' => 'Essai', 'shared' => true]);
        foreach ($files as $file) {
            if (str_ends_with($file, '.php')) {
                $path = $this->modules . '/' . substr($file, strlen('modules/'));
                $this->assertSame(0, $this->lint($path), 'le fichier généré compile : ' . $file);
            }
        }
        $manifest = json_decode((string) file_get_contents($this->modules . '/essai/manifest.json'), true);
        $this->assertSame('edit/{key}', $manifest['datasets'][0]['openRoute'], 'ouvrable depuis les vues transversales');

        $_SESSION = [];
        $app = $this->application($this->modules);
        $app->synchronizer()->syncAll();
        $userId = $app->users->create(['username' => 'moi', 'password_hash' => $app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $app->acl->setRule('user', $userId, AclService::ROOT, 'admin', 'allow');
        $app->acl->clearCache();
        $app->auth->login('moi', 'Mot-de-passe-solide', '127.0.0.1');
        $headers = fn (): array => ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $app->csrf->token()];

        // Enregistrement : inscription au registre et tags.
        $saved = $app->handle(Request::create('POST', '/m/essai/save', [], ['title' => 'Premier élément', 'content' => 'x', 'tags' => 'essai, généré'], $headers()));
        $this->assertSame(200, $saved->status(), $saved->body());
        $id = (string) $saved->decodedJson()['data']['id'];
        $info = $app->shared->registry->find('essai.item', $id);
        $this->assertNotNull($info, 'l’élément est inscrit au registre commun');
        $this->assertSame(['essai', 'généré'], array_map(static fn (array $t): string => (string) $t['name'], $app->shared->tags->tagsOf((string) $info['id'])));
        $edit = $app->handle(Request::create('GET', '/m/essai/edit/' . $id, [], [], $headers()));
        $this->assertStringContains('data-tags-input', $edit->body(), 'le formulaire propose les tags');

        // Corbeille : le registre le sait, puis restauration.
        $this->assertSame(200, $app->handle(Request::create('POST', '/m/essai/delete', [], ['id' => $id], $headers()))->status());
        $this->assertTrue($app->shared->registry->isTrashed('essai.item', $id));
        $module = $app->modules->instance('essai');
        $this->assertTrue($module instanceof TrashProviderInterface);
        $module->restoreTrashItem($id);
        $this->assertFalse($app->shared->registry->isTrashed('essai.item', $id));

        // Purge définitive : l'entrée du registre disparaît.
        $app->handle(Request::create('POST', '/m/essai/delete', [], ['id' => $id], $headers()));
        $module->purgeTrashItem($id);
        $this->assertNull($app->shared->registry->find('essai.item', $id));
    }

    private function lint(string $path): int
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
        return $code;
    }
}
