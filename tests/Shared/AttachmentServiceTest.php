<?php

declare(strict_types=1);

namespace Atelier\Tests\Shared;

use Atelier\Error\ValidationException;
use Atelier\Kernel\Config;
use Atelier\Persistence\Database;
use Atelier\Shared\AttachmentService;
use Atelier\Shared\FileCrypto;
use Atelier\Support\Clock;
use Atelier\Testing\TestCase;

/**
 * Pièces jointes : stockage chiffré, téléchargement déchiffré en flux, intégrité, filtres et quotas.
 */
final class AttachmentServiceTest extends TestCase
{
    private Database $db;
    private Config $config;
    private string $var;
    private AttachmentService $service;

    public function setUp(): void
    {
        $this->db = $this->database();
        $root = dirname(__DIR__, 2);
        $this->var = sys_get_temp_dir() . '/atelier-att-' . bin2hex(random_bytes(4));
        mkdir($this->var, 0777, true);
        $values = require $root . '/config/app.php';
        $values['paths']['var'] = $this->var;
        $this->config = Config::fromArray($values, $root);
        $this->service = new AttachmentService($this->db, $this->config, new FileCrypto($this->config->path('attachments_key')));
        $now = Clock::utc();
        $this->db->insert('users', ['username' => 'alice', 'display_name' => 'Alice', 'password_hash' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    }

    public function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->var, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->var);
    }

    public function testStoredFileIsEncryptedAndDownloadDecryptsOnTheFly(): void
    {
        $content = "Compte rendu\nligne 2\n";
        $record = $this->service->storeContent($content, 'compte rendu.txt', null, 1, 'Un texte');
        $this->assertSame('text/plain', $record['mime']);
        $this->assertSame(FileCrypto::CIPHER, $record['cipher']);
        $this->assertSame(hash('sha256', $content), $record['sha256']);
        $this->assertMatches('/\.enc$/', $record['storage_path']);

        $onDisk = $this->service->directory() . '/' . $record['storage_path'];
        $this->assertTrue(is_file($onDisk));
        $this->assertTrue(FileCrypto::isEncryptedFile($onDisk));
        $this->assertFalse(str_contains((string) file_get_contents($onDisk), 'Compte rendu'), 'le clair ne doit pas apparaître sur disque');
        $this->assertTrue(is_file($this->config->path('attachments_key')));

        $response = $this->service->download($record['id']);
        $this->assertTrue($response->isStream());
        $this->assertSame($content, $response->streamToString());
        $this->assertSame((string) strlen($content), $response->header('Content-Length'));
        $this->assertStringContains('attachment', (string) $response->header('Content-Disposition'));
        $this->assertStringContains('compte%20rendu.txt', (string) $response->header('Content-Disposition'));
        $this->assertSame('private, no-store', $response->header('Cache-Control'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));

        $inline = $this->service->download($record['id'], true);
        $this->assertStringContains('inline', (string) $inline->header('Content-Disposition'));

        $this->assertSame($content, $this->service->contents($record['id']));
        $this->assertTrue($this->service->verify($record['id'])['ok']);
    }

    public function testVerifyDetectsCorruptedStorage(): void
    {
        $record = $this->service->storeContent(str_repeat('abc', 1000), 'data.txt', null, 1);
        $path = $this->service->directory() . '/' . $record['storage_path'];
        $bytes = (string) file_get_contents($path);
        $bytes[60] = chr(ord($bytes[60]) ^ 0xFF);
        file_put_contents($path, $bytes);
        $result = $this->service->verify($record['id']);
        $this->assertFalse($result['ok']);
        $this->assertStringContains('altéré', $result['message']);
        $this->assertThrows(\RuntimeException::class, fn () => $this->service->download($record['id'])->streamToString());
    }

    public function testTypeAndSizeControls(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->service->storeContent('MZ...', 'virus.exe', null, 1), 'refusé');
        $this->assertThrows(ValidationException::class, fn () => $this->service->storeContent('', 'vide.txt', null, 1), 'vide');
        $this->assertThrows(ValidationException::class, fn () => $this->service->storeContent("\x7fELF\x02\x01\x01", 'binaire.dat', null, 1), 'non autorisé');
        $this->assertSame('image', AttachmentService::kindOf('image/png'));
        $this->assertSame('document', AttachmentService::kindOf('application/vnd.oasis.opendocument.text'));
        $this->assertSame('other', AttachmentService::kindOf('application/octet-stream'));
    }

    public function testPaginationFiltersAndTrash(): void
    {
        $a = $this->service->storeContent('un', 'a.txt', null, 1, 'premier');
        $b = $this->service->storeContent('deux', 'b.csv', null, 1);
        $this->db->insert('info_registry', ['id' => 'info-1', 'dataset_code' => 'demo.item', 'module_id' => 'demo', 'local_key' => '1', 'label' => 'Objet démo', 'created_by' => 1, 'created_at' => Clock::utc()]);
        $this->service->attach($b['id'], 'info-1');

        $this->assertSame(2, $this->service->paginate(['uploaded_by' => 1], 1, 10)['total']);
        $this->assertSame(1, $this->service->paginate(['search' => 'premier'], 1, 10)['total']);
        $this->assertSame(1, $this->service->paginate(['search' => 'démo'], 1, 10)['total'], 'recherche sur le libellé de l’information rattachée');
        $this->assertSame(1, $this->service->paginate(['linked' => true], 1, 10)['total']);
        $this->assertSame(1, $this->service->paginate(['linked' => false], 1, 10)['total']);
        $this->assertSame(2, $this->service->paginate(['kind' => 'text'], 1, 10)['total']);
        $this->assertSame(0, $this->service->paginate(['kind' => 'image'], 1, 10)['total']);
        $row = $this->service->paginate(['linked' => true], 1, 10)['rows'][0];
        $this->assertSame('Objet démo', $row['info_label']);
        $this->assertSame('alice', $row['uploader']);

        $this->service->recordDownload($a['id']);
        $this->assertSame(1, (int) $this->service->find($a['id'])['downloads']);

        $this->service->softDelete($a['id']);
        $this->assertNull($this->service->find($a['id']));
        $this->assertSame(1, $this->service->paginate(['deleted' => true], 1, 10)['total']);
        $stats = $this->service->stats(1);
        $this->assertSame(1, $stats['count']);
        $this->assertSame(1, $stats['trashed']);
        $this->service->restore($a['id']);
        $this->assertNotNull($this->service->find($a['id']));

        $path = $this->service->directory() . '/' . $a['storage_path'];
        $this->service->softDelete($a['id']);
        $this->assertTrue($this->service->purge($a['id']));
        $this->assertFalse(is_file($path));
        $this->assertNull($this->service->find($a['id'], true));
    }

    public function testEncryptExistingMigratesPlainFiles(): void
    {
        $plainService = new AttachmentService($this->db, $this->config, null);
        $record = $plainService->storeContent('en clair', 'clair.txt', null, 1);
        $this->assertNull($record['cipher']);
        $this->assertFalse(FileCrypto::isEncryptedFile($this->service->directory() . '/' . $record['storage_path']));

        $result = $this->service->encryptExisting();
        $this->assertSame(1, $result['encrypted']);
        $this->assertSame([], $result['errors']);
        $migrated = $this->service->find($record['id']);
        $this->assertSame(FileCrypto::CIPHER, $migrated['cipher']);
        $this->assertTrue(FileCrypto::isEncryptedFile($this->service->directory() . '/' . $migrated['storage_path']));
        $this->assertSame('en clair', $this->service->contents($record['id']));
        $this->assertTrue($this->service->verify($record['id'])['ok']);
    }
}
