<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Modules\News\NewsModule;
use Atelier\Security\Acl\AclService;
use Atelier\Testing\TestCase;

/**
 * Module Actualités 1.2.0 : corbeille des flux et des faits archivés (suppression logique, masquage,
 * corbeille globale, restauration, purge, protection des flux porteurs d'archives, rétention).
 */
final class NewsTrashTest extends TestCase
{
    private Application $app;
    private int $userId;

    /** @var array<string, array{status: int, headers: array<string, string>, body: string}> */
    private array $responses = [];

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        foreach (['news', 'trash'] as $module) {
            $this->app->acl->setRule('user', $this->userId, AclService::module($module), 'admin', 'allow');
        }
        $this->app->acl->clearCache();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $this->responses['https://exemple.test/rss'] = ['status' => 200, 'headers' => ['ETag' => '"v1"'], 'body' => self::rss([
            ['a1', 'Fusée Ariane : nouveau lancement', 'Le lanceur européen décolle.', '22 Sep 2026 08:00:00 +0000'],
            ['a2', 'Budget : les débats reprennent', 'Assemblée nationale.', '21 Sep 2026 08:00:00 +0000'],
        ])];
        $this->responses['https://autre.test/rss'] = ['status' => 200, 'headers' => [], 'body' => self::rss([['b1', 'Autre source', 'x', '20 Sep 2026 08:00:00 +0000']])];
        NewsModule::$transport = function (string $url, array $headers, int $timeout): array {
            if (!isset($this->responses[$url])) {
                throw new \RuntimeException('Hôte injoignable (simulation).');
            }
            return $this->responses[$url];
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

    private function post(string $route, array $data = [], string $module = 'news'): array
    {
        $response = $this->app->handle(Request::create('POST', '/m/' . $module . '/' . $route, [], $data, $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function view(string $route, array $query = [], string $module = 'news'): array
    {
        $response = $this->app->handle(Request::create('GET', '/m/' . $module . '/' . $route, $query, [], $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function badge(): int
    {
        return (int) $this->app->handle(Request::create('GET', '/core/badges', [], [], $this->headers()))->decodedJson()['data']['badges']['news']['count'];
    }

    public function testFeedTrashMasksEntriesKeepsArchivesAndIsProtectedByThem(): void
    {
        $feed = (int) $this->post('feed-save', ['url' => 'https://exemple.test/rss'])['data']['id'];
        $other = (int) $this->post('feed-save', ['url' => 'https://autre.test/rss', 'title' => 'Autre'])['data']['id'];
        $a1 = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a1'");
        $this->assertSame(200, $this->post('archive', ['id' => $a1, 'note' => 'À suivre'])['_status']);
        $this->assertSame(2, $this->badge(), 'a2 et b1 non lues (a1 archivée est lue)');

        // Mise en corbeille : hors listes, badge, fil ; archives conservées.
        $deleted = $this->post('feed-delete', ['id' => $feed]);
        $this->assertSame(200, $deleted['_status'], json_encode($deleted));
        $this->assertStringContains('placé dans la corbeille', (string) $deleted['message']);
        $this->assertFalse(str_contains($this->view('feeds')['data']['content'], 'Flux test'));
        $this->assertSame(1, $this->badge());
        $list = $this->view('list', ['norefresh' => 1])['data']['content'];
        $this->assertFalse(str_contains($list, 'Budget'));
        $this->assertStringContains('Autre source', $list);
        $this->assertStringContains('Fusée Ariane', $this->view('archives')['data']['content'], 'les faits archivés d’un flux en corbeille restent consultables');
        $this->assertSame(200, $this->view('archive/' . $a1)['_status']);
        $this->assertSame(404, $this->view('feeds/edit/' . $feed)['_status']);
        $this->assertSame(404, $this->post('feed-refresh', ['id' => $feed])['_status'], 'flux introuvable');
        $recreate = $this->post('feed-save', ['url' => 'https://exemple.test/rss']);
        $this->assertSame(422, $recreate['_status']);
        $this->assertStringContains('corbeille', json_encode($recreate, JSON_UNESCAPED_UNICODE));

        // Tâches de fond et rétention ignorent le flux en corbeille.
        $this->app->db->execute("UPDATE news_feed SET last_fetched_at = '2020-01-01 00:00:00'");
        $context = $this->app->context(Request::create('GET', '/'));
        $this->app->modules->reset();
        $this->app->modules->discover();
        [$module] = $this->app->modules->boot('news', $context);
        $this->assertStringContains('1 flux vérifié(s)', $module->cron(), 'seul le flux vivant est récupéré');
        $this->assertSame(1, $this->app->db->count('SELECT COUNT(*) FROM news_item WHERE feed_id = :f AND is_archived = 0', ['f' => $feed]), 'entrées masquées mais conservées');

        // Vue corbeille du module, corbeille globale, purge bloquée par l'archive (409).
        $trash = $this->view('trash')['data']['content'];
        $this->assertStringContains('Flux test', $trash);
        $this->assertStringContains('1 fait(s) archivé(s) : purge bloquée', $trash);
        $global = $this->view('list', ['source' => 'module', 'module' => 'news'], 'trash')['data']['content'];
        $this->assertStringContains('Flux « Flux test »', $global);
        $this->assertStringContains('Flux suivis', $global, 'nom du jeu de données');
        $blocked = $this->post('purge', ['key' => 'module:news:feed:' . $feed], 'trash');
        $this->assertSame(409, $blocked['_status'], json_encode($blocked));
        $this->assertNotNull($this->app->db->selectOne('SELECT id FROM news_feed WHERE id = :id', ['id' => $feed]));

        // Restauration via la corbeille globale.
        $this->assertSame(200, $this->post('restore', ['source' => 'module', 'module' => 'news', 'id' => 'feed:' . $feed], 'trash')['_status']);
        $this->assertStringContains('Flux test', $this->view('feeds')['data']['content']);
        $this->assertSame(2, $this->badge());

        // Sans fait archivé, la purge définitive efface le flux et ses entrées.
        $this->post('feed-delete', ['id' => $other]);
        $this->assertSame(200, $this->post('trash-purge', ['id' => 'feed:' . $other])['_status']);
        $this->assertNull($this->app->db->selectOne('SELECT id FROM news_feed WHERE id = :id', ['id' => $other]));
        $this->assertSame(0, $this->app->db->count('SELECT COUNT(*) FROM news_item WHERE feed_id = :f', ['f' => $other]));
    }

    /**
     * Non-régression : le compteur d'un centre d'intérêt (badge du fil, page Classement) et le
     * recalcul des correspondances comptaient encore les faits mis en corbeille.
     */
    public function testInterestCountsIgnoreTrashedItems(): void
    {
        $this->assertSame(200, $this->post('interest-save', ['name' => 'Espace', 'keywords' => 'fusée, ariane'])['_status']);
        $this->post('feed-save', ['url' => 'https://exemple.test/rss']);
        $repository = new \Atelier\Modules\News\NewsRepository($this->app->db);
        $interest = $repository->interests()[0];
        $this->assertSame(1, (int) $interest['item_count']);
        $a1 = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a1'");

        // Fait archivé puis mis en corbeille : il quitte le fil, il doit quitter le compteur.
        $this->post('archive', ['id' => $a1]);
        $this->assertSame(200, $this->post('archive-delete', ['id' => $a1])['_status']);
        $this->assertSame(0, (int) $repository->interests()[0]['item_count'], 'un fait en corbeille ne compte plus');
        $rematched = $this->post('interest-save', ['id' => (int) $interest['id'], 'name' => 'Espace', 'keywords' => 'fusée, ariane']);
        $this->assertSame(0, (int) $rematched['data']['matched'], 'le recalcul annonce le même périmètre que le fil');
        $this->assertSame(0, (int) $repository->interests()[0]['item_count']);

        // Restauré, le fait retrouve son centre d'intérêt : le recalcul n'a pas effacé sa correspondance.
        $this->assertSame(200, $this->post('trash-restore', ['id' => 'archive:' . $a1])['_status']);
        $this->assertSame(1, (int) $repository->interests()[0]['item_count']);
        $this->assertStringContains('Fusée Ariane', $this->view('archives', ['interest' => (int) $interest['id']])['data']['content']);
    }

    public function testArchiveTrashRestorePurgeAndRetention(): void
    {
        $feed = (int) $this->post('feed-save', ['url' => 'https://exemple.test/rss'])['data']['id'];
        $a1 = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a1'");
        $a2 = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a2'");
        $this->post('archive', ['id' => $a1, 'note' => 'À suivre']);
        $this->post('archive-save', ['id' => $a1, 'note' => 'Note', 'tags' => 'espace']);
        $info = $this->app->shared->registry->find('news.archive', (string) $a1);
        $this->assertNotNull($info);
        $this->assertSame(404, $this->post('archive-delete', ['id' => $a2])['_status'], 'une entrée non archivée ne va pas à la corbeille');

        // Mise en corbeille : hors archives et service ; registre et tags conservés.
        $deleted = $this->post('archive-delete', ['id' => $a1]);
        $this->assertSame(200, $deleted['_status'], json_encode($deleted));
        $this->assertSame('archives', $deleted['directives']['navigate']);
        $this->assertFalse(str_contains($this->view('archives')['data']['content'], 'Fusée Ariane'));
        $this->assertSame(404, $this->view('archive/' . $a1)['_status']);
        $this->assertSame(404, $this->view('read/' . $a1)['_status']);
        $this->app->acl->setRule('user', $this->userId, AclService::module('news') . '/data/archive', 'read', 'allow');
        $this->app->acl->clearCache();
        $context = $this->app->context(Request::create('GET', '/'));
        $service = $context->moduleService('news');
        $this->assertNull($service->find($a1));
        $this->assertCount(0, $service->search('ariane'));
        $this->assertNotNull($this->app->shared->registry->find('news.archive', (string) $a1), 'registre conservé jusqu’à la purge');
        $this->assertCount(1, $this->app->shared->tags->tagsOf((string) $info['id']));
        $trash = $this->view('trash')['data']['content'];
        $this->assertStringContains('Fusée Ariane', $trash);
        $this->assertStringContains('Fait archivé', $trash);
        $global = $this->view('list', ['source' => 'module', 'module' => 'news'], 'trash')['data']['content'];
        $this->assertStringContains('Fait archivé « Fusée Ariane : nouveau lancement » — Flux test', $global);
        $this->assertStringContains('Actualités archivées', $global);

        // Restauration depuis la vue du module.
        $this->assertSame(200, $this->post('trash-restore', ['id' => 'archive:' . $a1])['_status']);
        $this->assertStringContains('Fusée Ariane', $this->view('archives')['data']['content']);
        $this->assertSame('Fusée Ariane : nouveau lancement', $service->find($a1)['title']);

        // Droits : un utilisateur sans « archive » ne restaure pas un fait, sans « delete » ne purge pas.
        $bob = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $bob, AclService::module('news'), 'update', 'allow');
        $this->app->acl->setRule('user', $bob, AclService::module('trash'), 'open', 'allow');
        $this->app->acl->clearCache();
        $this->post('archive-delete', ['id' => $a1]);
        $this->app->auth->logout();
        $this->app->auth->login('bob', 'Mot-de-passe-solide', '127.0.0.1');
        $this->assertSame(403, $this->post('restore', ['key' => 'module:news:archive:' . $a1], 'trash')['_status']);
        $this->assertSame(403, $this->post('purge', ['key' => 'module:news:archive:' . $a1], 'trash')['_status']);
        $this->assertSame(403, $this->view('trash')['_status']);
        $this->app->auth->logout();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');

        // Purge définitive via la corbeille globale : ligne et registre effacés, flux intact.
        $purged = $this->post('purge', ['source' => 'module', 'module' => 'news', 'id' => 'archive:' . $a1], 'trash');
        $this->assertSame(200, $purged['_status'], json_encode($purged));
        $this->assertNull($this->app->db->selectOne('SELECT id FROM news_item WHERE id = :id', ['id' => $a1]));
        $this->assertNull($this->app->shared->registry->find('news.archive', (string) $a1));
        $this->assertNotNull($this->app->db->selectOne('SELECT id FROM news_feed WHERE id = :id', ['id' => $feed]));

        // Rétention : un fait expiré est purgé par le hook, un flux expiré porteur d'archives est conservé.
        $this->post('archive', ['id' => $a2]);
        $this->post('archive-delete', ['id' => $a2]);
        $this->post('feed-delete', ['id' => $feed]);
        $this->app->db->execute("UPDATE news_item SET deleted_at = '2026-01-01 00:00:00' WHERE id = :id", ['id' => $a2]);
        $this->app->db->execute("UPDATE news_feed SET deleted_at = '2026-01-01 00:00:00' WHERE id = :id", ['id' => $feed]);
        $this->app->modules->reset();
        $this->app->modules->discover();
        [$module] = $this->app->modules->boot('news', $this->app->context(Request::create('GET', '/')));
        $summary = $module->purge();
        $this->assertStringContains('1 flux conservé(s)', $summary, 'a2 est encore archivé (en corbeille) au moment du tour des flux');
        $this->assertStringContains('1 fait(s) archivé(s) purgés', $summary);
        $this->assertNull($this->app->db->selectOne('SELECT id FROM news_item WHERE id = :id', ['id' => $a2]));
        $this->assertNotNull($this->app->db->selectOne('SELECT id FROM news_feed WHERE id = :id', ['id' => $feed]));
        $this->assertStringContains('1 flux et 0 fait(s) archivé(s) purgés', $module->purge(), 'second passage : le flux n’a plus de fait archivé');
        $this->assertNull($this->app->db->selectOne('SELECT id FROM news_feed WHERE id = :id', ['id' => $feed]), 'le flux, sans archive désormais, est purgé au second passage');
    }

    /** @return list<string> identifiants locaux des faits archivés portant le tag */
    private function taggedArchives(string $tag): array
    {
        $tagId = (int) $this->app->shared->tags->findOrCreate($tag)['id'];
        $rows = array_filter($this->app->shared->tags->infosWithTag($tagId), static fn (array $r): bool => $r['dataset_code'] === 'news.archive');
        return array_values(array_map(static fn (array $r): string => (string) $r['local_key'], $rows));
    }

    /**
     * Non-régression : un fait archivé mis à la corbeille restait listé sous ses tags (Explorateur,
     * module Tags) avec un lien menant à une erreur 404. Un flux en corbeille, lui, laisse ses faits
     * archivés consultables : ils restent donc visibles sous leurs tags (pas de cascade).
     */
    public function testTrashedArchiveLeavesTagListingsAndComesBackOnRestore(): void
    {
        $feed = (int) $this->post('feed-save', ['url' => 'https://exemple.test/rss'])['data']['id'];
        $a1 = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a1'");
        $this->post('archive', ['id' => $a1]);
        $this->assertSame(200, $this->post('archive-save', ['id' => $a1, 'note' => 'Lancement', 'tags' => 'espace, ariane'])['_status']);
        $this->assertSame([(string) $a1], $this->taggedArchives('espace'));

        $this->assertSame(200, $this->post('archive-delete', ['id' => $a1])['_status']);
        $this->assertSame([], $this->taggedArchives('espace'), 'fait archivé en corbeille écarté des tags');
        $this->assertTrue($this->app->shared->registry->isTrashed('news.archive', (string) $a1));

        $this->assertSame(200, $this->post('trash-restore', ['id' => 'archive:' . $a1])['_status']);
        $this->assertSame([(string) $a1], $this->taggedArchives('espace'), 'restauré par la corbeille du module');

        // Corbeille globale
        $this->post('archive-delete', ['id' => $a1]);
        $restored = $this->post('restore', ['key' => 'module:news:archive:' . $a1], 'trash');
        $this->assertSame(200, $restored['_status'], json_encode($restored));
        $this->assertSame([(string) $a1], $this->taggedArchives('espace'), 'restauré par la corbeille globale');
        $this->assertFalse($this->app->shared->registry->isTrashed('news.archive', (string) $a1));

        // Flux en corbeille : ses faits archivés restent consultables, donc visibles sous leurs tags.
        $this->assertSame(200, $this->post('feed-delete', ['id' => $feed])['_status']);
        $this->assertSame([(string) $a1], $this->taggedArchives('espace'), 'le fait archivé d’un flux en corbeille reste visible');
        $this->assertSame(200, $this->view('archive/' . $a1)['_status']);
    }

    /** La fiche d'un fait archivé n'affiche plus une page reliée mise à la corbeille ; elle revient à la restauration. */
    public function testArchiveShowHidesTrashedRelatedPage(): void
    {
        $this->app->acl->setRule('user', $this->userId, AclService::module('wiki'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $this->post('feed-save', ['url' => 'https://exemple.test/rss']);
        $a1 = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a1'");
        $this->post('archive', ['id' => $a1]);
        $pageId = (int) $this->post('save', ['title' => 'Dossier Ariane 6', 'content' => 'Synthèse.'], 'wiki')['data']['id'];
        $archiveInfo = (string) $this->app->shared->registry->find('news.archive', (string) $a1)['id'];
        $pageInfo = (string) $this->app->shared->registry->find('wiki.page', (string) $pageId)['id'];
        $this->app->shared->relations->relate('references', $pageInfo, $archiveInfo, $this->userId);
        $show = fn (): string => (string) $this->view('archive/' . $a1)['data']['content'];
        $this->assertStringContains('Dossier Ariane 6', $show());

        $this->post('delete', ['id' => $pageId], 'wiki');
        $this->assertFalse(str_contains($show(), 'Dossier Ariane 6'), 'page en corbeille absente de la fiche');
        $this->post('restore', ['id' => $pageId], 'wiki');
        $this->assertStringContains('Dossier Ariane 6', $show());
    }

    /** La migration de rattrapage marque les faits archivés et les flux déjà en corbeille, et se rejoue sans effet. */
    public function testCatchUpMigrationMarksExistingTrash(): void
    {
        $feed = (int) $this->post('feed-save', ['url' => 'https://exemple.test/rss'])['data']['id'];
        $a1 = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a1'");
        $a2 = (int) $this->app->db->scalar("SELECT id FROM news_item WHERE guid = 'a2'");
        $this->post('archive', ['id' => $a1]);
        $this->post('archive', ['id' => $a2]);
        // Un flux n'est pas inscrit par le module ; on simule une inscription pour vérifier son rattrapage.
        $this->app->shared->registry->register('news.feed', (string) $feed, 'Flux test');
        $this->app->db->update('news_item', ['deleted_at' => '2026-09-01 10:00:00'], 'id = :id', ['id' => $a1]);
        $this->app->db->update('news_feed', ['deleted_at' => '2026-09-02 10:00:00'], 'id = :id', ['id' => $feed]);

        $migration = require dirname(__DIR__, 2) . '/modules/news/migrations/004_registry_trash.php';
        $migration($this->app->db);
        $registry = $this->app->shared->registry;
        $this->assertSame('2026-09-01 10:00:00', $registry->find('news.archive', (string) $a1)['trashed_at']);
        $this->assertNull($registry->find('news.archive', (string) $a2)['trashed_at'], 'le fait archivé d’un flux en corbeille reste visible');
        $this->assertSame('2026-09-02 10:00:00', $registry->find('news.feed', (string) $feed)['trashed_at']);
        $migration($this->app->db);
        $this->assertSame('2026-09-01 10:00:00', $registry->find('news.archive', (string) $a1)['trashed_at'], 'rejouable sans effet');
    }
}
