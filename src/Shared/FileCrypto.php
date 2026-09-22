<?php

declare(strict_types=1);

namespace Atelier\Shared;

use Atelier\Support\Files;
use RuntimeException;

/**
 * Chiffrement des fichiers au repos : AES-256-GCM par blocs (1 Mio), clé locale stockée hors
 * du répertoire public (var/config/attachments.key, générée à la première utilisation).
 *
 * Format du fichier chiffré :
 *   en-tête (37 octets) : "ATLC" | version (1) | identifiant de clé (8) | taille de bloc (4, BE)
 *                         | nonce de base (12) | taille du clair (8, BE)
 *   puis, pour chaque bloc : longueur du chiffré (4, BE) | tag GCM (16) | chiffré
 *
 * Chaque bloc est authentifié avec l'en-tête, son index et un indicateur "dernier bloc" :
 * toute modification, réordonnancement ou troncature du fichier est détectée au déchiffrement.
 * Le nonce d'un bloc dérive du nonce de base et de l'index du bloc (jamais réutilisé pour une même clé).
 */
final class FileCrypto
{
    public const CIPHER = 'aes-256-gcm';
    public const EXTENSION = 'enc';

    private const MAGIC = 'ATLC';
    private const VERSION = 1;
    private const TAG_LENGTH = 16;
    private const NONCE_LENGTH = 12;
    private const HEADER_LENGTH = 4 + 1 + 8 + 4 + self::NONCE_LENGTH + 8;
    private const KEY_LENGTH = 32;

    private ?string $key = null;

    public function __construct(private readonly string $keyFile, private readonly int $chunkSize = 1048576)
    {
        if ($this->chunkSize < 1024) {
            throw new \InvalidArgumentException('La taille de bloc doit être d’au moins 1 Kio.');
        }
    }

    public static function isAvailable(): bool
    {
        return function_exists('openssl_encrypt') && in_array(self::CIPHER, array_map('strtolower', openssl_get_cipher_methods()), true);
    }

    public function keyFile(): string
    {
        return $this->keyFile;
    }

    /** Identifiant public de la clé (16 caractères hexadécimaux), enregistré avec chaque fichier. */
    public function keyId(): string
    {
        return substr(hash('sha256', $this->key()), 0, 16);
    }

