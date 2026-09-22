<?php

declare(strict_types=1);

namespace Atelier\Tests\Kernel;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Testing\TestCase;

/**
 * Tests d'intégration du routage : un visiteur non connecté n'obtient rien, une URL directe
 * vers un module interdit est refusée côté serveur, un module inconnu donne 404.
 */
final class ApplicationTest extends TestCase
{
    private Application $app;
    private int $userId;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
    }

    public function testAnonymousShellRedirectsToLogin(): void
    {
        $response = $this->app->handle(Request::create('GET', '/'));
        $this->assertSame(302, $response->status());
        $this->assertStringContains('/login', (string) $response->header('Location'));
    }

    public function testAnonymousDynamicRequestGetsJson401(): void
    {
        $response = $this->app->handle(Request::create('GET', '/m/home', [], [], ['X-Atelier-Request' => 'json']));
        $this->assertSame(401, $response->status());
        $this->assertSame('auth', $response->decodedJson()['error']['type']);
    }

    public function testAnonymousCoreEndpointIsRefused(): void
    {
        $response = $this->app->handle(Request::create('GET', '/core/nav', [], [], ['X-Atelier-Request' => 'json']));
        $this->assertSame(401, $response->status());
    }

    public function testLoginThenForbiddenModuleThenAllowed(): void
    {
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $response = $this->app->handle(Request::create('GET', '/m/home', [], [], ['X-Atelier-Request' => 'json']));
        $this->assertSame(403, $response->status());
        $this->assertSame('forbidden', $response->decodedJson()['error']['type']);

        $this->app->acl->setRule('user', $this->userId, AclService::module('home'), 'open', 'allow');
        $response = $this->app->handle(Request::create('GET', '/m/home', [], [], ['X-Atelier-Request' => 'json']));
        $this->assertSame(200, $response->status());
        $data = $response->decodedJson()['data'];
        $this->assertSame('Accueil', $data['title']);
        $this->assertStringContains('module-home', $data['content']);
        $this->assertSame('home', $data['module']['id']);
    }

    public function testUnknownModuleIs404AndSecurityHeadersPresent(): void
    {
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $response = $this->app->handle(Request::create('GET', '/m/inexistant', [], [], ['X-Atelier-Request' => 'json']));
        $this->assertSame(404, $response->status());
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $response = $this->app->handle(Request::create('POST', '/core/preferences', [], ['values' => ['pageSize' => 50]], ['X-Atelier-Request' => 'json']));
        $this->assertSame(419, $response->status());
    }

    public function testNavigationTreeMarksLockedModules(): void
    {
        $tree = $this->app->modules->navigationTree($this->userId, $this->app->acl);
        $home = null;
        foreach ($tree as $group) {
            foreach ($group['modules'] as $module) {
                if ($module['id'] === 'home') {
                    $home = $module;
                }
            }
        }
        $this->assertNotNull($home);
        $this->assertSame('locked', $home['state']);
        $this->app->acl->setRule('user', $this->userId, AclService::module('home'), 'open', 'allow');
        $this->app->acl->clearCache();
        $tree = $this->app->modules->navigationTree($this->userId, $this->app->acl);
        $states = [];
        foreach ($tree as $group) {
            foreach ($group['modules'] as $module) {
                $states[$module['id']] = $module['state'];
            }
        }
        $this->assertSame('active', $states['home']);
    }
}
