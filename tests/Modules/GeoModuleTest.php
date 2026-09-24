<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Error\ForbiddenException;
use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Testing\TestCase;

/**
 * Module Coordonnées GPS : routes, droits, registre commun et service intermodule.
 */
final class GeoModuleTest extends TestCase
{
    private Application $app;
    private int $userId;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
    }

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    private function allowAll(): void
    {
        $this->app->acl->setRule('user', $this->userId, AclService::module('geo'), 'admin', 'allow');
        $this->app->acl->clearCache();
    }

    public function testModuleIsDiscoveredAndLockedWithoutRights(): void
    {
        $descriptor = $this->app->modules->get('geo');
        $this->assertNotNull($descriptor);
        $this->assertTrue($descriptor->isUsable(), implode(' ', $descriptor->errors));
        $response = $this->app->handle(Request::create('GET', '/m/geo/list', [], [], $this->headers()));
        $this->assertSame(403, $response->status());
        $this->assertNotNull($this->app->shared->catalog->findShared('geo.point'), 'le jeu geo.point est catalogué comme partagé');
    }

    public function testCreateListShowAndSearchNearby(): void
    {
        $this->allowAll();
        $response = $this->app->handle(Request::create('POST', '/m/geo/save', [], ['name' => 'Tour Eiffel', 'code' => 'TE', 'coordinates' => '48°51\'30"N 2°17\'40"E', 'altitude' => '330', 'address' => 'Paris'], $this->headers()));
        $this->assertSame(200, $response->status(), $response->body());
        $id = (int) $response->decodedJson()['data']['id'];
        $this->assertTrue($id > 0);
        $this->assertSame('show/' . $id, $response->decodedJson()['directives']['navigate']);

        $response = $this->app->handle(Request::create('POST', '/m/geo/save', [], ['name' => 'Notre-Dame', 'coordinates' => '48.852968, 2.349902'], $this->headers()));
        $this->assertSame(200, $response->status(), $response->body());
        $response = $this->app->handle(Request::create('POST', '/m/geo/save', [], ['name' => 'Fourvière', 'coordinates' => '45.762365, 4.822621'], $this->headers()));
        $this->assertSame(200, $response->status(), $response->body());

        // Validation : code dupliqué et coordonnées illisibles
        $response = $this->app->handle(Request::create('POST', '/m/geo/save', [], ['name' => 'Doublon', 'code' => 'TE', 'coordinates' => 'nulle part'], $this->headers()));
        $this->assertSame(422, $response->status());
        $fields = $response->decodedJson()['error']['fields'];
        $this->assertStringContains('déjà utilisé', $fields['code']);
        $this->assertTrue(isset($fields['coordinates']));

        // Liste et fiche
        $response = $this->app->handle(Request::create('GET', '/m/geo/list', ['q' => 'eiffel'], [], $this->headers()));
        $this->assertSame(200, $response->status());
        $data = $response->decodedJson()['data'];
        $this->assertStringContains('Tour Eiffel', $data['content']);
        $this->assertStringContains('module-geo', $data['content']);
        $this->assertFalse(str_contains($data['content'], 'Fourvière'));

        $response = $this->app->handle(Request::create('GET', '/m/geo/show/' . $id, [], [], $this->headers()));
        $this->assertSame(200, $response->status());
        $this->assertStringContains('48°51′30.0″N 2°17′40.0″E', $response->decodedJson()['data']['content']);
        $this->assertStringContains('Notre-Dame', $response->decodedJson()['data']['content'], 'point proche affiché');

        // Recherche par coordonnées : points proches triés par distance, Lyon exclu (rayon 25 km)
        $response = $this->app->handle(Request::create('GET', '/m/geo/list', ['q' => '48.86, 2.34'], [], $this->headers()));
        $content = $response->decodedJson()['data']['content'];
        $this->assertStringContains('Points à proximité', $content);
        $this->assertTrue(strpos($content, 'Notre-Dame') < strpos($content, 'Tour Eiffel'), 'Notre-Dame est plus proche du centre recherché');
        $this->assertFalse(str_contains($content, 'Fourvière'));

        // Recherche JSON pour les sélecteurs
        $response = $this->app->handle(Request::create('GET', '/m/geo/lookup', ['q' => 'notre'], [], $this->headers()));
        $items = $response->decodedJson()['data']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Notre-Dame', $items[0]['label']);

        // Registre commun
        $info = $this->app->shared->registry->find('geo.point', (string) $id);
        $this->assertNotNull($info);
        $this->assertSame('Tour Eiffel [TE]', $info['label']);

        // Export CSV
        $this->app->acl->setRule('user', $this->userId, AclService::module('geo') . '/action/export', 'export', 'allow');
        $this->app->acl->clearCache();
        $response = $this->app->handle(Request::create('GET', '/m/geo/export.csv', [], [], $this->headers()));
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertStringContains('Tour Eiffel', $response->body());
        $this->assertStringContains('48.858333', $response->body());
    }

    public function testTrashRestoreAndPurgeUnregisterInfo(): void
    {
        $this->allowAll();
        $response = $this->app->handle(Request::create('POST', '/m/geo/save', [], ['name' => 'Temporaire', 'coordinates' => '1, 1'], $this->headers()));
        $id = (int) $response->decodedJson()['data']['id'];
        $this->assertSame(200, $this->app->handle(Request::create('POST', '/m/geo/delete', [], ['id' => $id], $this->headers()))->status());
        $this->assertSame(404, $this->app->handle(Request::create('GET', '/m/geo/show/' . $id, [], [], $this->headers()))->status());
        $this->assertStringContains('Temporaire', $this->app->handle(Request::create('GET', '/m/geo/trash', [], [], $this->headers()))->decodedJson()['data']['content']);
        $this->assertSame(200, $this->app->handle(Request::create('POST', '/m/geo/restore', [], ['id' => $id], $this->headers()))->status());
        $this->assertSame(200, $this->app->handle(Request::create('GET', '/m/geo/show/' . $id, [], [], $this->headers()))->status());
        $this->app->handle(Request::create('POST', '/m/geo/delete', [], ['id' => $id], $this->headers()));
        $this->assertSame(200, $this->app->handle(Request::create('POST', '/m/geo/purge', [], ['id' => $id], $this->headers()))->status());
        $this->assertNull($this->app->shared->registry->find('geo.point', (string) $id));
    }

    /** Corbeille globale : trashItems() après suppression, restauration puis purge via le contrat TrashProviderInterface. */
    public function testGlobalTrashProviderListsRestoresAndPurges(): void
    {
        $this->allowAll();
        $this->app->acl->setRule('user', $this->userId, AclService::module('trash'), 'open', 'allow');
        $this->app->acl->clearCache();
        $id = (int) $this->app->handle(Request::create('POST', '/m/geo/save', [], ['name' => 'Phare', 'code' => 'PH', 'coordinates' => '48.04, -4.74'], $this->headers()))->decodedJson()['data']['id'];

        $module = $this->app->modules->instance('geo');
        $module->boot($this->app->context(Request::create('GET', '/')));
        $this->assertTrue($module instanceof \Atelier\Modules\TrashProviderInterface);
        $this->assertSame([], $module->trashItems(), 'rien en corbeille avant suppression');

        $this->assertSame(200, $this->app->handle(Request::create('POST', '/m/geo/delete', [], ['id' => $id], $this->headers()))->status());
        $items = $module->trashItems();
        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertSame((string) $id, $item['id']);
        $this->assertSame('Phare [PH]', $item['label']);
        $this->assertSame('geo.point', $item['dataset']);
        $this->assertNull($item['deleted_by']);
        $this->assertTrue($item['can_restore']);
        $this->assertTrue($item['can_purge']);
        $this->assertTrue(strtotime($item['purge_at'] . ' UTC') > strtotime($item['deleted_at'] . ' UTC'), 'purge prévue après la suppression');

        // Le module Corbeille agrège le point
        $list = $this->app->handle(Request::create('GET', '/m/trash/list', ['source' => 'module', 'module' => 'geo'], [], $this->headers()));
        $this->assertSame(200, $list->status(), $list->body());
        $this->assertStringContains('Phare [PH]', $list->decodedJson()['data']['content']);

        // Restauration via le contrat : le point redevient visible, la corbeille est vide
        $module->restoreTrashItem((string) $id);
        $this->assertSame([], $module->trashItems());
        $this->assertSame(200, $this->app->handle(Request::create('GET', '/m/geo/show/' . $id, [], [], $this->headers()))->status());
        $this->assertThrows(\Atelier\Error\NotFoundException::class, fn () => $module->restoreTrashItem((string) $id));

        // Purge via le contrat : point et inscription au registre disparaissent
        $this->app->handle(Request::create('POST', '/m/geo/delete', [], ['id' => $id], $this->headers()));
        $module->purgeTrashItem((string) $id);
        $this->assertSame([], $module->trashItems());
        $this->assertSame(404, $this->app->handle(Request::create('GET', '/m/geo/show/' . $id, [], [], $this->headers()))->status());
        $this->assertNull($this->app->shared->registry->find('geo.point', (string) $id));
        $this->assertThrows(\Atelier\Error\NotFoundException::class, fn () => $module->purgeTrashItem((string) $id));
    }

    public function testServiceChecksDatasetRightsAndLinksInformation(): void
    {
        $this->allowAll();
        $response = $this->app->handle(Request::create('POST', '/m/geo/save', [], ['name' => 'Bureau', 'code' => 'HQ', 'coordinates' => '48.87, 2.33'], $this->headers()));
        $pointId = (int) $response->decodedJson()['data']['id'];

        $context = $this->app->context(Request::create('GET', '/'));
        $this->app->modules->reset();
        $this->app->modules->discover();
        $service = $context->moduleService('geo');

        $point = $service->get($pointId);
        $this->assertSame('Bureau [HQ]', $point['label']);
        $this->assertSame('48.870000, 2.330000', $point['decimal']);
        $this->assertSame('Bureau [HQ]', $service->label($pointId));
        $this->assertSame($pointId, $service->findByCode('HQ')['id']);
        $this->assertCount(1, $service->nearby(48.87, 2.33, 1.0));
        $this->assertCount(0, $service->nearby(45.0, 5.0, 1.0));
        $this->assertSame([['id' => $pointId, 'label' => 'Bureau [HQ]']], $service->options());

        // Rattachement d'une information d'un jeu partagé lisible (users.account)
        $this->app->acl->setRule('user', $this->userId, AclService::module('users') . '/data/account', 'read', 'allow');
        $this->app->acl->clearCache();
        $infoId = $this->app->shared->registry->register('users.account', (string) $this->userId, 'Alice');
        $service->attach($infoId, $pointId, 'siège');
        $points = $service->pointsOf($infoId);
        $this->assertCount(1, $points);
        $this->assertSame($pointId, $points[0]['id']);
        $infos = $service->infosAt($pointId);
        $this->assertCount(1, $infos);
        $this->assertSame('Alice', $infos[0]['label']);
        $this->assertSame('siège', $infos[0]['comment']);

        // La fiche du point affiche l'information rattachée
        $content = $this->app->handle(Request::create('GET', '/m/geo/show/' . $pointId, [], [], $this->headers()))->decodedJson()['data']['content'];
        $this->assertStringContains('Alice', $content);
        $this->assertStringContains('users.account', $content);

        $service->detach($infoId, $pointId);
        $this->assertCount(0, $service->pointsOf($infoId));

        // Sans droit de lecture sur le jeu partagé, le service refuse
        $this->app->acl->setRule('user', $this->userId, AclService::module('geo') . '/data/point', 'read', 'deny');
        $this->app->acl->clearCache();
        $this->assertThrows(ForbiddenException::class, fn () => $service->find($pointId));
    }

    /** @return list<string> identifiants locaux des informations d'un jeu portant le tag */
    private function taggedKeys(string $tag, string $dataset): array
    {
        $tagId = (int) $this->app->shared->tags->findOrCreate($tag)['id'];
        $rows = array_filter($this->app->shared->tags->infosWithTag($tagId), static fn (array $r): bool => $r['dataset_code'] === $dataset);
        return array_values(array_map(static fn (array $r): string => (string) $r['local_key'], $rows));
    }

    private function post(string $module, string $route, array $data = []): \Atelier\Http\Response
    {
        return $this->app->handle(Request::create('POST', '/m/' . $module . '/' . $route, [], $data, $this->headers()));
    }

    /**
     * Non-régression : un point mis à la corbeille restait listé sous ses tags (Explorateur,
     * module Tags) avec un lien menant à une erreur 404. Il doit disparaître du registre visible
     * à la mise en corbeille et revenir à la restauration, par l'action du module comme par la
     * corbeille globale.
     */
    public function testTrashedPointLeavesTagListingsAndComesBackOnRestore(): void
    {
        $this->allowAll();
        $id = (int) $this->post('geo', 'save', ['name' => 'Phare du Créac’h', 'coordinates' => '48.4594, -5.1253'])->decodedJson()['data']['id'];
        $this->assertSame(200, $this->post('geo', 'tag-add', ['id' => $id, 'tag' => 'phare'])->status());
        $this->assertSame([(string) $id], $this->taggedKeys('phare', 'geo.point'));

        $this->assertSame(200, $this->post('geo', 'delete', ['id' => $id])->status());
        $this->assertSame([], $this->taggedKeys('phare', 'geo.point'), 'point en corbeille écarté des tags');
        $this->assertTrue($this->app->shared->registry->isTrashed('geo.point', (string) $id));

        $this->assertSame(200, $this->post('geo', 'restore', ['id' => $id])->status());
        $this->assertSame([(string) $id], $this->taggedKeys('phare', 'geo.point'), 'restauré par l’action du module');

        // Corbeille globale : restauration par le contrat TrashProviderInterface
        $this->post('geo', 'delete', ['id' => $id]);
        $this->assertSame([], $this->taggedKeys('phare', 'geo.point'));
        $module = $this->app->modules->instance('geo');
        $module->boot($this->app->context(Request::create('GET', '/')));
        $module->restoreTrashItem((string) $id);
        $this->assertSame([(string) $id], $this->taggedKeys('phare', 'geo.point'), 'restauré par la corbeille globale');
        $this->assertFalse($this->app->shared->registry->isTrashed('geo.point', (string) $id));
    }

    /**
     * Non-régression : la fiche d'un point (« informations rattachées ») affichait encore une page
     * mise à la corbeille, avec un lien vers une erreur 404.
     */
    public function testShowHidesTrashedLinkedInformation(): void
    {
        $this->allowAll();
        $this->app->acl->setRule('user', $this->userId, AclService::module('wiki'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $pointId = (int) $this->post('geo', 'save', ['name' => 'Refuge', 'coordinates' => '45.83, 6.86'])->decodedJson()['data']['id'];
        $pageId = (int) $this->post('wiki', 'save', ['title' => 'Nuit au refuge', 'content' => 'Récit.', 'points' => [$pointId]])->decodedJson()['data']['id'];
        $show = fn (): string => (string) $this->app->handle(Request::create('GET', '/m/geo/show/' . $pointId, [], [], $this->headers()))->decodedJson()['data']['content'];
        $this->assertStringContains('Nuit au refuge', $show());

        $this->assertSame(200, $this->post('wiki', 'delete', ['id' => $pageId])->status());
        $this->assertFalse(str_contains($show(), 'Nuit au refuge'), 'page en corbeille absente de la fiche du point');

        $this->assertSame(200, $this->post('wiki', 'restore', ['id' => $pageId])->status());
        $this->assertStringContains('Nuit au refuge', $show());
    }

    /** Un point en corbeille ne peut pas recevoir de rattachement par son identifiant global. */
    public function testLinkRefusesTrashedInformation(): void
    {
        $this->allowAll();
        $this->app->acl->setRule('user', $this->userId, AclService::module('wiki'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $pointId = (int) $this->post('geo', 'save', ['name' => 'Col', 'coordinates' => '45.0, 6.0'])->decodedJson()['data']['id'];
        $pageId = (int) $this->post('wiki', 'save', ['title' => 'Page supprimée', 'content' => 'x'])->decodedJson()['data']['id'];
        $infoId = (string) $this->app->shared->registry->find('wiki.page', (string) $pageId)['id'];
        $this->post('wiki', 'delete', ['id' => $pageId]);
        $response = $this->post('geo', 'link', ['id' => $pointId, 'info_id' => $infoId]);
        $this->assertSame(422, $response->status(), $response->body());
    }

    /** Le formulaire de création et d'édition pose et remplace les tags partagés ; la fiche garde l'ajout et le retrait. */
    public function testFormSetsAndReplacesTags(): void
    {
        $this->allowAll();
        $form = (string) $this->app->handle(Request::create('GET', '/m/geo/new', [], [], $this->headers()))->decodedJson()['data']['content'];
        $this->assertStringContains('data-tags-input', $form, 'champ de tags commun dans le formulaire de création');

        $response = $this->post('geo', 'save', ['name' => 'Pointe du Raz', 'coordinates' => '48.0375, -4.7381', 'tags' => 'Côte, phare, côte']);
        $this->assertSame(200, $response->status(), $response->body());
        $id = (int) $response->decodedJson()['data']['id'];
        $infoId = (string) $this->app->shared->registry->find('geo.point', (string) $id)['id'];
        $names = static fn (array $tags): array => array_map(static fn (array $t): string => (string) $t['name'], $tags);
        $this->assertSame(['Côte', 'phare'], $names($this->app->shared->tags->tagsOf($infoId)));

        $edit = (string) $this->app->handle(Request::create('GET', '/m/geo/edit/' . $id, [], [], $this->headers()))->decodedJson()['data']['content'];
        $this->assertStringContains('data-tags-input', $edit);
        $this->assertStringContains('value="Côte, phare"', $edit, 'tags existants préremplis');

        $this->assertSame(200, $this->post('geo', 'save', ['id' => $id, 'name' => 'Pointe du Raz', 'coordinates' => '48.0375, -4.7381', 'tags' => 'phare, Finistère'])->status());
        $this->assertSame(['Finistère', 'phare'], $names($this->app->shared->tags->tagsOf($infoId)));

        // Ajout et retrait depuis la fiche, inchangés
        $this->assertSame(200, $this->post('geo', 'tag-add', ['id' => $id, 'tag' => 'granit'])->status());
        $this->assertSame(['Finistère', 'granit', 'phare'], $names($this->app->shared->tags->tagsOf($infoId)));
        $tagId = (int) $this->app->shared->tags->findOrCreate('granit')['id'];
        $this->assertSame(200, $this->post('geo', 'tag-remove', ['id' => $id, 'tag_id' => $tagId])->status());
        $this->assertSame(['Finistère', 'phare'], $names($this->app->shared->tags->tagsOf($infoId)));

        // Validation : un tag trop long est signalé sur le champ
        $response = $this->post('geo', 'save', ['id' => $id, 'name' => 'Pointe du Raz', 'coordinates' => '48.0375, -4.7381', 'tags' => str_repeat('x', 61)]);
        $this->assertSame(422, $response->status());
        $this->assertTrue(isset($response->decodedJson()['error']['fields']['tags']));
    }

    /** La migration de rattrapage marque au registre les points déjà en corbeille, et se rejoue sans effet. */
    public function testCatchUpMigrationMarksExistingTrash(): void
    {
        $this->allowAll();
        $trashed = (int) $this->post('geo', 'save', ['name' => 'Ancien', 'coordinates' => '1, 1'])->decodedJson()['data']['id'];
        $alive = (int) $this->post('geo', 'save', ['name' => 'Vivant', 'coordinates' => '2, 2'])->decodedJson()['data']['id'];
        // État hérité : supprimé logiquement avant que le registre ne connaisse la corbeille.
        $this->app->db->update('geo_point', ['deleted_at' => '2026-09-01 10:00:00'], 'id = :id', ['id' => $trashed]);
        $this->assertFalse($this->app->shared->registry->isTrashed('geo.point', (string) $trashed));

        $migration = require dirname(__DIR__, 2) . '/modules/geo/migrations/002_registry_trash.php';
        $migration($this->app->db);
        $this->assertSame('2026-09-01 10:00:00', $this->app->shared->registry->find('geo.point', (string) $trashed)['trashed_at']);
        $this->assertNull($this->app->shared->registry->find('geo.point', (string) $alive)['trashed_at']);

        $migration($this->app->db);
        $this->assertSame('2026-09-01 10:00:00', $this->app->shared->registry->find('geo.point', (string) $trashed)['trashed_at'], 'rejouable sans effet');
    }
}
