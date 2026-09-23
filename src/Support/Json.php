<?php

declare(strict_types=1);

namespace Atelier\Support;

use JsonException;
use RuntimeException;

/**
 * Encodage/décodage JSON strict avec exceptions explicites.
 */
final class Json
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $flags);
    }

    /**
     * Encodage destiné à une réponse HTTP : une séquence UTF-8 invalide (nom de fichier hérité du
     * système, donnée importée) ne doit pas transformer la réponse en erreur serveur. Les octets
     * fautifs sont remplacés par le caractère de substitution, la réponse reste exploitable.
     */
    public static function encodeForResponse(mixed $value): string
    {
        try {
            return self::encode($value);
        } catch (JsonException) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }
    }

    /** @return mixed */
    public static function decode(string $json, bool $assoc = true): mixed
    {
        try {
            return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('JSON invalide : ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    public static function decodeArray(string $json): array
    {
        $value = self::decode($json);
        if (!is_array($value)) {
            throw new RuntimeException('JSON invalide : un objet ou un tableau était attendu.');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    public static function readFile(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Fichier JSON illisible : ' . $path);
        }
        return self::decodeArray($content);
    }

    public static function writeFile(string $path, mixed $value): void
    {
        Files::writeAtomic($path, self::encode($value, true) . "\n");
    }
}
