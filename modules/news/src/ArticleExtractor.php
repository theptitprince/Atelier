<?php

declare(strict_types=1);

namespace Atelier\Modules\News;

/**
 * Extraction du texte principal d'une page d'article (copie locale) : la page est nettoyée
 * (scripts, navigation, pieds de page, encarts), le bloc le plus dense en paragraphes est retenu
 * puis converti en texte structuré : paragraphes séparés par une ligne vide, titres préfixés
 * par « ## », éléments de liste par « • ». Aucun HTML n'est conservé.
 */
final class ArticleExtractor
{
    public const MAX_CHARS = 200000;

    private const DROP_TAGS = ['script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'iframe', 'svg', 'template', 'button', 'select', 'input', 'textarea', 'video', 'audio', 'canvas', 'object'];
    private const DROP_PATTERN = '/(^|[\s_-])(comment|comments|sidebar|share|sharing|social|related|cookie|newsletter|menu|breadcrumb|promo|advert|ad-|ads|banner|popup|modal|subscribe|paywall|footer|header|nav)([\s_-]|$)/i';
    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'main', 'blockquote', 'pre', 'ul', 'ol', 'table', 'tr', 'figure', 'figcaption', 'dl', 'dd', 'dt', 'hr', 'address', 'details', 'summary'];

    /**
     * @return array{text: string, title: ?string, image: ?string}
     */
    public static function extract(string $html): array
    {
        $html = self::toUtf8($html);
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($dom);
        $title = self::meta($xpath, 'og:title') ?? self::nodeText($xpath->query('//title')?->item(0));
        $image = self::meta($xpath, 'og:image');

        foreach (self::DROP_TAGS as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach (iterator_to_array($xpath->query('//*[@id or @class or @role]') ?: []) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $signature = $node->getAttribute('id') . ' ' . $node->getAttribute('class') . ' ' . $node->getAttribute('role');
            if (preg_match(self::DROP_PATTERN, $signature) === 1 && strtolower($node->tagName) !== 'body') {
                $node->parentNode?->removeChild($node);
            }
        }

        $best = null;
        $bestScore = 0;
        foreach (['article', 'main', 'section', 'div', 'body'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $candidate) {
                $score = self::score($candidate);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $candidate;
                }
            }
            if ($best !== null && in_array($tag, ['article', 'main'], true)) {
                break;
            }
        }
        $root = $best ?? $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        $text = $root === null ? '' : self::toText($root);
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > self::MAX_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::MAX_CHARS, 'UTF-8')) . "\n\n[…]";
        }
        return ['text' => $text, 'title' => $title !== null && $title !== '' ? mb_substr($title, 0, 500, 'UTF-8') : null, 'image' => $image];
    }

    private static function score(\DOMNode $node): int
    {
        $score = 0;
        foreach (['p', 'h1', 'h2', 'h3', 'li', 'blockquote'] as $tag) {
            if (!$node instanceof \DOMElement) {
                break;
            }
            foreach ($node->getElementsByTagName($tag) as $child) {
                $length = mb_strlen(trim($child->textContent), 'UTF-8');
                if ($length > 40) {
                    $score += $length;
                }
            }
        }
        return $score;
    }

    private static function toText(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return preg_replace('/\s+/u', ' ', $node->textContent) ?? $node->textContent;
        }
        if (!$node instanceof \DOMElement) {
            $out = '';
            foreach ($node->childNodes as $child) {
                $out .= self::toText($child);
            }
            return $out;
        }
        $tag = strtolower($node->tagName);
        if ($tag === 'br') {
            return "\n";
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::toText($child);
        }
        $inner = trim($inner, " \t");
        if (preg_match('/^h[1-6]$/', $tag) === 1) {
            return "\n\n" . ($tag <= 'h3' ? '## ' : '### ') . trim($inner) . "\n\n";
        }
        if ($tag === 'li') {
            return "\n• " . trim($inner) . "\n";
        }
        if ($tag === 'td' || $tag === 'th') {
            return $inner . "\t";
        }
        if (in_array($tag, self::BLOCK_TAGS, true)) {
            return "\n\n" . $inner . "\n\n";
        }
        return $inner;
    }

    private static function meta(\DOMXPath $xpath, string $property): ?string
    {
        $node = $xpath->query('//meta[@property="' . $property . '" or @name="' . $property . '"]')?->item(0);
        $value = $node instanceof \DOMElement ? trim($node->getAttribute('content')) : '';
        return $value !== '' ? $value : null;
    }

    private static function nodeText(?\DOMNode $node): ?string
    {
        $text = $node === null ? '' : trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
        return $text !== '' ? $text : null;
    }

    /** Ramène le document en UTF-8 d'après sa déclaration de jeu de caractères. */
    private static function toUtf8(string $html): string
    {
        $charset = null;
        if (preg_match('/<meta[^>]+charset=["\']?\s*([a-z0-9_-]+)/i', $html, $m) === 1) {
            $charset = strtoupper($m[1]);
        }
        if ($charset !== null && $charset !== 'UTF-8' && $charset !== 'UTF8' && in_array($charset, array_map('strtoupper', mb_list_encodings()), true)) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
            if (is_string($converted)) {
                $html = $converted;
            }
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        // Le document est désormais en UTF-8 : sa déclaration de jeu de caractères ne doit plus contredire l'analyseur.
        return preg_replace('/(<meta[^>]+charset=["\']?\s*)([a-z0-9_-]+)/i', '${1}utf-8', $html) ?? $html;
    }
}
