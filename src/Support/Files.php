<?php

declare(strict_types=1);

namespace Atelier\Support;

use RuntimeException;

/**
 * Opérations sur le système de fichiers : écriture atomique, création de répertoires, nettoyage.
 */
final class Files
{
    public static function ensureDirectory(string $path, int $mode = 0775): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException('Impossible de créer le répertoire : ' . $path);
        }
    }

    /**
     * Écriture atomique : fichier temporaire dans le même répertoire puis remplacement.
     */
    public static function writeAtomic(string $path, string $content): void
    {
        $directory = dirname($path);
        self::ensureDirectory($directory);

        $temp = $directory . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Écriture impossible : ' . $path);
        }
        // Sous Windows, rename() échoue si la cible existe : on la retire d'abord.
        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            @unlink($path);
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Remplacement impossible : ' . $path);
        }
    }

    public static function isWritableDirectory(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    /** Supprime les fichiers d'un répertoire plus anciens que $days jours, selon un motif glob. */
    public static function purgeOlderThan(string $directory, int $days, string $pattern = '*'): int
    {
        if (!is_dir($directory)) {
            return 0;
        }
        $limit = time() - $days * 86400;
        $deleted = 0;
        foreach (glob(rtrim($directory, '/') . '/' . $pattern) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $limit && @unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Copie récursive d'un répertoire. */
    public static function copyDirectory(string $source, string $destination): void
    {
        self::ensureDirectory($destination);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                self::ensureDirectory($target);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    /** Taille cumulée d'un répertoire en octets. */
    public static function directorySize(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    /** Nettoie un nom de fichier fourni par un utilisateur (affichage uniquement, jamais utilisé comme chemin). */
    public static function sanitizeFilename(string $name): string
    {
        $name = str_replace(['\\', '/', "\0"], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
        $name = trim($name, " ._");
        return $name === '' ? 'fichier' : mb_substr($name, 0, 200, 'UTF-8');
    }
}
