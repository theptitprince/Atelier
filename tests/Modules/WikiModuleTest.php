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

    /**
     * Non-régression : le compteur de rétroliens de la liste comptait aussi les pages en corbeille,
     * alors que la fiche n'en affiche que les pages actives.
     */
    public function testBacklinkCounterIgnoresTrashedPages(): void
    {
        $this->post('wiki', 'save', ['title' => 'Cible', 'content' => 'Page cible.']);
        $this->post('wiki', 'save', ['title' => 'Source vivante', 'content' => 'Voir [[Cible]].']);
        $doomed = (int) $this->post('wiki', 'save', ['title' => 'Source supprimée', 'content' => 'Voir [[Cible]].'])->decodedJson()['data']['id'];

        $repository = new \Atelier\Modules\Wiki\WikiRepository($this->app->db);
        $counter = static function () use ($repository): int {
            foreach ($repository->paginate('Cible', 1, 10)['rows'] as $row) {
                if ((string) $row['slug'] === 'cible') {
                    return (int) $row['backlinks'];
                }
            }
            return -1;
        };
        $this->assertSame(2, $counter());
        $this->assertCount(2, $repository->backlinks('cible'));

        $this->assertSame(200, $this->post('wiki', 'delete', ['id' => $doomed])->status());
        $this->assertCount(1, $repository->backlinks('cible'), 'la page en corbeille ne pointe plus');
        $this->assertSame(1, $counter(), 'le compteur de la liste suit la fiche');

        $this->assertSame(200, $this->post('wiki', 'restore', ['id' => $doomed])->status());
        $this->assertSame(2, $counter());
    }

    /** Non-régression : un numéro de page démesuré débordait l'entier et cassait la clause OFFSET (erreur 500). */
    public function testHugePageNumberReturnsAnEmptyPageInsteadOfAnError(): void
    {
        $this->post('wiki', 'save', ['title' => 'Une page', 'content' => 'Texte']);
        foreach (['0', '-3', '9223372036854775807', '999999999999999999'] as $page) {
            $response = $this->app->handle(Request::create('GET', '/m/wiki/list', ['page' => $page], [], $this->headers()));
            $this->assertSame(200, $response->status(), 'page=' . $page . ' : ' . $response->body());
        }
    }

    /**
     * Non-régression : le rendu révélait le nom et la taille d'une pièce jointe, ainsi que le
     * libellé et les coordonnées d'un point GPS, sans vérifier les droits du lecteur. Il suffisait
     * d'en citer l'identifiant dans une page pour les faire apparaître à n'importe qui.
     */
    public function testRestrictedFilesAndPointsAreHiddenFromOtherReaders(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $file = $this->app->shared->attachments->storeContent($png, 'plan-confidentiel.png', null, $this->userId);
        $pointId = (int) $this->post('geo', 'save', ['name' => 'Entrepôt secret', 'coordinates' => '48.85, 2.35'])->decodedJson()['data']['id'];
        $this->post('wiki', 'save', ['title' => 'Note de service', 'content' => 'Plan : [file=' . $file['id'] . '] Lieu : [point=' . $pointId . ']']);

        // Alice a déposé le fichier et administre le module Coordonnées GPS : elle voit tout.
        $vuAlice = $this->view('show/note-de-service')['content'];
        $this->assertStringContains('plan-confidentiel.png', $vuAlice);
        $this->assertStringContains('Entrepôt secret', $vuAlice);

        // Bob lit la même page sans droit sur le fichier ni sur les points GPS.
        $bob = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $bob, AclService::module('wiki'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $this->app->auth->logout();
        $_SESSION = [];
        $this->app->auth->login('bob', 'Mot-de-passe-solide', '127.0.0.1');

        $vuBob = $this->view('show/note-de-service')['content'];
        $this->assertFalse(str_contains($vuBob, 'plan-confidentiel.png'), 'le nom du fichier ne doit pas fuiter');
        $this->assertFalse(str_contains($vuBob, 'Entrepôt secret'), 'le libellé du point ne doit pas fuiter');
        $this->assertStringContains('absent', $vuBob);
        $this->assertStringContains('indisponible', $vuBob);
    }

    /** @return list<string> identifiants locaux des informations d'un jeu portant le tag */
    private function taggedKeys(string $tag, string $dataset): array
    {
        $tagId = (int) $this->app->shared->tags->findOrCreate($tag)['id'];
        $rows = array_filter($this->app->shared->tags->infosWithTag($tagId), static fn (array $r): bool => $r['dataset_code'] === $dataset);
        return array_values(array_map(static fn (array $r): string => (string) $r['local_key'], $rows));
    }

    /**
     * Non-régression : une page mise à la corbeille restait listée sous ses tags et dans les
     * éléments liés des autres modules (fiche d'un projet), avec un lien menant à une erreur 404.
     */
    public function testTrashedPageLeavesTagListingsAndComesBackOnRestore(): void
    {
        $id = (int) $this->post('wiki', 'save', ['title' => 'Pain au levain', 'content' => 'Recette.', 'tags' => 'recette, cuisine'])->decodedJson()['data']['id'];
        $this->assertSame([(string) $id], $this->taggedKeys('recette', 'wiki.page'));

        $this->assertSame(200, $this->post('wiki', 'delete', ['id' => $id])->status());
        $this->assertSame([], $this->taggedKeys('recette', 'wiki.page'), 'page en corbeille écartée des tags');
        $this->assertTrue($this->app->shared->registry->isTrashed('wiki.page', (string) $id));

        $this->assertSame(200, $this->post('wiki', 'restore', ['id' => $id])->status());
        $this->assertSame([(string) $id], $this->taggedKeys('recette', 'wiki.page'), 'restaurée par l’action du module');

        $this->post('wiki', 'delete', ['id' => $id]);
        $module = $this->app->modules->instance('wiki');
        $module->boot($this->app->context(Request::create('GET', '/')));
        $module->restoreTrashItem((string) $id);
        $this->assertSame([(string) $id], $this->taggedKeys('recette', 'wiki.page'), 'restaurée par la corbeille globale');
        $this->assertFalse($this->app->shared->registry->isTrashed('wiki.page', (string) $id));
    }

    /** La fiche d'une page n'affiche ni un projet relié ni un lieu mis à la corbeille ; ils reviennent à la restauration. */
    public function testShowHidesTrashedRelatedProjectAndPoint(): void
    {
        $this->app->acl->setRule('user', $this->userId, AclService::module('project'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $pointId = (int) $this->post('geo', 'save', ['name' => 'Moulin de la Galette', 'coordinates' => '48.8874, 2.3371'])->decodedJson()['data']['id'];
        $pageId = (int) $this->post('wiki', 'save', ['title' => 'Balade à Montmartre', 'content' => 'Itinéraire.', 'points' => [$pointId]])->decodedJson()['data']['id'];
        $projectId = (int) $this->post('project', 'save', ['title' => 'Week-end à Paris'])->decodedJson()['data']['id'];
        $pageInfo = (string) $this->app->shared->registry->find('wiki.page', (string) $pageId)['id'];
        $this->assertSame(200, $this->post('project', 'link', ['id' => $projectId, 'to' => $pageInfo, 'type' => 'related'])->status());

        $show = fn (): string => (string) $this->view('page/' . $pageId)['content'];
        $this->assertStringContains('Week-end à Paris', $show());
        $this->assertStringContains('Moulin de la Galette', $show());

        $this->post('project', 'delete', ['id' => $projectId]);
        $this->post('geo', 'delete', ['id' => $pointId]);
        $this->assertFalse(str_contains($show(), 'Week-end à Paris'), 'projet en corbeille absent de la fiche');
        $this->assertFalse(str_contains($show(), 'Moulin de la Galette'), 'lieu en corbeille absent de la fiche');

        // Enregistrer la page pendant que le lieu est en corbeille ne doit pas rompre le rattachement.
        $this->assertSame(200, $this->post('wiki', 'save', ['id' => $pageId, 'title' => 'Balade à Montmartre', 'content' => 'Itinéraire revu.'])->status());

        $this->post('project', 'restore', ['id' => $projectId]);
        $this->post('geo', 'restore', ['id' => $pointId]);
        $this->assertStringContains('Week-end à Paris', $show());
        $this->assertStringContains('Moulin de la Galette', $show(), 'le lieu revient avec son rattachement');
    }

    /** La migration de rattrapage marque au registre les pages déjà en corbeille, et se rejoue sans effet. */
    public function testCatchUpMigrationMarksExistingTrash(): void
    {
        $trashed = (int) $this->post('wiki', 'save', ['title' => 'Ancienne', 'content' => 'x'])->decodedJson()['data']['id'];
        $alive = (int) $this->post('wiki', 'save', ['title' => 'Vivante', 'content' => 'x'])->decodedJson()['data']['id'];
        $this->app->db->update('wiki_page', ['deleted_at' => '2026-09-01 10:00:00'], 'id = :id', ['id' => $trashed]);
        $this->assertFalse($this->app->shared->registry->isTrashed('wiki.page', (string) $trashed));

        $migration = require dirname(__DIR__, 2) . '/modules/wiki/migrations/002_registry_trash.php';
        $migration($this->app->db);
        $this->assertSame('2026-09-01 10:00:00', $this->app->shared->registry->find('wiki.page', (string) $trashed)['trashed_at']);
        $this->assertNull($this->app->shared->registry->find('wiki.page', (string) $alive)['trashed_at']);
        $migration($this->app->db);
        $this->assertSame('2026-09-01 10:00:00', $this->app->shared->registry->find('wiki.page', (string) $trashed)['trashed_at'], 'rejouable sans effet');
    }
}
