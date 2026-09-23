<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Modules\News\FeedFetcher;
use Atelier\Modules\News\FeedParser;
use Atelier\Modules\News\InterestMatcher;
use Atelier\Testing\TestCase;

final class NewsFeedParserTest extends TestCase
{
    public function setUp(): void
    {
        $dir = dirname(__DIR__, 2) . '/modules/news/src/';
        foreach (['FeedParser', 'FeedFetcher', 'InterestMatcher'] as $class) {
            require_once $dir . $class . '.php';
        }
    }

    public function testRss(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel><title>Journal &amp; Cie</title><link>https://exemple.fr/</link><description>Le &lt;b&gt;journal&lt;/b&gt;</description>
<item><title>Titre &amp; co</title><link>https://exemple.fr/a</link><guid isPermaLink="false">a-1</guid><pubDate>22 Sep 2026 08:00:00 +0200</pubDate>
<description><![CDATA[<p>Un <a href="x">résumé</a>&nbsp;avec du <script>alert(1)</script>HTML.</p>]]></description><dc:creator>Alice</dc:creator>
<enclosure url="https://exemple.fr/a.jpg" type="image/jpeg" length="1"/></item>
<item><title>Sans guid</title><link>https://exemple.fr/b</link><content:encoded><![CDATA[Contenu complet]]></content:encoded></item>
<item><title>Lien invalide</title><link>javascript:alert(1)</link></item>
</channel></rss>
XML;
        $feed = FeedParser::parse($xml);
        $this->assertSame('rss', $feed['format']);
        $this->assertSame('Journal & Cie', $feed['title']);
        $this->assertSame('https://exemple.fr/', $feed['site_url']);
        $this->assertSame('Le journal', $feed['description']);
        $this->assertCount(3, $feed['items']);
        $first = $feed['items'][0];
        $this->assertSame('a-1', $first['guid']);
        $this->assertSame('Titre & co', $first['title']);
        $this->assertSame('Un résumé avec du alert(1)HTML.', $first['summary']);
        $this->assertSame('Alice', $first['author']);
        $this->assertSame('2026-09-22 06:00:00', $first['published_at']);
        $this->assertSame('https://exemple.fr/a.jpg', $first['image_url']);
        $this->assertSame('https://exemple.fr/b', $feed['items'][1]['guid']);
        $this->assertSame('Contenu complet', $feed['items'][1]['summary']);
        $this->assertNull($feed['items'][2]['url']);
        $this->assertSame(sha1('Lien invalide|'), $feed['items'][2]['guid']);
    }

    public function testAtomAndJsonFeed(): void
    {
        $atom = <<<XML
<?xml version="1.0"?>
<feed xmlns="http://www.w3.org/2005/Atom"><title>Blog</title><subtitle>Notes</subtitle>
<link rel="self" href="https://blog.test/feed"/><link rel="alternate" href="https://blog.test/"/>
<entry><id>urn:1</id><title>Billet</title><link href="https://blog.test/1"/><updated>2026-09-20T10:00:00Z</updated><author><name>Bob</name></author><content type="html">&lt;p&gt;Texte&lt;/p&gt;</content></entry>
</feed>
XML;
        $feed = FeedParser::parse($atom);
        $this->assertSame('atom', $feed['format']);
        $this->assertSame('https://blog.test/', $feed['site_url']);
        $this->assertSame('urn:1', $feed['items'][0]['guid']);
        $this->assertSame('https://blog.test/1', $feed['items'][0]['url']);
        $this->assertSame('Texte', $feed['items'][0]['summary']);
        $this->assertSame('Bob', $feed['items'][0]['author']);
        $this->assertSame('2026-09-20 10:00:00', $feed['items'][0]['published_at']);

        $json = '{"version":"https://jsonfeed.org/version/1.1","title":"JSON","home_page_url":"https://j.test/","items":[{"id":"j1","url":"https://j.test/1","title":"Un","content_text":"Corps","date_published":"2026-09-21T12:00:00+02:00","authors":[{"name":"Cléo"}]}]}';
        $feed = FeedParser::parse($json);
        $this->assertSame('json', $feed['format']);
        $this->assertSame('Un', $feed['items'][0]['title']);
        $this->assertSame('Corps', $feed['items'][0]['summary']);
        $this->assertSame('Cléo', $feed['items'][0]['author']);
        $this->assertSame('2026-09-21 10:00:00', $feed['items'][0]['published_at']);

        $this->assertThrows(\InvalidArgumentException::class, fn () => FeedParser::parse('<html><body>Pas un flux</body></html>'));
        $this->assertThrows(\InvalidArgumentException::class, fn () => FeedParser::parse('{"title":"x"}'));
        $this->assertThrows(\InvalidArgumentException::class, fn () => FeedParser::parse(''));
    }

