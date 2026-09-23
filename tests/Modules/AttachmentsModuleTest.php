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
        return $this->uploadMany([$name => $content], $post);
    }

    /** @param array<string, string> $files nom => contenu */
    private function uploadMany(array $files, array $post = []): Request
    {
        $entry = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($files as $name => $content) {
            $file = $this->tmp . '/' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($file, $content);
            $entry['name'][] = $name;
            $entry['type'][] = 'application/octet-stream';
            $entry['tmp_name'][] = $file;
            $entry['error'][] = UPLOAD_ERR_OK;
            $entry['size'][] = strlen($content);
        }
        return new Request('POST', '/m/attachments/upload', [], $post, ['files' => $entry], [], $this->headers(), ['REMOTE_ADDR' => '127.0.0.1']);
    }

    private function post(string $route, array $data): \Atelier\Http\Response
    {
        return $this->app->handle(Request::create('POST', '/m/attachments/' . $route, [], $data, $this->headers()));
    }

    private function view(string $route, array $query = []): string
    {
        $response = $this->app->handle(Request::create('GET', '/m/attachments/' . $route, $query, [], $this->headers()));
        $this->assertSame(200, $response->status(), $response->body());
        return (string) $response->decodedJson()['data']['content'];
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

    public function testDisplayLabelIsAppliedAndSuffixedForMultipleFiles(): void
    {
        $this->login('alice');
        $attachments = $this->app->shared->attachments;

        // Un seul fichier : nom d'affichage appliqué tel quel ; le téléchargement garde le nom d'origine.
        $response = $this->app->handle($this->upload('scan-0001.txt', 'contenu', ['label' => '  Facture   EDF ']));
        $this->assertSame(200, $response->status(), $response->body());
        $id = $response->decodedJson()['data']['files'][0]['id'];
        $record = $attachments->find($id);
        $this->assertSame('Facture EDF', $record['label']);
        $this->assertSame('Facture EDF', $attachments::displayName($record));
        $this->assertSame('scan-0001.txt', $record['original_name']);
        $download = $this->app->handle(Request::create('GET', '/m/attachments/download/' . $id, [], [], $this->headers()));
        $this->assertStringContains('filename="scan-0001.txt"', (string) $download->header('Content-Disposition'));
        $this->assertStringContains('Facture EDF', $this->view('list'));
        $show = $this->view('show/' . $id);
        $this->assertStringContains('Facture EDF', $show);
        $this->assertStringContains('scan-0001.txt', $show, 'le nom d’origine reste visible sur la fiche');

        // Le fichier est inscrit au registre commun sous attachments.file avec son nom d'affichage.
        $info = $this->app->shared->registry->find('attachments.file', $id);
        $this->assertNotNull($info);
        $this->assertSame('Facture EDF', $info['label']);

        // Renommage : label modifié, puis effacé (champ transmis vide), puis conservé (champ absent).
        $this->assertSame(200, $this->post('rename', ['id' => $id, 'name' => 'scan-0001.txt', 'label' => 'Facture EDF 2026'])->status());
        $this->assertSame('Facture EDF 2026', $attachments->find($id)['label']);
        $this->assertSame('Facture EDF 2026', $this->app->shared->registry->find('attachments.file', $id)['label']);
        $this->assertSame(200, $this->post('rename', ['id' => $id, 'name' => 'scan-0001.txt'])->status());
        $this->assertSame('Facture EDF 2026', $attachments->find($id)['label'], 'sans champ label, le nom d’affichage est conservé');
        $this->assertSame(200, $this->post('rename', ['id' => $id, 'name' => 'scan-0001.txt', 'label' => ''])->status());
        $this->assertNull($attachments->find($id)['label']);
        $this->assertSame('scan-0001.txt', $this->app->shared->registry->find('attachments.file', $id)['label']);
        $this->assertSame(422, $this->post('rename', ['id' => $id, 'name' => 'scan-0001.txt', 'label' => str_repeat('x', 201)])->status());

        // Plusieurs fichiers dans un même envoi : nom suffixé d'un numéro d'ordre.
        $response = $this->app->handle($this->uploadMany(['a.txt' => 'aaa', 'b.txt' => 'bbb'], ['label' => 'Rapport']));
        $this->assertSame(200, $response->status(), $response->body());
        $files = $response->decodedJson()['data']['files'];
        $this->assertSame('Rapport (1)', $attachments->find($files[0]['id'])['label']);
        $this->assertSame('Rapport (2)', $attachments->find($files[1]['id'])['label']);
    }

    public function testTagsOnFiles(): void
    {
        $this->login('alice');
        $response = $this->app->handle($this->upload('contrat.txt', 'contrat', ['tags' => 'Urgent, #edf, urgent, ']));
        $this->assertSame(200, $response->status(), $response->body());
        $id = $response->decodedJson()['data']['files'][0]['id'];
        $infoId = $this->app->shared->registry->find('attachments.file', $id)['id'];
        $names = array_map(static fn (array $t): string => $t['name'], $this->app->shared->tags->tagsOf($infoId));
        $this->assertSame(['edf', 'Urgent'], $names, 'tags dédoublonnés, # retiré');

        // Filtre par tag et puces cliquables
        $this->assertStringContains('contrat.txt', $this->view('list', ['tag' => 'edf']));
        $this->assertStringContains('contrat.txt', $this->view('list', ['tag' => 'URGENT']), 'filtre insensible à la casse');
        $this->assertFalse(str_contains($this->view('list', ['tag' => 'inconnu']), 'contrat.txt'));
        $list = $this->view('list');
        $this->assertStringContains('data-route="list?tag=edf"', $list);
        $this->assertStringContains('edf (1)', $list, 'le filtre « Tag » liste les tags portés par des fichiers');
        $this->assertStringContains('data-tags-input', $this->view('show/' . $id));
        $this->assertStringContains('data-tags-input', $this->view('upload'));

        // Remplacement depuis la fiche
        $this->assertSame(200, $this->post('tags-save', ['id' => $id, 'tags' => 'archive'])->status());
        $names = array_map(static fn (array $t): string => $t['name'], $this->app->shared->tags->tagsOf($infoId));
        $this->assertSame(['archive'], $names);
        $this->assertSame(422, $this->post('tags-save', ['id' => $id, 'tags' => str_repeat('x', 61)])->status());
        $this->assertSame(200, $this->post('tags-save', ['id' => $id, 'tags' => ''])->status());
        $this->assertSame([], $this->app->shared->tags->tagsOf($infoId));

        // Un fichier antérieur (non inscrit) est inscrit au premier besoin
        $this->app->shared->registry->unregister('attachments.file', $id);
        $this->assertSame(200, $this->post('tags-save', ['id' => $id, 'tags' => 'retard'])->status());
        $this->assertNotNull($this->app->shared->registry->find('attachments.file', $id));

        // Un fichier ne peut pas être rattaché à un autre fichier ; Bob ne gère pas les tags d'Alice
        $other = $this->app->handle($this->upload('autre.txt', 'autre contenu'))->decodedJson()['data']['files'][0]['id'];
        $fileInfo = $this->app->shared->registry->find('attachments.file', $other)['id'];
        $this->assertSame(422, $this->post('link', ['id' => $id, 'info_id' => $fileInfo])->status());
        $this->login('bob');
        $this->assertSame(403, $this->post('tags-save', ['id' => $id, 'tags' => 'pirate'])->status());
    }

    public function testVirtualFoldersLifecycle(): void
    {
        $this->login('alice');
        $attachments = $this->app->shared->attachments;
        $folders = $this->app->shared->folders;

        // Création d'un dossier et d'un sous-dossier
        $response = $this->post('folder-create', ['name' => 'Factures', 'parent_id' => '']);
        $this->assertSame(200, $response->status(), $response->body());
        $factures = (int) $response->decodedJson()['data']['id'];
        $this->assertSame('list?folder=' . $factures, $response->decodedJson()['directives']['navigate'] ?? null);
        $response = $this->post('folder-create', ['name' => '2026', 'parent_id' => $factures]);
        $this->assertSame(200, $response->status(), $response->body());
        $sub = (int) $response->decodedJson()['data']['id'];
        $this->assertSame('/Factures/2026', $response->decodedJson()['data']['path']);
        $this->assertSame(422, $this->post('folder-create', ['name' => '2026', 'parent_id' => $factures])->status(), 'doublon refusé');
        $this->assertSame(422, $this->post('folder-create', ['name' => 'X', 'parent_id' => 999999])->status());

        // Téléversement direct dans le sous-dossier, avec nom et tags
        $response = $this->app->handle($this->upload('edf.txt', 'edf', ['label' => 'EDF janvier', 'tags' => 'edf', 'folder_id' => $sub]));
        $this->assertSame(200, $response->status(), $response->body());
        $id = $response->decodedJson()['data']['files'][0]['id'];
        $this->assertSame($sub, (int) $attachments->find($id)['folder_id']);
        $this->assertSame(422, $this->app->handle($this->upload('x.txt', 'x', ['folder_id' => 999999]))->status());

        // Listes : dossier courant, non rangés, tout ; fil d'Ariane et arbre
        $inFolder = $this->view('list', ['folder' => (string) $sub]);
        $this->assertStringContains('EDF janvier', $inFolder);
        $this->assertStringContains('Factures', $inFolder);
        $this->assertStringContains('aria-current="page"', $inFolder);
        $this->assertFalse(str_contains($this->view('list', ['folder' => 'root']), 'EDF janvier'));
        $this->assertFalse(str_contains($this->view('list', ['folder' => (string) $factures]), 'EDF janvier'), 'un dossier ne montre pas les fichiers de ses sous-dossiers');
        $all = $this->view('list');
        $this->assertStringContains('EDF janvier', $all);
        $this->assertStringContains('data-route="list?folder=' . $sub . '"', $all);
        $this->assertStringContains('Déplacer vers', $all);
        $this->assertStringContains('option value="' . $sub . '" selected', $this->view('upload', ['folder' => (string) $sub]), 'dossier courant présélectionné au téléversement');
        $this->assertSame(404, $this->app->handle(Request::create('GET', '/m/attachments/list', ['folder' => '999999'], [], $this->headers()))->status());

        // Renommage et déplacement du dossier
        $this->assertSame(200, $this->post('folder-rename', ['id' => $sub, 'name' => '2026-T1'])->status());
        $this->assertSame('/Factures/2026-T1', $folders->find($sub)['path']);
        $this->assertSame(200, $this->post('folder-move', ['id' => $sub, 'parent_id' => ''])->status());
        $this->assertSame('/2026-T1', $folders->find($sub)['path']);
        $this->assertSame(422, $this->post('folder-move', ['id' => $factures, 'parent_id' => $factures])->status());
        $this->assertSame(200, $this->post('folder-move', ['id' => $sub, 'parent_id' => $factures])->status());

        // Déplacement du fichier : à la racine (fiche), puis en masse vers le sous-dossier (liste)
        $this->assertSame(200, $this->post('move', ['id' => $id, 'folder_id' => ''])->status());
        $this->assertNull($attachments->find($id)['folder_id']);
        $this->assertStringContains('EDF janvier', $this->view('list', ['folder' => 'root']));
        $response = $this->post('move', ['ids' => [$id], 'folder_id' => $sub]);
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame(1, $response->decodedJson()['data']['moved']);
        $this->assertSame($sub, (int) $attachments->find($id)['folder_id']);
        $this->assertSame(422, $this->post('move', ['ids' => [], 'folder_id' => $sub])->status());

        // Bob ne peut ni déplacer le fichier d'Alice ni le voir dans le dossier
        $this->login('bob');
        $this->assertSame(403, $this->post('move', ['id' => $id, 'folder_id' => ''])->status());
        $this->assertFalse(str_contains($this->view('list', ['folder' => (string) $sub]), 'EDF janvier'));
        $this->assertSame($sub, (int) $attachments->find($id)['folder_id']);

        // Suppression du sous-dossier : le fichier remonte dans « Factures » ; puis du parent : à la racine
        $this->login('alice');
        $response = $this->post('folder-delete', ['id' => $sub]);
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertStringContains('1 fichier(s) remonté(s) dans « /Factures »', $response->decodedJson()['message']);
        $this->assertSame('list?folder=' . $factures, $response->decodedJson()['directives']['navigate'] ?? null);
        $this->assertNull($folders->find($sub));
        $this->assertSame($factures, (int) $attachments->find($id)['folder_id']);
        $response = $this->post('folder-delete', ['id' => $factures]);
        $this->assertSame(200, $response->status());
        $this->assertStringContains('la racine', $response->decodedJson()['message']);
        $this->assertNull($attachments->find($id)['folder_id']);
        $this->assertSame(404, $this->post('folder-delete', ['id' => $factures])->status());
        $this->assertSame('edf', $this->app->handle(Request::create('GET', '/m/attachments/download/' . $id, [], [], $this->headers()))->streamToString());
    }
}
