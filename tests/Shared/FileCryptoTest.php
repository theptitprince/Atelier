<?php

declare(strict_types=1);

namespace Atelier\Tests\Shared;

use Atelier\Shared\FileCrypto;
use Atelier\Testing\TestCase;

/**
 * Chiffrement des fichiers au repos : aller-retour, découpage en blocs, détection des altérations,
 * des troncatures et d'une clé étrangère.
 */
final class FileCryptoTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/atelier-crypto-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testRoundTripAndKeyIsGeneratedOnFirstUse(): void
    {
        $crypto = new FileCrypto($this->dir . '/key');
        $this->assertFalse(is_file($this->dir . '/key'));
        $plain = $this->dir . '/plain.txt';
        file_put_contents($plain, "Bonjour, fichier joint chiffré.\n");
        $crypto->encryptFile($plain, $this->dir . '/out.enc');
        $this->assertTrue(is_file($this->dir . '/key'), 'la clé est générée au premier usage');
        $this->assertMatches('/^[0-9a-f]{64}$/', trim((string) file_get_contents($this->dir . '/key')));
        $this->assertTrue(FileCrypto::isEncryptedFile($this->dir . '/out.enc'));
        $this->assertFalse(FileCrypto::isEncryptedFile($plain));
        $this->assertFalse(str_contains((string) file_get_contents($this->dir . '/out.enc'), 'Bonjour'));
        $this->assertSame("Bonjour, fichier joint chiffré.\n", $crypto->decryptToString($this->dir . '/out.enc'));
        $this->assertSame(strlen("Bonjour, fichier joint chiffré.\n"), $crypto->plainSize($this->dir . '/out.enc'));
        $this->assertMatches('/^[0-9a-f]{16}$/', $crypto->keyId());
    }

    public function testMultiChunkAndEmptyFiles(): void
    {
        $crypto = new FileCrypto($this->dir . '/key', 1024);
        $content = random_bytes(5000); // 4 blocs pleins + 1 partiel
        file_put_contents($this->dir . '/big.bin', $content);
        $crypto->encryptFile($this->dir . '/big.bin', $this->dir . '/big.enc');
        $this->assertSame($content, $crypto->decryptToString($this->dir . '/big.enc'));

        file_put_contents($this->dir . '/exact.bin', random_bytes(2048)); // multiple exact de la taille de bloc
        $crypto->encryptFile($this->dir . '/exact.bin', $this->dir . '/exact.enc');
        $this->assertSame(file_get_contents($this->dir . '/exact.bin'), $crypto->decryptToString($this->dir . '/exact.enc'));

        file_put_contents($this->dir . '/empty.bin', '');
        $crypto->encryptFile($this->dir . '/empty.bin', $this->dir . '/empty.enc');
        $this->assertSame('', $crypto->decryptToString($this->dir . '/empty.enc'));
    }

    public function testTamperingIsDetected(): void
    {
        $crypto = new FileCrypto($this->dir . '/key', 1024);
        file_put_contents($this->dir . '/doc.bin', random_bytes(3000));
        $crypto->encryptFile($this->dir . '/doc.bin', $this->dir . '/doc.enc');
        $encrypted = (string) file_get_contents($this->dir . '/doc.enc');

        // Un octet du deuxième bloc modifié
        $altered = $encrypted;
        $offset = 37 + 4 + 16 + 1024 + 4 + 16 + 10;
        $altered[$offset] = chr(ord($altered[$offset]) ^ 0x01);
        file_put_contents($this->dir . '/altered.enc', $altered);
        $this->assertThrows(\RuntimeException::class, fn () => $crypto->decryptToString($this->dir . '/altered.enc'), 'altéré');

        // Dernier bloc retiré (troncature "propre" à une frontière de bloc)
        $lastChunkLength = 3000 - 2048;
        $truncated = substr($encrypted, 0, strlen($encrypted) - (4 + 16 + $lastChunkLength));
        file_put_contents($this->dir . '/truncated.enc', $truncated);
        $this->assertThrows(\RuntimeException::class, fn () => $crypto->decryptToString($this->dir . '/truncated.enc'));

        // Troncature au milieu d'un bloc
        file_put_contents($this->dir . '/cut.enc', substr($encrypted, 0, strlen($encrypted) - 5));
        $this->assertThrows(\RuntimeException::class, fn () => $crypto->decryptToString($this->dir . '/cut.enc'), 'tronqué');

        // Deux blocs échangés
        $header = substr($encrypted, 0, 37);
        $frame1 = substr($encrypted, 37, 4 + 16 + 1024);
        $frame2 = substr($encrypted, 37 + 4 + 16 + 1024, 4 + 16 + 1024);
        $rest = substr($encrypted, 37 + 2 * (4 + 16 + 1024));
        file_put_contents($this->dir . '/swapped.enc', $header . $frame2 . $frame1 . $rest);
        $this->assertThrows(\RuntimeException::class, fn () => $crypto->decryptToString($this->dir . '/swapped.enc'));
    }

    public function testForeignKeyIsRefused(): void
    {
        $a = new FileCrypto($this->dir . '/key-a');
        $b = new FileCrypto($this->dir . '/key-b');
        file_put_contents($this->dir . '/x.bin', 'secret');
        $a->encryptFile($this->dir . '/x.bin', $this->dir . '/x.enc');
        $this->assertThrows(\RuntimeException::class, fn () => $b->decryptToString($this->dir . '/x.enc'), 'autre clé');
        $this->assertThrows(\RuntimeException::class, fn () => $a->decryptToString($this->dir . '/x.bin'), 'format');
    }
}
