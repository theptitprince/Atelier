<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Testing\TestCase;

/**
 * Module Carte : dépend du service geo ; points enrichis (tags, liens, pièces jointes) ; préférences.
 */
final class MapModuleTest extends TestCase
{
    private Application $app;
    private int $userId;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $this->userId, AclService::module('map'), 'open', 'allow');
        $this->app->acl->setRule('user', $this->userId, AclService::module('geo'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
    }

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    public function testMapListsPointsWithLinksTagsAndAttachments(): void
    {
        $response = $this->app->handle(Request::create('POST', '/m/geo/save', [], ['name' => 'Phare', 'code' => 'PH', 'coordinates' => '48.04, -4.74'], $this->headers()));
        $pointId = (int) $response->decodedJson()['data']['id'];
        $this->app->handle(Request::create('POST', '/m/geo/tag-add', [], ['id' => $pointId, 'tag' => 'mer'], $this->headers()));
        $infoId = (string) $this->app->shared->registry->find('geo.point', (string) $pointId)['id'];
        $this->app->shared->attachments->storeContent('plan', 'plan.txt', $infoId, $this->userId);
        $this->app->acl->setRule('user', $this->userId, AclService::module('users') . '/data/account', 'read', 'allow');
        $this->app->acl->clearCache();
        $userInfo = $this->app->shared->registry->register('users.account', (string) $this->userId, 'Alice');
        $this->app->shared->relations->relate('located_at', $userInfo, $infoId);

        $view = $this->app->handle(Request::create('GET', '/m/map/index', [], [], $this->headers()));
        $this->assertSame(200, $view->status(), $view->body());
        $data = $view->decodedJson()['data'];
        $this->assertStringContains('data-map-canvas', $data['content']);
        $this->assertSame('osm', $data['state']['prefs']['base']);
        $this->assertContains('/module-assets/map/assets/vendor/leaflet/leaflet.js?v=1.0.0', $data['module']['assets']['js']);

        $points = $this->app->handle(Request::create('GET', '/m/map/points', [], [], $this->headers()))->decodedJson()['data'];
        $this->assertCount(1, $points['points']);
        $point = $points['points'][0];
        $this->assertSame('Phare', $point['name']);
        $this->assertSame(['mer'], $point['tags']);
        $this->assertSame(1, $point['attachments']);
        $this->assertSame('Alice', $point['links'][0]['label']);
        $this->assertSame('users', $point['links'][0]['module']);
        $this->assertSame('Utilisateurs et droits', $points['modules']['users']);

        $response = $this->app->handle(Request::create('POST', '/m/map/prefs', [], ['base' => 'seamap', 'seamarks' => true, 'lat' => 48.0, 'lon' => -4.7, 'zoom' => 12, 'sort' => 'distance'], $this->headers()));
        $this->assertSame(200, $response->status(), $response->body());
        $state = $this->app->handle(Request::create('GET', '/m/map/index', [], [], $this->headers()))->decodedJson()['data']['state'];
        $this->assertSame('seamap', $state['prefs']['base']);
        $this->assertSame(12, $state['prefs']['zoom']);
        $this->assertSame('distance', $state['prefs']['sort']);
        $this->assertSame(422, $this->app->handle(Request::create('POST', '/m/map/prefs', [], ['base' => 'google'], $this->headers()))->status());
    }

    public function testMapRequiresGeoDatasetRight(): void
    {
        $this->app->acl->setRule('user', $this->userId, AclService::module('geo') . '/data/point', 'read', 'deny');
        $this->app->acl->clearCache();
        $view = $this->app->handle(Request::create('GET', '/m/map/index', [], [], $this->headers()));
        $this->assertSame(200, $view->status());
        $this->assertStringContains('Carte indisponible', $view->decodedJson()['data']['content']);
        $this->assertFalse($view->decodedJson()['data']['state']['available']);
        $this->assertSame(403, $this->app->handle(Request::create('GET', '/m/map/points', [], [], $this->headers()))->status());
    }
}