    /** Un fichier porte-t-il l'en-tête du format chiffré ? */
    public static function isEncryptedFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $magic = fread($handle, 4);
        fclose($handle);
        return $magic === self::MAGIC;
    }

    /** Chiffre $source vers $target (écriture atomique). */
    public function encryptFile(string $source, string $target): void
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('Fichier source illisible : ' . $source);
        }
        Files::ensureDirectory(dirname($target));
        $temp = dirname($target) . '/.' . basename($target) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $out = @fopen($temp, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Impossible d’écrire le fichier chiffré : ' . $target);
        }
        try {
            $this->encryptStream($in, $out, (int) (filesize($source) ?: 0));
        } catch (\Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($temp);
            throw $e;
        }
        fclose($in);
        fclose($out);
        if (PHP_OS_FAMILY === 'Windows' && is_file($target)) {
            @unlink($target);
        }
        if (!@rename($temp, $target)) {
            @unlink($temp);
            throw new RuntimeException('Remplacement impossible : ' . $target);
        }
    }

    /**
     * Déchiffre $source dans le flux $out. Retourne le nombre d'octets écrits.
     * Lève RuntimeException si le fichier est altéré, tronqué ou chiffré avec une autre clé.
     *
     * @param resource $out
     */
    public function decryptToStream(string $source, $out): int
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('Fichier chiffré illisible : ' . $source);
        }
        try {
            return $this->decryptStream($in, $out);
        } finally {
            fclose($in);
        }
    }

    public function decryptToString(string $source): string
    {
        $out = fopen('php://temp', 'w+b');
        if ($out === false) {
            throw new RuntimeException('Impossible d’allouer un tampon.');
        }
        $this->decryptToStream($source, $out);
        rewind($out);
        $content = (string) stream_get_contents($out);
        fclose($out);
        return $content;
    }

    /** Taille du clair annoncée par l'en-tête (sans déchiffrer). */
    public function plainSize(string $source): int
    {
        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Fichier chiffré illisible : ' . $source);
        }
        $header = $this->readHeader($handle);
        fclose($handle);
        return $header['size'];
    }

    // ----- Flux -----

    /**
     * @param resource $in
     * @param resource $out
     */
    private function encryptStream($in, $out, int $plainSize): void
    {
        $key = $this->key();
        $baseNonce = random_bytes(self::NONCE_LENGTH);
        $header = self::MAGIC
            . chr(self::VERSION)
            . hex2bin($this->keyId())
            . pack('N', $this->chunkSize)
            . $baseNonce
            . self::packUint64($plainSize);
        if (fwrite($out, $header) !== strlen($header)) {
            throw new RuntimeException('Écriture de l’en-tête impossible.');
        }

        $index = 0;
        $written = 0;
        $chunk = fread($in, $this->chunkSize);
        if ($chunk === false) {
            $chunk = '';
        }
        do {
            $next = feof($in) ? '' : (fread($in, $this->chunkSize) ?: '');
            $last = $next === '';
            $tag = '';
            $cipher = openssl_encrypt($chunk, self::CIPHER, $key, OPENSSL_RAW_DATA, $this->nonceFor($baseNonce, $index), $tag, $this->aad($header, $index, $last), self::TAG_LENGTH);
            if ($cipher === false) {
                throw new RuntimeException('Échec du chiffrement (openssl).');
            }
            $frame = pack('N', strlen($cipher)) . $tag . $cipher;
            if (fwrite($out, $frame) !== strlen($frame)) {
                throw new RuntimeException('Écriture du bloc chiffré impossible.');
            }
            $written += strlen($chunk);
            $index++;
            $chunk = $next;
        } while (!$last);

        if ($written !== $plainSize) {
            throw new RuntimeException('Taille du fichier source incohérente pendant le chiffrement.');
        }
    }

    /**
     * @param resource $in
     * @param resource $out
     */
    private function decryptStream($in, $out): int
    {
        $key = $this->key();
        $header = $this->readHeader($in);
        $index = 0;
        $written = 0;

        $prefix = $this->readExact($in, 4, true);
        if ($prefix === null) {
            throw new RuntimeException('Fichier chiffré tronqué (aucun bloc).');
        }
        while (true) {
            $length = unpack('N', $prefix)[1];
            if ($length > $header['chunkSize']) {
                throw new RuntimeException('Fichier chiffré altéré (bloc trop long).');
            }
            $tag = $this->readExact($in, self::TAG_LENGTH);
            $cipher = $length === 0 ? '' : $this->readExact($in, $length);
            $next = $this->readExact($in, 4, true);
            $last = $next === null;
            $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $this->nonceFor($header['nonce'], $index), $tag, $this->aad($header['raw'], $index, $last));
            if ($plain === false) {
                throw new RuntimeException('Fichier chiffré altéré ou tronqué : authentification du bloc ' . $index . ' impossible.');
            }
            if ($plain !== '' && fwrite($out, $plain) !== strlen($plain)) {
                throw new RuntimeException('Écriture du contenu déchiffré impossible.');
            }
            $written += strlen($plain);
            $index++;
            if ($last) {
                break;
            }
            $prefix = $next;
        }
        if ($written !== $header['size']) {
            throw new RuntimeException('Fichier chiffré altéré : taille du contenu incohérente.');
        }
        return $written;
    }

    /**
     * @param resource $handle
     * @return array{raw: string, keyId: string, chunkSize: int, nonce: string, size: int}
     */
    private function readHeader($handle): array
    {
        $raw = $this->readExact($handle, self::HEADER_LENGTH, true);
        if ($raw === null || substr($raw, 0, 4) !== self::MAGIC) {
            throw new RuntimeException('Le fichier n’est pas au format chiffré attendu.');
        }
        if (ord($raw[4]) !== self::VERSION) {
            throw new RuntimeException('Version du format chiffré non prise en charge.');
        }
        $keyId = bin2hex(substr($raw, 5, 8));
        if (!hash_equals($this->keyId(), $keyId)) {
            throw new RuntimeException('Ce fichier a été chiffré avec une autre clé (' . $keyId . ').');
        }
        return [
            'raw' => $raw,
            'keyId' => $keyId,
            'chunkSize' => unpack('N', substr($raw, 13, 4))[1],
            'nonce' => substr($raw, 17, self::NONCE_LENGTH),
            'size' => self::unpackUint64(substr($raw, 17 + self::NONCE_LENGTH, 8)),
        ];
    }

    /**
     * Lit exactement $length octets ; retourne null en fin de fichier si $nullOnEof, sinon lève.
     *
     * @param resource $handle
     */
    private function readExact($handle, int $length, bool $nullOnEof = false): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($handle, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }
        if (strlen($data) === $length) {
            return $data;
        }
        if ($data === '' && $nullOnEof) {
            return null;
        }
        throw new RuntimeException('Fichier chiffré tronqué.');
    }

    private function nonceFor(string $baseNonce, int $index): string
    {
        $counter = unpack('N', substr($baseNonce, 8, 4))[1];
        return substr($baseNonce, 0, 8) . pack('N', ($counter ^ $index) & 0xFFFFFFFF);
    }

    private function aad(string $header, int $index, bool $last): string
    {
        return $header . pack('N', $index) . ($last ? "\x01" : "\x00");
    }

    // ----- Clé -----

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if (is_file($this->keyFile)) {
            $hex = trim((string) file_get_contents($this->keyFile));
            $key = ctype_xdigit($hex) && strlen($hex) === self::KEY_LENGTH * 2 ? hex2bin($hex) : false;
            if ($key === false) {
                throw new RuntimeException('Fichier de clé de chiffrement invalide : ' . $this->keyFile);
            }
            return $this->key = $key;
        }
        $key = random_bytes(self::KEY_LENGTH);
        Files::writeAtomic($this->keyFile, bin2hex($key) . PHP_EOL);
        @chmod($this->keyFile, 0600);
        return $this->key = $key;
    }

    private static function packUint64(int $value): string
    {
        return pack('J', $value);
    }

    private static function unpackUint64(string $bytes): int
    {
        return (int) unpack('J', $bytes)[1];
    }
}
