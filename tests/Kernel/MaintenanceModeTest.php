<?php

declare(strict_types=1);

namespace Atelier\Tests\Kernel;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Kernel\Config;
use Atelier\Persistence\Database;
use Atelier\Testing\TestCase;

/**
 * Stockage applicatif indisponible (critère de recette §36) : refus par défaut, état de maintenance,
 * découverte des modules conservée, aucune fuite technique.
 */
final class MaintenanceModeTest extends TestCase
{
    private function brokenApplication(): Application
    {
        $root = dirname(__DIR__, 2);
        $values = require $root . '/config/app.php';
        $values['app']['debug'] = false;
        $values['database'] = ['driver' => 'sqlite', 'sqlite' => ['path' => 'Z:/inexistant/interdit/atelier.sqlite', 'wal' => false]];
        $config = Config::fromArray($values, $root);
        return Application::boot($root, $config, Database::fromConfig($config));
    }

    /** Une session valide existe : la lecture du compte échoue, l'application passe en maintenance. */
    public function testAuthenticatedUserGetsMaintenancePage(): void
    {
        $_SESSION = ['atelier' => ['user_id' => 1, 'login_at' => time(), 'last_activity' => time()]];
        $app = $this->brokenApplication();
        $response = $app->handle(Request::create('GET', '/'));
        $this->assertSame(503, $response->status());
        $this->assertSame('60', $response->header('Retry-After'));
        $this->assertStringContains('Maintenance', $response->body());
        $this->assertMatches('/ERR-[0-9A-F]{6}/', $response->body());
        $this->assertFalse(str_contains($response->body(), 'Z:/inexistant'), 'aucun chemin technique ne doit fuir');
    }

    /**
     * Une requête dynamique reçoit une erreur « indisponible », y compris à la deuxième tentative :
     * un échec de lecture du compte ne doit pas être mémorisé comme « non connecté ».
     */
    public function testDynamicRequestsStayUnavailable(): void
    {
        $_SESSION = ['atelier' => ['user_id' => 1, 'login_at' => time(), 'last_activity' => time()]];
        $app = $this->brokenApplication();
        foreach ([1, 2] as $attempt) {
            $response = $app->handle(Request::create('GET', '/m/notes/list', [], [], ['X-Atelier-Request' => 'json']));
            $body = $response->decodedJson();
            $this->assertSame(503, $response->status(), 'tentative ' . $attempt);
            $this->assertSame('unavailable', $body['error']['type']);
            $this->assertTrue($body['error']['maintenance']);
        }
    }

    /** Sans session, le visiteur est renvoyé vers la page de connexion, qui signale la panne. */
    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $_SESSION = ['atelier' => []];
        $app = $this->brokenApplication();
        $response = $app->handle(Request::create('GET', '/'));
        $this->assertSame(302, $response->status());
        $login = $app->handle(Request::create('GET', '/login'));
        $this->assertSame(200, $login->status());
        $this->assertStringContains('stockage applicatif est indisponible', $login->body());
    }
}
