<?php

declare(strict_types=1);

namespace Atelier\Modules\News;

use InvalidArgumentException;

/**
 * Analyse d'un flux d'actualité : RSS 2.0, RSS 1.0 (RDF), Atom et JSON Feed.
 * Le contenu HTML des entrées est ramené à du texte brut (aucune balise conservée) ;
 * les résumés sont tronqués. Aucun accès réseau ni entité externe (LIBXML_NONET).
 */
final class FeedParser
{
    public const SUMMARY_MAX = 1200;
    public const TITLE_MAX = 500;

    /**
     * @return array{format: string, title: string, site_url: ?string, description: ?string, items: list<array<string, mixed>>}
     */
    public static function parse(string $body): array
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if ($body === '') {
            throw new InvalidArgumentException('Le flux est vide.');
        }
        if ($body[0] === '{') {
            return self::parseJson($body);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            throw new InvalidArgumentException('Le flux n’est ni un XML valide (RSS, Atom) ni un JSON Feed.');
        }
        $root = strtolower($xml->getName());
        return match (true) {
            $root === 'rss' => self::parseRss($xml),
            $root === 'feed' => self::parseAtom($xml),
            $root === 'rdf' => self::parseRdf($xml),
            default => throw new InvalidArgumentException('Format de flux non reconnu (racine « ' . $root . ' »).'),
        };
    }

    // ----- RSS 2.0 -----

    /** @return array{format: string, title: string, site_url: ?string, description: ?string, items: list<array<string, mixed>>} */
    private static function parseRss(\SimpleXMLElement $xml): array
    {
        $channel = $xml->channel;
        if ($channel === null) {
            throw new InvalidArgumentException('Flux RSS sans élément channel.');
        }
        $items = [];
        foreach ($channel->item as $entry) {
            $content = $entry->children('http://purl.org/rss/1.0/modules/content/');
            $dc = $entry->children('http://purl.org/dc/elements/1.1/');
            $media = $entry->children('http://search.yahoo.com/mrss/');
            $link = self::text($entry->link);
            $guid = self::text($entry->guid);
            $image = null;
            if (isset($entry->enclosure['url']) && str_starts_with((string) ($entry->enclosure['type'] ?? ''), 'image/')) {
                $image = (string) $entry->enclosure['url'];
            } elseif (isset($media->content['url'])) {
                $image = (string) $media->content['url'];
            } elseif (isset($media->thumbnail['url'])) {
                $image = (string) $media->thumbnail['url'];
            }
            $items[] = self::item(
                $guid,
                $link,
                self::text($entry->title),
                self::text($entry->description) ?: self::text($content->encoded ?? null),
                self::text($entry->author) ?: self::text($dc->creator ?? null),
                self::text($entry->pubDate) ?: self::text($dc->date ?? null),
                $image
            );
        }
        return [
            'format' => 'rss',
            'title' => self::text($channel->title),
            'site_url' => self::url(self::text($channel->link)),
            'description' => self::plain(self::text($channel->description), 500) ?: null,
            'items' => $items,
        ];
    }

    // ----- RSS 1.0 (RDF) -----

    /** @return array{format: string, title: string, site_url: ?string, description: ?string, items: list<array<string, mixed>>} */
    private static function parseRdf(\SimpleXMLElement $xml): array
    {
        $ns = 'http://purl.org/rss/1.0/';
        $rss = $xml->children($ns);
        $channel = $rss->channel;
        $items = [];
        foreach ($rss->item as $entry) {
            $dc = $entry->children('http://purl.org/dc/elements/1.1/');
            $attributes = $entry->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
            $link = self::text($entry->link);
            $items[] = self::item(
                (string) ($attributes["about"] ?? ""),
                $link,
                self::text($entry->title),
                self::text($entry->description),
                self::text($dc->creator ?? null),
                self::text($dc->date ?? null),
                null
            );
        }
        return [
            'format' => 'rdf',
            'title' => self::text($channel->title ?? null),
            'site_url' => self::url(self::text($channel->link ?? null)),
            'description' => self::plain(self::text($channel->description ?? null), 500) ?: null,
            'items' => $items,
        ];
    }

    // ----- Atom -----

    /** @return array{format: string, title: string, site_url: ?string, description: ?string, items: list<array<string, mixed>>} */
    private static function parseAtom(\SimpleXMLElement $xml): array
    {
        $items = [];
        foreach ($xml->entry as $entry) {
            $link = self::atomLink($entry);
            $summary = self::text($entry->summary) ?: self::text($entry->content);
            $author = isset($entry->author->name) ? self::text($entry->author->name) : '';
            $image = null;
            foreach ($entry->link as $l) {
                if ((string) ($l['rel'] ?? '') === 'enclosure' && str_starts_with((string) ($l['type'] ?? ''), 'image/')) {
                    $image = (string) $l['href'];
                    break;
                }
            }
            $items[] = self::item(
                self::text($entry->id),
                $link,
                self::text($entry->title),
                $summary,
                $author,
                self::text($entry->published) ?: self::text($entry->updated),
                $image
            );
        }
        return [
            'format' => 'atom',
            'title' => self::text($xml->title),
            'site_url' => self::url(self::atomLink($xml)),
            'description' => self::plain(self::text($xml->subtitle), 500) ?: null,
            'items' => $items,
        ];
    }

    private static function atomLink(\SimpleXMLElement $node): string
    {
        $fallback = '';
        foreach ($node->link as $link) {
            $rel = (string) ($link['rel'] ?? 'alternate');
            $href = (string) ($link['href'] ?? '');
            if ($href === '') {
                continue;
            }
            if ($rel === 'alternate' || $rel === '') {
                return $href;
            }
            if ($fallback === '' && $rel !== 'self' && $rel !== 'enclosure') {
                $fallback = $href;
            }
        }
        return $fallback;
    }

    // ----- JSON Feed -----

    /** @return array{format: string, title: string, site_url: ?string, description: ?string, items: list<array<string, mixed>>} */
    private static function parseJson(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            throw new InvalidArgumentException('JSON Feed invalide (clé « items » absente).');
        }
        $items = [];
        foreach ($data['items'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $url = (string) ($entry['url'] ?? $entry['external_url'] ?? '');
            $authors = $entry['authors'] ?? (isset($entry['author']) ? [$entry['author']] : []);
            $author = is_array($authors) && isset($authors[0]['name']) ? (string) $authors[0]['name'] : '';
            $items[] = self::item(
                (string) ($entry["id"] ?? ""),
                $url,
                (string) ($entry['title'] ?? ''),
                (string) ($entry['summary'] ?? $entry['content_text'] ?? $entry['content_html'] ?? ''),
                $author,
                (string) ($entry['date_published'] ?? $entry['date_modified'] ?? ''),
                isset($entry['image']) ? (string) $entry['image'] : null
            );
        }
        return [
            'format' => 'json',
            'title' => (string) ($data['title'] ?? ''),
            'site_url' => self::url((string) ($data['home_page_url'] ?? '')),
            'description' => self::plain((string) ($data['description'] ?? ''), 500) ?: null,
            'items' => $items,
        ];
    }

    // ----- Normalisation -----

    /** @return array<string, mixed> */
    private static function item(string $guid, string $link, string $title, string $summary, string $author, string $date, ?string $image): array
    {
        $url = self::url($link);
        $title = self::plain($title, self::TITLE_MAX);
        $guid = trim($guid);
        if ($guid === "") {
            $guid = $url ?? sha1($title . "|");
        }
        return [
            'guid' => mb_substr($guid, 0, 500, 'UTF-8'),
            'url' => $url,
            'title' => $title !== '' ? $title : '(sans titre)',
            'summary' => self::plain($summary, self::SUMMARY_MAX) ?: null,
            'author' => self::plain($author, 200) ?: null,
            'published_at' => self::date($date),
            'image_url' => self::url($image ?? ''),
        ];
    }

    private static function text(?\SimpleXMLElement $node): string
    {
        return $node === null ? '' : trim((string) $node);
    }

    /** Texte brut : entités décodées, balises retirées, espaces réduits, tronqué. */
    public static function plain(string $html, int $max): string
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*(br|p|div|li|h[1-6]|tr)[^>]*>/i', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text, 'UTF-8') > $max) {
            $text = rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…';
        }
        return $text;
    }

    /** Adresse http(s) valide ou null. */
    public static function url(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url, 'UTF-8') > 500) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        return $url;
    }

    /** Date UTC "Y-m-d H:i:s" ou null si illisible. */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
