<?php

declare(strict_types=1);

namespace Atelier\View;

use Atelier\Support\Str;

/**
 * Rendu sécurisé d'un sous-ensemble de BBCode vers HTML. Le texte est d'abord intégralement
 * échappé, puis seules les balises de la liste blanche sont converties : aucun HTML saisi par
 * l'utilisateur ne passe. Les URL sont limitées à http(s) et aux chemins relatifs internes.
 *
 * Balises : [b] [i] [u] [s] [h1] [h2] [h3] [quote] [quote=auteur] [code] [url] [url=…] [list] [*]
 * [color=nom|#hex] [size=small|large] [hr] [center] [right]. Les sauts de ligne deviennent <br>.
 *
 * Le composant JavaScript commun (data-editor="bbcode") applique les mêmes règles pour l'aperçu.
 */
final class BbCode
{
    private const SIMPLE = [
        'b' => 'strong',
        'i' => 'em',
        'u' => 'u',
        's' => 's',
        'h1' => 'h2',   // le h1 est réservé au titre de l'écran
        'h2' => 'h3',
        'h3' => 'h4',
        'center' => 'div class="bb-center"',
        'right' => 'div class="bb-right"',
    ];

    private const COLORS = ['red', 'green', 'blue', 'orange', 'gray', 'grey', 'purple', 'teal', 'black'];

    public static function toHtml(?string $source): string
    {
        if ($source === null || trim($source) === '') {
            return '';
        }
        $text = str_replace(["\r\n", "\r"], "\n", $source);
        $text = Str::e($text);

        // Blocs de code : protégés avant toute autre conversion.
        $codes = [];
        $text = preg_replace_callback('/\[code\](.*?)\[\/code\]/si', static function (array $m) use (&$codes): string {
            $codes[] = '<pre class="bb-code"><code>' . trim($m[1], "\n") . '</code></pre>';
            return "\x00CODE" . (count($codes) - 1) . "\x00";
        }, $text) ?? $text;

        foreach (self::SIMPLE as $tag => $html) {
            $open = '<' . $html . '>';
            $close = '</' . explode(' ', $html, 2)[0] . '>';
            $text = preg_replace('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/si', $open . '$1' . $close, $text) ?? $text;
        }

        $text = preg_replace('/\[quote=(?:&quot;)?([^\]&]{1,80}?)(?:&quot;)?\](.*?)\[\/quote\]/si', '<blockquote class="bb-quote"><cite>$1</cite>$2</blockquote>', $text) ?? $text;
        $text = preg_replace('/\[quote\](.*?)\[\/quote\]/si', '<blockquote class="bb-quote">$1</blockquote>', $text) ?? $text;

        $text = preg_replace_callback('/\[url=(?:&quot;)?([^\]\s&]+?)(?:&quot;)?\](.*?)\[\/url\]/si', static function (array $m): string {
            $href = self::safeUrl(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return $href === null ? $m[2] : '<a href="' . Str::e($href) . '" rel="noopener" target="_blank">' . $m[2] . '</a>';
        }, $text) ?? $text;
        $text = preg_replace_callback('/\[url\]([^\[]+?)\[\/url\]/si', static function (array $m): string {
            $href = self::safeUrl(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return $href === null ? $m[1] : '<a href="' . Str::e($href) . '" rel="noopener" target="_blank">' . $m[1] . '</a>';
        }, $text) ?? $text;

        $text = preg_replace_callback('/\[color=(?:&quot;)?(#[0-9a-f]{3,6}|[a-z]+)(?:&quot;)?\](.*?)\[\/color\]/si', static function (array $m): string {
            $color = strtolower($m[1]);
            if (!str_starts_with($color, '#') && !in_array($color, self::COLORS, true)) {
                return $m[2];
            }
            return '<span style="color:' . $color . '">' . $m[2] . '</span>';
        }, $text) ?? $text;
        $text = preg_replace('/\[size=small\](.*?)\[\/size\]/si', '<span class="bb-small">$1</span>', $text) ?? $text;
        $text = preg_replace('/\[size=large\](.*?)\[\/size\]/si', '<span class="bb-large">$1</span>', $text) ?? $text;

        // Listes : [list] [*] a [*] b [/list] ; [list=1] pour une liste numérotée.
        $text = preg_replace_callback('/\[list(=1)?\](.*?)\[\/list\]/si', static function (array $m): string {
            $items = preg_split('/\[\*\]/', $m[2]) ?: [];
            $items = array_values(array_filter(array_map('trim', $items), static fn (string $i): bool => $i !== ''));
            $tag = $m[1] === '' ? 'ul' : 'ol';
            return '<' . $tag . ' class="bb-list"><li>' . implode('</li><li>', $items) . '</li></' . $tag . '>';
        }, $text) ?? $text;

        $text = preg_replace('/\[hr\]/i', '<hr>', $text) ?? $text;

        // Sauts de ligne, sauf immédiatement après/avant un bloc.
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/(<\/(?:h2|h3|h4|blockquote|ul|ol|div|pre)>|<hr>)\n+/', '$1', $text) ?? $text;
        $text = preg_replace('/\n+(<(?:h2|h3|h4|blockquote|ul|ol|div|pre|hr)\b)/', '$1', $text) ?? $text;
        $text = nl2br($text, false);

        $text = preg_replace_callback('/\x00CODE(\d+)\x00/', static fn (array $m): string => $codes[(int) $m[1]] ?? '', $text) ?? $text;

        return '<div class="bb">' . $text . '</div>';
    }

    /** Texte brut sans balises (extraits, recherche). */
    public static function toText(?string $source): string
    {
        if ($source === null) {
            return '';
        }
        $text = preg_replace('/\[\*\]/', ' • ', $source) ?? $source;
        $text = preg_replace('/\[\/?[a-z0-9]+(=[^\]]*)?\]/i', '', $text) ?? $text;
        return trim($text);
    }

    private static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\s"\'<>]/', $url) === 1) {
            return null;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        return null;
    }
}
