<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Shared\FileCrypto;
use Atelier\Testing\TestCase;

/**
 * Module Fichiers joints : téléversement, stockage chiffré, téléchargement contrôlé par les droits.
 */
final class AttachmentsModuleTest extends TestCase
{
    private Application $app;
    private int $alice;
    private int $bob;
    private string $tmp;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->alice = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->bob = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        foreach ([$this->alice, $this->bob] as $userId) {
            foreach (['open', 'read', 'create', 'update', 'delete'] as $permission) {
                $this->app->acl->setRule('user', $userId, AclService::module('attachments'), $permission, 'allow');
            }
        }
        $this->app->acl->clearCache();
        $this->tmp = $this->app->config->path('tmp');
        @mkdir($this->tmp, 0777, true);
    }

    private function login(string $username): void
    {
        $this->app->auth->logout();
        $this->app->auth->login($username, 'Mot-de-passe-solide', '127.0.0.1');
    }

    private function headers(): array
    {
        return ['x-atelier-request' => 'json', 'x-csrf-token' => $this->app->csrf->token()];
    }

    private function upload(string $name, string $content, array $post = []): Request
    {
        $file = $this->tmp . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($file, $content);
        $files = ['files' => ['name' => [$name], 'type' => ['application/octet-stream'], 'tmp_name' => [$file], 'error' => [UPLOAD_ERR_OK], 'size' => [strlen($content)]]];
        return new Request('POST', '/m/attachments/upload', [], $post, $files, [], $this->headers(), ['REMOTE_ADDR' => '127.0.0.1']);
    }

    public function testUploadEncryptsAndOwnerDownloadsDecrypted(): void
    {
        $this->login('alice');
        $content = "Rapport confidentiel\n" . str_repeat('x', 500);
        $response = $this->app->handle($this->upload('rapport.txt', $content, ['description' => 'Version 1']));
        $this->assertSame(200, $response->status(), $response->body());
        $data = $response->decodedJson()['data'];
        $this->assertCount(1, $data['files']);
        $this->assertSame([], $data['errors']);
        $id = $data['files'][0]['id'];

        $record = $this->app->shared->attachments->find($id);
        $this->assertSame(FileCrypto::CIPHER, $record['cipher']);
        $this->assertSame($this->alice, (int) $record['uploaded_by']);
        $this->assertSame('Version 1', $record['description']);
        $path = $this->app->shared->attachments->directory() . '/' . $record['storage_path'];
        $this->assertTrue(FileCrypto::isEncryptedFile($path));
        $this->assertFalse(str_contains((string) file_get_contents($path), 'Rapport confidentiel'));

        // Téléchargement par le module (route brute) et par le noyau (/files/{id})
        $response = $this->app->handle(Request::create('GET', '/m/attachments/download/' . $id, [], [], $this->headers()));
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame($content, $response->streamToString());
        $this->assertStringContains('attachment; filename="rapport.txt"', (string) $response->header('Content-Disposition'));
        $this->assertSame('private, no-store', $response->header('Cache-Control'));
        $this->assertSame(1, (int) $this->app->shared->attachments->find($id)['downloads']);

        $response = $this->app->handle(Request::create('GET', '/files/' . $id, [], [], $this->headers()));
        $this->assertSame(200, $response->status());
        $this->assertSame($content, $response->streamToString());

        // Liste et fiche
        $content = $this->app->handle(Request::create('GET', '/m/attachments/list', [], [], $this->headers()))->decodedJson()['data']['content'];
        $this->assertStringContains('rapport.txt', $content);
        $this->assertStringContains('module-attachments', $content);
        $show = $this->app->handle(Request::create('GET', '/m/attachments/show/' . $id, [], [], $this->headers()))->decodedJson()['data']['content'];
        $this->assertStringContains('AES-256-GCM', $show);
        $this->assertStringContains($record['sha256'], $show);

        // Vérification d'intégrité et renommage
        $response = $this->app->handle(Request::create('POST', '/m/attachments/verify', [], ['id' => $id], $this->headers()));
        $this->assertTrue($response->decodedJson()['data']['ok']);
        $response = $this->app->handle(Request::create('POST', '/m/attachments/rename', [], ['id' => $id, 'name' => 'rapport.pdf'], $this->headers()));
        $this->assertSame(422, $response->status(), 'l’extension ne change pas');
        $response = $this->app->handle(Request::create('POST', '/m/attachments/rename', [], ['id' => $id, 'name' => 'rapport-final.txt', 'description' => ''], $this->headers()));
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame('rapport-final.txt', $this->app->shared->attachments->find($id)['original_name']);
    }

    public function testRefusedTypesAreReportedPerFile(): void
    {
        $this->login('alice');
        $response = $this->app->handle($this->upload('script.php', '<?php echo 1;'));
        $this->assertSame(422, $response->status());
        $this->assertStringContains('refusé', $response->decodedJson()['error']['fields']['files']);
        $this->assertSame(0, $this->app->shared->attachments->stats($this->alice)['count']);
    }

    public function testAccessRulesBetweenUsers(): void
    {
        $this->login('alice');
        $id = $this->app->handle($this->upload('prive.txt', 'secret'))->decodedJson()['data']['files'][0]['id'];

        // Bob n'est ni auteur ni assistant : refus au téléchargement, à la fiche et à la gestion
        $this->login('bob');
        $this->assertSame(403, $this->app->handle(Request::create('GET', '/m/attachments/download/' . $id, [], [], $this->headers()))->status());
        $this->assertSame(403, $this->app->handle(Request::create('GET', '/files/' . $id, [], [], $this->headers()))->status());
        $this->assertSame(403, $this->app->handle(Request::create('GET', '/m/attachments/show/' . $id, [], [], $this->headers()))->status());
        $this->assertSame(403, $this->app->handle(Request::create('POST', '/m/attachments/delete', [], ['id' => $id], $this->headers()))->status());
        $this->assertSame(403, $this->app->handle(Request::create('GET', '/m/attachments/all', [], [], $this->headers()))->status(), 'la vue « tous » exige la permission assist');
        $this->assertFalse(str_contains($this->app->handle(Request::create('GET', '/m/attachments/list', [], [], $this->headers()))->decodedJson()['data']['content'], 'prive.txt'));

        // Rattaché à une information d'un jeu partagé lisible par Bob : consultable, pas gérable
        $this->login('alice');
        $this->app->acl->setRule('user', $this->alice, AclService::module('users') . '/data/account', 'read', 'allow');
        $this->app->acl->setRule('user', $this->bob, AclService::module('users') . '/data/account', 'read', 'allow');
        $this->app->acl->clearCache();
        $infoId = $this->app->shared->registry->register('users.account', (string) $this->alice, 'Alice');
        $this->assertSame(200, $this->app->handle(Request::create('POST', '/m/attachments/link', [], ['id' => $id, 'info_id' => $infoId], $this->headers()))->status());
        $this->login('bob');
        $response = $this->app->handle(Request::create('GET', '/m/attachments/download/' . $id, [], [], $this->headers()));
        $this->assertSame(200, $response->status());
        $this->assertSame('secret', $response->streamToString());
        $this->assertSame(403, $this->app->handle(Request::create('POST', '/m/attachments/delete', [], ['id' => $id], $this->headers()))->status());

        // Avec la permission assist, Bob gère tout
        $this->app->acl->setRule('user', $this->bob, AclService::module('attachments'), 'assist', 'allow');
        $this->app->acl->clearCache();
        $this->assertStringContains('prive.txt', $this->app->handle(Request::create('GET', '/m/attachments/all', [], [], $this->headers()))->decodedJson()['data']['content']);
        $this->assertSame(200, $this->app->handle(Request::create('POST', '/m/attachments/delete', [], ['id' => $id], $this->headers()))->status());
        $this->assertSame(404, $this->app->handle(Request::create('GET', '/m/attachments/download/' . $id, [], [], $this->headers()))->status(), 'un fichier en corbeille n’est plus téléchargeable');
        $this->assertSame(200, $this->app->handle(Request::create('POST', '/m/attachments/restore', [], ['id' => $id], $this->headers()))->status());
        $this->assertSame(200, $this->app->handle(Request::create('POST', '/m/attachments/delete', [], ['id' => $id], $this->headers()))->status());
        $path = $this->app->shared->attachments->directory() . '/' . $this->app->shared->attachments->find($id, true)['storage_path'];
        $this->assertSame(200, $this->app->handle(Request::create('POST', '/m/attachments/purge', [], ['id' => $id], $this->headers()))->status());
        $this->assertFalse(is_file($path));
    }

    public function testUploadLinkedToGeoPointAppearsOnPointSheet(): void
    {
        $this->login('alice');
        $this->app->acl->setRule('user', $this->alice, AclService::module('geo'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $pointId = (int) $this->app->handle(Request::create('POST', '/m/geo/save', [], ['name' => 'Chantier', 'coordinates' => '47.2, -1.55'], $this->headers()))->decodedJson()['data']['id'];

        $form = $this->app->handle(Request::create('GET', '/m/attachments/upload', ['point' => (string) $pointId], [], $this->headers()))->decodedJson()['data']['content'];
        $this->assertStringContains('Chantier', $form, 'la cible est présélectionnée');
        $infoId = $this->app->shared->registry->find('geo.point', (string) $pointId)['id'];

        $response = $this->app->handle($this->upload('plan.txt', 'plan du chantier', ['info_id' => $infoId]));
        $this->assertSame(200, $response->status(), $response->body());
        $sheet = $this->app->handle(Request::create('GET', '/m/geo/show/' . $pointId, [], [], $this->headers()))->decodedJson()['data']['content'];
        $this->assertStringContains('plan.txt', $sheet);
        $this->assertStringContains('/files/' . $response->decodedJson()['data']['files'][0]['id'], $sheet);
    }
}
