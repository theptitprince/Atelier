<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Modules\News\NewsModule;
use Atelier\Security\Acl\AclService;
use Atelier\Testing\TestCase;

/**
 * Module Actualités : flux (transport HTTP simulé), lecture, filtres, archivage, registre et service.
 */
final class NewsModuleTest extends TestCase
{
    private Application $app;
    private int $userId;

    /** @var array<string, array{status: int, headers: array<string, string>, body: string}> réponses simulées par URL */
    private array $responses = [];

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $this->userId, AclService::module('news'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $this->responses['https://exemple.test/rss'] = ['status' => 200, 'headers' => ['ETag' => '"v1"'], 'body' => self::rss([
            ['a1', 'Fusée Ariane : nouveau lancement', 'Le lanceur européen décolle.', '22 Sep 2026 08:00:00 +0000'],
            ['a2', 'Budget : les débats reprennent', 'Assemblée nationale.', '21 Sep 2026 08:00:00 +0000'],
        ])];
        $this->responses['https://exemple.test/a1'] = ['status' => 200, 'headers' => ['Content-Type' => 'text/html; charset=utf-8'], 'body' => '<html><head><title>Ariane</title></head><body><nav>Menu du site</nav><article><h1>Fusée Ariane : nouveau lancement</h1><p>Le lanceur européen a décollé ce matin de Kourou avec deux satellites à son bord, une réussite pour le programme.</p><p>La mission suivante est prévue au printemps, selon l’agence spatiale européenne qui salue le succès.</p></article><footer>Pied de page du site</footer></body></html>'];
        $this->responses['https://exemple.test/a2'] = ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => '<html><body><p>Trop court.</p></body></html>'];
        NewsModule::$transport = function (string $url, array $headers, int $timeout): array {
            if (!isset($this->responses[$url])) {
                throw new \RuntimeException('Hôte injoignable (simulation).');
            }
            $response = $this->responses[$url];
            if (isset($headers['If-None-Match']) && ($response['headers']['ETag'] ?? null) === $headers['If-None-Match']) {
                return ['status' => 304, 'headers' => $response['headers'], 'body' => ''];
            }
            return $response;
        };
    }

    public function tearDown(): void
    {
        NewsModule::$transport = null;
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string}> $items */
    private static function rss(array $items): string
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>Flux test</title><link>https://exemple.test/</link>';
        foreach ($items as [$guid, $title, $description, $date]) {
            $xml .= '<item><guid>' . $guid . '</guid><title>' . htmlspecialchars($title) . '</title><link>https://exemple.test/' . $guid . '</link><description>' . htmlspecialchars($description) . '</description><pubDate>' . $date . '</pubDate></item>';
        }
        return $xml . '</channel></rss>';
    }

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    private function post(string $route, array $data = []): \Atelier\Http\Response
    {
        return $this->app->handle(Request::create('POST', '/m/news/' . $route, [], $data, $this->headers()));
    }

    private function get(string $route, array $query = []): \Atelier\Http\Response
    {
        return $this->app->handle(Request::create('GET', '/m/news/' . $route, $query, [], $this->headers()));
    }

    public function testFeedLifecycleReadingAndInterests(): void
    {
        // Centre d'intérêt puis flux : les entrées récupérées sont classées
        $this->assertSame(200, $this->post('interest-save', ['name' => 'Espace', 'keywords' => 'fusée, spatial'])->status());
        $this->assertSame(200, $this->post('category-save', ['name' => 'Sciences', 'color' => '#1f8a4c'])->status());
        $category = $this->app->db->scalar('SELECT id FROM news_category');
        $response = $this->post('feed-save', ['url' => 'https://exemple.test/rss', 'category_id' => $category, 'refresh_minutes' => 60, 'retention_days' => 30, 'is_active' => 1]);
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertStringContains('2 nouvelle(s) entrée(s)', (string) $response->decodedJson()['message']);
        $feedId = (int) $response->decodedJson()['data']['id'];
        $feed = $this->app->db->selectOne('SELECT * FROM news_feed WHERE id = :id', ['id' => $feedId]);
        $this->assertSame('Flux test', $feed['title'], 'titre lu depuis le flux');
        $this->assertSame('ok', $feed['last_status']);
        $this->assertSame('"v1"', $feed['etag']);

        // Adresse interne et doublon refusés
        $this->assertSame(422, $this->post('feed-save', ['url' => 'http://127.0.0.1/rss'])->status());
        $this->assertSame(422, $this->post('feed-save', ['url' => 'https://exemple.test/rss'])->status());

        // Fil : 2 entrées non lues, filtre par centre d'intérêt
        $content = $this->get('list')->decodedJson()['data']['content'];
        $this->assertStringContains('Fusée Ariane', $content);
        $this->assertStringContains('Budget', $content);
        $this->assertStringContains('module-news', $content);
        $interestId = (int) $this->app->db->scalar('SELECT id FROM news_interest');
        $content = $this->get('list', ['interest' => $interestId])->decodedJson()['data']['content'];
        $this->assertStringContains('Fusée Ariane', $content);
        $this->assertFalse(str_contains($content, 'Budget'));
        $this->assertSame(2, $this->get('badge')->decodedJson()['data']['count']);

        // Lecture
        $itemId = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a2'");
        $response = $this->post('read', ['id' => $itemId, 'read' => true]);
        $this->assertSame(1, $response->decodedJson()['data']['unread']);
        $content = $this->get('list', ['unread' => 1])->decodedJson()['data']['content'];
        $this->assertFalse(str_contains($content, 'Budget'));
        $this->assertSame(200, $this->post('read-all')->status());
        $this->assertSame(0, $this->get('badge')->decodedJson()['data']['count']);

        // Nouvelle récupération : 304 puis nouveau contenu
        $response = $this->post('feed-refresh', ['id' => $feedId]);
        $this->assertStringContains('1 inchangé(s)', (string) $response->decodedJson()['message']);
        $this->responses['https://exemple.test/rss'] = ['status' => 200, 'headers' => ['ETag' => '"v2"'], 'body' => self::rss([['a3', 'Troisième', 'x', '23 Sep 2026 08:00:00 +0000']])];
        $response = $this->post('feed-refresh', ['id' => $feedId]);
        $this->assertStringContains('1 nouvelle(s)', (string) $response->decodedJson()['message']);
        $this->assertSame(1, $this->get('badge')->decodedJson()['data']['count']);

        // Flux en erreur : signalé sans casser la liste
        $this->responses['https://exemple.test/rss'] = ['status' => 500, 'headers' => [], 'body' => ''];
        $response = $this->post('feed-refresh', ['id' => $feedId]);
        $this->assertSame('warning', $response->decodedJson()['level']);
        $this->assertSame('error', $this->app->db->scalar('SELECT last_status FROM news_feed WHERE id = :id', ['id' => $feedId]));
        $this->assertSame(200, $this->get('feeds')->status());
        $this->assertSame(200, $this->get('list')->status());
    }

    public function testArchiveRegistersFactAndSurvivesPurge(): void
    {
        $this->post('feed-save', ['url' => 'https://exemple.test/rss', 'retention_days' => 1]);
        $itemId = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a1'");
        $response = $this->post('archive', ['id' => $itemId, 'note' => 'À suivre']);
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame('archive/' . $itemId, $response->decodedJson()['directives']['navigate']);
        $info = $this->app->shared->registry->find('news.archive', (string) $itemId);
        $this->assertNotNull($info);
        $this->assertSame('Fusée Ariane : nouveau lancement', $info['label']);

        $response = $this->post('archive-save', ['id' => $itemId, 'note' => '[b]Important[/b]', 'tags' => 'espace, Europe']);
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertCount(2, $this->app->shared->tags->tagsOf((string) $info['id']));
        $content = $this->get('archive/' . $itemId)->decodedJson()['data']['content'];
        $this->assertStringContains('[b]Important[/b]', $content);
        $this->assertStringContains('espace, Europe', $content);
        $this->assertStringContains('Fusée Ariane', $this->get('archives')->decodedJson()['data']['content']);
        $this->assertFalse(str_contains($this->get('list')->decodedJson()['data']['content'], 'Fusée Ariane'), 'une archive quitte le fil');

        // La purge de rétention conserve l'archive et supprime le reste
        $this->app->db->execute("UPDATE news_item SET published_at = '2020-01-01 00:00:00'");
        $context = $this->app->context(Request::create('GET', '/'));
        $this->app->modules->reset();
        $this->app->modules->discover();
        [$module] = $this->app->modules->boot('news', $context);
        $this->assertStringContains('1 entrée(s)', $module->purge());
        $this->assertSame(1, $this->app->db->count('SELECT COUNT(*) FROM news_item'));

        // Service intermodule
        $this->app->acl->setRule('user', $this->userId, AclService::module('news') . '/data/archive', 'read', 'allow');
        $this->app->acl->clearCache();
        $service = $context->moduleService('news');
        $this->assertSame('Fusée Ariane : nouveau lancement', $service->find($itemId)['title']);
        $this->assertCount(1, $service->search('ariane'));
        $names = array_map(static fn (array $t): string => (string) $t["name"], $this->app->shared->tags->tagsOf((string) $info["id"]));
        sort($names);
        $this->assertSame(["Europe", "espace"], $names);

        // Désarchivage : retrait du registre
        $this->assertSame(200, $this->post('unarchive', ['id' => $itemId])->status());
        $this->assertNull($this->app->shared->registry->find('news.archive', (string) $itemId));
        $this->assertNull($service->find($itemId));
    }

    public function testCronFetchesFeedsAndLocalCopies(): void
    {
        $this->post('feed-save', ['url' => 'https://exemple.test/rss', 'refresh_minutes' => 240, 'fetch_content' => 1]);
        $feedId = (int) $this->app->db->scalar('SELECT id FROM news_feed');
        $this->assertSame(240, (int) $this->app->db->scalar('SELECT refresh_minutes FROM news_feed WHERE id = :id', ['id' => $feedId]));
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT fetch_content FROM news_feed WHERE id = :id', ['id' => $feedId]));
        $this->assertSame(422, $this->post('feed-save', ['url' => 'https://autre.test/rss', 'refresh_minutes' => 99999])->status(), 'fréquence bornée à 7 jours');

        // Avertissement de planification absente, puis exécution du hook cron : flux non échu, articles copiés
        $this->assertStringContains('Tâche de fond jamais exécutée', $this->get('feeds')->decodedJson()['data']['content']);
        $context = $this->app->context(Request::create('GET', '/'));
        $this->app->modules->reset();
        $this->app->modules->discover();
        [$module] = $this->app->modules->boot('news', $context);
        $summary = $module->cron();
        $this->assertStringContains('0 flux vérifié(s)', $summary, 'le flux vient d’être récupéré : pas encore échu');
        $this->assertStringContains('1 article(s) copié(s) en local, 1 échec(s)', $summary);
        $a1 = $this->app->db->selectOne("SELECT * FROM news_item WHERE guid = 'a1'");
        $a2 = $this->app->db->selectOne("SELECT * FROM news_item WHERE guid = 'a2'");
        $this->assertSame('ok', $a1['content_status']);
        $this->assertStringContains("## Fusée Ariane : nouveau lancement\n\nLe lanceur européen", (string) $a1['content']);
        $this->assertFalse(str_contains((string) $a1['content'], 'Menu du site'));
        $this->assertSame('error', $a2['content_status']);
        $this->assertStringContains('Aucun texte exploitable', (string) $a2['content_error']);

        // Flux échu : la récupération reprend au prochain cron ; l'état de planification est visible
        $this->app->db->execute("UPDATE news_feed SET last_fetched_at = '2020-01-01 00:00:00'");
        $this->responses['https://exemple.test/rss'] = ['status' => 200, 'headers' => ['ETag' => '"v9"'], 'body' => self::rss([['a3', 'Troisième', 'x', '23 Sep 2026 08:00:00 +0000']])];
        $this->assertStringContains('1 flux vérifié(s), 1 nouvelle(s) entrée(s)', $module->cron());
        $this->app->settings->set('cron.last_run', \Atelier\Support\Clock::utc(), 'core');
        $this->assertStringContains('dernière exécution le', $this->get('feeds')->decodedJson()['data']['content']);

        // Lecture de la copie locale (marque lue) et téléchargement à la demande
        $read = $this->get('read/' . $a1['id'])->decodedJson()['data'];
        $this->assertStringContains('<h3>Fusée Ariane : nouveau lancement</h3>', $read['content']);
        $this->assertStringContains('Le lanceur européen', $read['content']);
        $this->assertSame(1, $this->app->db->count('SELECT COUNT(*) FROM news_read WHERE item_id = :i', ['i' => (int) $a1['id']]));
        $this->assertStringContains('Télécharger maintenant', $this->get('read/' . $a2['id'])->decodedJson()['data']['content']);
        $this->responses['https://exemple.test/a2'] = ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => '<html><body><main><p>Un article désormais complet, avec un paragraphe suffisamment long pour être conservé en copie locale.</p></main></body></html>'];
        $response = $this->post('content-fetch', ['id' => (int) $a2['id']]);
        $this->assertSame('success', $response->decodedJson()['level'], $response->body());
        $this->assertSame('ok', $this->app->db->scalar('SELECT content_status FROM news_item WHERE id = :id', ['id' => (int) $a2['id']]));

        // L'archivage d'une entrée sans copie la télécharge immédiatement
        $a3 = $this->app->db->selectOne("SELECT * FROM news_item WHERE guid = 'a3'");
        $this->responses['https://exemple.test/a3'] = ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => '<html><body><article><p>Troisième article, texte principal assez long pour la copie locale demandée à l’archivage.</p></article></body></html>'];
        $this->assertSame(200, $this->post('archive', ['id' => (int) $a3['id']])->status());
        $this->assertSame('ok', $this->app->db->scalar('SELECT content_status FROM news_item WHERE id = :id', ['id' => (int) $a3['id']]));
        $this->assertStringContains('Troisième article', $this->get('archive/' . $a3['id'])->decodedJson()['data']['content']);
    }

    public function testSuggestedFeedsAreAddedOnce(): void
    {
        $response = $this->post('feed-suggest');
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame(count(NewsModule::SUGGESTED_FEEDS), $response->decodedJson()['data']['added']);
        $this->assertSame(count(NewsModule::SUGGESTED_FEEDS), $this->app->db->count('SELECT COUNT(*) FROM news_feed'));
        $this->assertTrue($this->app->db->count('SELECT COUNT(*) FROM news_category') >= 5);
        $this->assertSame(0, $this->post('feed-suggest')->decodedJson()['data']['added'], 'aucun doublon');
        foreach (NewsModule::SUGGESTED_FEEDS as [$title, $url]) {
            \Atelier\Modules\News\FeedFetcher::assertSafeUrl($url);
        }
    }

    public function testPermissionsAreEnforced(): void
    {
        $bob = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $bob, AclService::module('news'), 'open', 'allow');
        $this->app->acl->clearCache();
        $this->post('feed-save', ['url' => 'https://exemple.test/rss']);
        $itemId = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a1'");
        $this->app->auth->logout();
        $this->app->auth->login('bob', 'Mot-de-passe-solide', '127.0.0.1');
        $this->assertSame(200, $this->get('list')->status());
        $this->assertSame(403, $this->post('archive', ['id' => $itemId])->status());
        $this->assertSame(403, $this->get('feeds')->status());
        $this->assertSame(403, $this->post('feed-save', ['url' => 'https://autre.test/rss'])->status());
        $this->assertSame(403, $this->post('category-save', ['name' => 'X'])->status());
        $this->assertSame(200, $this->post('read', ['id' => $itemId, 'read' => true])->status(), 'la lecture est personnelle');
    }
}
