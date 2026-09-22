<?php

declare(strict_types=1);

namespace Atelier\Modules\News;

/**
 * Correspondance entre un texte et les mots-clés d'un centre d'intérêt (séparés par des virgules
 * ou des retours à la ligne) : insensible à la casse et aux accents, sur des mots entiers.
 * Un mot-clé préfixé par « - » exclut le texte s'il est présent ("IA, -Iran").
 */
final class InterestMatcher
{
    public static function matches(string $text, string $keywords): bool
    {
        $haystack = ' ' . self::normalize($text) . ' ';
        $found = false;
        foreach (self::keywords($keywords) as $keyword) {
            $negative = str_starts_with($keyword, '-');
            $needle = self::normalize(ltrim($keyword, '-'));
            if ($needle === '') {
                continue;
            }
            $present = preg_match('/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/', $haystack) === 1;
            if ($negative && $present) {
                return false;
            }
            if (!$negative && $present) {
                $found = true;
            }
        }
        return $found;
    }

    /** @return list<string> */
    public static function keywords(string $keywords): array
    {
        $parts = preg_split('/[,;\n]+/', $keywords) ?: [];
        $list = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $list[] = $part;
            }
        }
        return $list;
    }

    /** Minuscules sans accents, ponctuation remplacée par des espaces. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n', '’' => "'"]);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