    public function testInterestMatcher(): void
    {
        $this->assertTrue(InterestMatcher::matches('Nouvelle fusée Ariane 6 lancée', 'spatial, fusée'));
        $this->assertTrue(InterestMatcher::matches('L’IA générative progresse', 'IA'));
        $this->assertFalse(InterestMatcher::matches('Le Cambodia s’exprime', 'IA'), 'mot entier seulement');
        $this->assertTrue(InterestMatcher::matches('CLIMAT : record de chaleur', 'Climat'));
        $this->assertTrue(InterestMatcher::matches('Rechauffement climatique', 'réchauffement'), 'accents ignorés');
        $this->assertFalse(InterestMatcher::matches('IA et Iran', 'IA, -Iran'), 'exclusion');
        $this->assertFalse(InterestMatcher::matches('Rien', ''));
        $this->assertSame(['a', 'b c'], InterestMatcher::keywords("a,\n b c ,,"));
    }

    public function testFetcherRefusesInternalAddresses(): void
    {
        foreach (['ftp://exemple.fr/x', 'http://localhost/feed', 'http://127.0.0.1/feed', 'http://10.0.0.5/', 'http://192.168.1.10/rss', 'http://[::1]/', 'http://user:pw@exemple.fr/', 'pas une url'] as $url) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => FeedFetcher::assertSafeUrl($url), null);
        }
        FeedFetcher::assertSafeUrl('https://93.184.216.34/rss');
        $fetcher = new FeedFetcher(static fn (string $url, array $headers, int $timeout): array => ['status' => 304, 'headers' => ['ETag' => '"abc"'], 'body' => '']);
        $response = $fetcher->fetch('https://exemple.test/rss', '"abc"', null);
        $this->assertSame(304, $response['status']);
        $this->assertSame('"abc"', $response['etag']);
    }

    /**
     * Les écritures numériques d'une IPv4 et les IPv4 encapsulées dans une IPv6 joignaient le
     * réseau interne : curl les comprend, le garde-fou ne les reconnaissait pas comme des IP.
     */
    public function testFetcherRefusesEncodedInternalAddresses(): void
    {
        $encodees = [
            'http://2130706433/' => '127.0.0.1 en décimal',
            'http://0x7f000001/' => '127.0.0.1 en hexadécimal',
            'http://0177.0.0.1/' => '127.0.0.1 en octal',
            'http://127.1/' => '127.0.0.1 abrégé',
            'http://2852039166/' => '169.254.169.254 (métadonnées) en décimal',
            'http://3232235777/' => '192.168.1.1 en décimal',
            'http://0xC0A80001/' => '192.168.0.1 en hexadécimal',
            'http://[::ffff:127.0.0.1]/' => 'IPv4 encapsulée dans une IPv6',
            'http://[::ffff:7f00:1]/' => 'IPv4 encapsulée, écriture hexadécimale',
            'http://[0:0:0:0:0:0:0:1]/' => 'bouclage IPv6 non abrégé',
            'http://[::]/' => 'adresse IPv6 indéterminée',
            'http://0/' => 'adresse nulle',
            'http://127.0.0.1.:8000/feed' => 'point final ajouté au nom',
        ];
        foreach (array_keys($encodees) as $url) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => FeedFetcher::assertSafeUrl($url), null);
        }
        // Une IP publique en notation pointée reste acceptée.
        FeedFetcher::assertSafeUrl('http://93.184.216.34/rss');
        // Mais la même IP en décimal est refusée : seule la notation usuelle est admise, pour que
        // ce qui est contrôlé soit exactement ce que curl joint.
        $this->assertThrows(\InvalidArgumentException::class, static fn () => FeedFetcher::assertSafeUrl('http://1572395042/rss'), null);
    }
}
