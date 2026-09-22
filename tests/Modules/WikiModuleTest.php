<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Testing\TestCase;

/**
 * Module Pages : création, liens internes et rétroliens, fichiers et lieux, versions, corbeille, service.
 */
final class WikiModuleTest extends TestCase
{
    private Application $app;
    private int $userId;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $this->userId, AclService::module('wiki'), 'admin', 'allow');
        $this->app->acl->setRule('user', $this->userId, AclService::module('geo'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
    }

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    private function post(string $module, string $route, array $data = []): \Atelier\Http\Response
    {
        return $this->app->handle(Request::create('POST', '/m/' . $module . '/' . $route, [], $data, $this->headers()));
    }

    private function view(string $route, array $query = []): array
    {
        $response = $this->app->handle(Request::create('GET', '/m/wiki/' . $route, $query, [], $this->headers()));
        $this->assertSame(200, $response->status(), $response->body());
        return $response->decodedJson()['data'];
    }

    public function testPagesLinksFilesPointsAndVersions(): void
    {
        $point = (int) $this->post('geo', 'save', ['name' => 'Atelier', 'code' => 'HQ', 'coordinates' => '48.87, 2.33'])->decodedJson()['data']['id'];

        $response = $this->post('wiki', 'save', ['title' => 'Procédure d’accueil', 'content' => "[h2]Étapes[/h2]\nVoir [[Sécurité]] et [[Accueil|la page d’accueil]]. <script>x</script>", 'tags' => 'procédure, RH', 'points' => [$point]]);
        $this->assertSame(200, $response->status(), $response->body());
        $data = $response->decodedJson()['data'];
        $this->assertSame('procedure-d-accueil', $data['slug']);
        $this->assertSame(1, $data['revision']);
        $pageId = (int) $data['id'];

        $show = $this->view('show/procedure-d-accueil');
        $content = $show['content'];
        $this->assertStringContains('wiki__link--missing', $content, 'lien vers une page inexistante');
        $this->assertStringContains('data-route="new?title=S%C3%A9curit%C3%A9"', $content);
        $this->assertStringContains('&lt;script&gt;', $content, 'le HTML saisi est échappé');
        $this->assertStringContains('Atelier [HQ]', $content, 'lieu rattaché affiché');
        $this->assertStringContains('procédure', $content);
        $this->assertStringContains('<h2', $content);

        // Doublon de titre refusé, puis création de la page cible : le lien devient valide et le rétrolien apparaît
        $this->assertSame(422, $this->post('wiki', 'save', ['title' => 'procédure d’accueil'])->status());
        $this->assertSame(200, $this->post('wiki', 'save', ['title' => 'Sécurité', 'content' => 'Consignes.'])->status());
        $content = $this->view('show/procedure-d-accueil')['content'];
        $this->assertFalse(str_contains($content, 'new?title=S%C3%A9curit%C3%A9'));
        $this->assertStringContains('data-route="show/securite"', $content);
        $this->assertStringContains('Procédure d’accueil', $this->view('show/securite')['content'], 'rétrolien');

        // Fichier joint inséré comme image ; point GPS inséré
        $infoId = (string) $this->app->shared->registry->find('wiki.page', (string) $pageId)['id'];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $file = $this->app->shared->attachments->storeContent($png, 'pixel.png', $infoId, $this->userId);
        $response = $this->post('wiki', 'save', ['id' => $pageId, 'title' => 'Procédure d’accueil', 'content' => 'Photo : [file=' . $file['id'] . '|Le pixel|200] Lieu : [point=' . $point . ']', 'tags' => 'procédure', 'points' => [$point]]);
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame(2, $response->decodedJson()['data']['revision']);
        $content = $this->view('show/procedure-d-accueil')['content'];
        $this->assertStringContains('<img class="wiki__image" src="/files/' . $file['id'] . '?inline=1" alt="Le pixel"', $content);
        $this->assertStringContains('max-width:200px', $content);
        $this->assertStringContains('data-open-route="show/' . $point . '"', $content);
        $this->assertStringContains('pixel.png', $content, 'pièce jointe listée');

        // Le point connaît la page (relation located_at) ; retirer le lieu à l'enregistrement suivant
        $context = $this->app->context(Request::create('GET', '/'));
        $this->app->modules->reset();
        $this->app->modules->discover();
        $geo = $context->moduleService('geo');
        $this->assertSame('Procédure d’accueil', $geo->infosAt($point)[0]['label']);
        $this->post('wiki', 'save', ['id' => $pageId, 'title' => 'Procédure d’accueil', 'content' => 'Sans lieu', 'points' => []]);
        $this->assertCount(0, $geo->infosAt($point));

        // Historique et restauration
        $history = $this->view('history/' . $pageId)['content'];
        $this->assertStringContains('courante', $history);
        $this->assertStringContains('Photo', $this->view('revision/' . $pageId . '/2')['content']);
        $response = $this->post('wiki', 'restore-revision', ['id' => $pageId, 'revision' => 1]);
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame(4, $response->decodedJson()['data']['revision']);
        $this->assertStringContains('Étapes', $this->view('show/procedure-d-accueil')['content']);

        // Liste, recherche et lookup
        $list = $this->view('list', ['q' => 'consignes'])['content'];
        $this->assertStringContains('Sécurité', $list);
        $this->assertFalse(str_contains($list, 'Procédure'));
        $items = $this->app->handle(Request::create('GET', '/m/wiki/lookup', ['q' => 'proc'], [], $this->headers()))->decodedJson()['data']['items'];
        $this->assertSame('procedure-d-accueil', $items[0]['slug']);

        // Service intermodule
        $this->app->acl->setRule('user', $this->userId, AclService::module('wiki') . '/data/page', 'read', 'allow');
        $this->app->acl->clearCache();
        $service = $context->moduleService('wiki');
        $this->assertSame('Procédure d’accueil', $service->findBySlug('procedure-d-accueil')['title']);
        $this->assertStringContains('Étapes', $service->find($pageId)['excerpt']);

        // Corbeille
        $this->assertSame(200, $this->post('wiki', 'delete', ['id' => $pageId])->status());
        $this->assertSame(404, $this->app->handle(Request::create('GET', '/m/wiki/show/procedure-d-accueil', [], [], $this->headers()))->status());
        $this->assertStringContains('Procédure', $this->view('trash')['content']);
        $this->assertSame(200, $this->post('wiki', 'restore', ['id' => $pageId])->status());
        $this->assertSame(200, $this->app->handle(Request::create('GET', '/m/wiki/show/procedure-d-accueil', [], [], $this->headers()))->status());
        $this->post('wiki', 'delete', ['id' => $pageId]);
        $this->assertSame(200, $this->post('wiki', 'purge', ['id' => $pageId])->status());
        $this->assertNull($this->app->shared->registry->find('wiki.page', (string) $pageId));
        $this->assertSame(0, $this->app->db->count('SELECT COUNT(*) FROM wiki_revision WHERE page_id = :p', ['p' => $pageId]));
    }

    /** Corbeille globale : trashItems() après suppression, restauration puis purge via le contrat TrashProviderInterface. */
    public function testGlobalTrashProviderListsRestoresAndPurges(): void
    {
        $this->app->acl->setRule('user', $this->userId, AclService::module('trash'), 'open', 'allow');
        $this->app->acl->clearCache();
        $pageId = (int) $this->post('wiki', 'save', ['title' => 'Compte rendu', 'content' => 'Texte'])->decodedJson()['data']['id'];

        $module = $this->app->modules->instance('wiki');
        $module->boot($this->app->context(Request::create('GET', '/')));
        $this->assertTrue($module instanceof \Atelier\Modules\TrashProviderInterface);
        $this->assertSame([], $module->trashItems(), 'rien en corbeille avant suppression');

        $this->assertSame(200, $this->post('wiki', 'delete', ['id' => $pageId])->status());
        $items = $module->trashItems();
        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertSame((string) $pageId, $item['id']);
        $this->assertSame('Compte rendu', $item['label']);
        $this->assertSame('wiki.page', $item['dataset']);
        $this->assertNull($item['deleted_by']);
        $this->assertTrue($item['can_restore']);
        $this->assertTrue($item['can_purge']);
        $this->assertTrue(strtotime($item['purge_at'] . ' UTC') > strtotime($item['deleted_at'] . ' UTC'), 'purge prévue après la suppression');

        // Le module Corbeille agrège la page
        $list = $this->app->handle(Request::create('GET', '/m/trash/list', ['source' => 'module', 'module' => 'wiki'], [], $this->headers()));
        $this->assertSame(200, $list->status(), $list->body());
        $this->assertStringContains('Compte rendu', $list->decodedJson()['data']['content']);

        // Restauration via le contrat : la page redevient lisible, la corbeille est vide
        $module->restoreTrashItem((string) $pageId);
        $this->assertSame([], $module->trashItems());
        $this->view('show/compte-rendu');
        $this->assertThrows(\Atelier\Error\NotFoundException::class, fn () => $module->restoreTrashItem((string) $pageId));

        // Purge via le contrat : page, versions et inscription au registre disparaissent
        $this->post('wiki', 'delete', ['id' => $pageId]);
        $module->purgeTrashItem((string) $pageId);
        $this->assertSame([], $module->trashItems());
        $this->assertSame(404, $this->app->handle(Request::create('GET', '/m/wiki/show/compte-rendu', [], [], $this->headers()))->status());
        $this->assertNull($this->app->shared->registry->find('wiki.page', (string) $pageId));
        $this->assertSame(0, $this->app->db->count('SELECT COUNT(*) FROM wiki_revision WHERE page_id = :p', ['p' => $pageId]));
        $this->assertThrows(\Atelier\Error\NotFoundException::class, fn () => $module->purgeTrashItem((string) $pageId));
    }

    public function testReaderCannotEdit(): void
    {
        $bob = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $bob, AclService::module('wiki'), 'open', 'allow');
        $this->app->acl->clearCache();
        $this->post('wiki', 'save', ['title' => 'Lecture', 'content' => 'Texte']);
        $this->app->auth->logout();
        $this->app->auth->login('bob', 'Mot-de-passe-solide', '127.0.0.1');
        $show = $this->view('show/lecture');
        $this->assertStringContains('Texte', $show['content']);
        $this->assertFalse(str_contains($show['banner'], 'data-route="edit/'));
        $this->assertSame(403, $this->post('wiki', 'save', ['title' => 'Autre'])->status());
        $this->assertSame(403, $this->app->handle(Request::create('GET', '/m/wiki/new', [], [], $this->headers()))->status());
    }
}
