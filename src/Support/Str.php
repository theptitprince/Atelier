<?php

declare(strict_types=1);

namespace Atelier\Support;

/**
 * Fonctions utilitaires sur les chaînes.
 */
final class Str
{
    /** Échappement HTML systématique (UTF-8, guillemets inclus). */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Identifiant aléatoire sûr, en hexadécimal (2 caractères par octet). */
    public static function random(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** UUID v4. */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Référence courte d'incident, ex. ERR-7F3A9C. */
    public static function errorReference(): string
    {
        return 'ERR-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /** Vérifie un identifiant technique : lettres minuscules, chiffres, tirets et underscores. */
    public static function isSlug(string $value, int $max = 64): bool
    {
        return $value !== '' && strlen($value) <= $max && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $value) === 1;
    }

    /** Transforme "mon-module" en "MonModule". */
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    /** Normalisation d'un tag : sans #, sans espaces superflus, insensible à la casse. */
    public static function normalizeTag(string $value): string
    {
        $value = trim($value);
        $value = ltrim($value, '#');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return mb_strtolower(trim($value), 'UTF-8');
    }

    public static function truncate(string $value, int $length, string $suffix = '…'): string
    {
        if (mb_strlen($value, 'UTF-8') <= $length) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $length, 'UTF-8')) . $suffix;
    }

    /** Taille lisible : 1,5 Mo. */
    public static function humanSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $bytes : number_format($size, 1, ',', ' ')) . ' ' . $units[$i];
    }
}
