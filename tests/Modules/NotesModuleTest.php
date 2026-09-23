<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Modules\Notes\NotesModule;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Clock;
use Atelier\Testing\TestCase;

/**
 * Module Bloc-notes : contrôle de concurrence entre deux onglets et robustesse de la pagination.
 */
final class NotesModuleTest extends TestCase
{
    private Application $app;
    private int $userId;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $this->userId, AclService::module('notes'), 'admin', 'allow');
        $this->app->acl->clearCache();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
    }

    public function tearDown(): void
    {
        Clock::freeze(null);
    }

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    private function post(string $route, array $data = []): \Atelier\Http\Response
    {
        return $this->app->handle(Request::create('POST', '/m/notes/' . $route, [], $data, $this->headers()));
    }

    private function get(string $route, array $query = []): \Atelier\Http\Response
    {
        return $this->app->handle(Request::create('GET', '/m/notes/' . $route, $query, [], $this->headers()));
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $row = $this->app->db->selectOne('SELECT * FROM notes_note WHERE id = :id', ['id' => $id]);
        $this->assertNotNull($row);
        return (array) $row;
    }

    /**
     * Non-régression : le contrôle de concurrence reposait sur updated_at, à la seconde près.
     * Deux enregistrements dans la même seconde laissaient l'horodatage identique, si bien qu'un
     * onglet resté ouvert écrasait silencieusement le travail de l'autre.
     */
    public function testConcurrentSavesWithinTheSameSecondAreDetected(): void
    {
        Clock::freeze(new \DateTimeImmutable('2026-09-23 10:00:00', new \DateTimeZone('UTC')));
        $id = (int) $this->post('save', ['title' => 'Recette', 'content' => 'version initiale'])->decodedJson()['data']['id'];

        // Deux onglets ouvrent la note dans le même état : même jeton de concurrence.
        $token = NotesModule::versionToken($this->row($id));

        // L'onglet B enregistre dans la même seconde que la création.
        $this->assertSame(200, $this->post('save', ['id' => $id, 'title' => 'Recette', 'content' => 'texte saisi par B', 'version' => $token])->status());
        $this->assertSame('2026-09-23 10:00:00', (string) $this->row($id)['updated_at'], 'horodatage inchangé : granularité à la seconde');

        // L'onglet A enregistre plus tard avec son jeton périmé : conflit, rien n'est écrasé.
        Clock::freeze(new \DateTimeImmutable('2026-09-23 10:00:05', new \DateTimeZone('UTC')));
        $conflict = $this->post('save', ['id' => $id, 'title' => 'Recette', 'content' => 'texte saisi par A', 'version' => $token]);
        $this->assertSame(409, $conflict->status(), $conflict->body());
        $this->assertStringContains('modifiée entre-temps', (string) $conflict->decodedJson()['message']);
        $this->assertSame('texte saisi par B', (string) $this->row($id)['content']);

        // Après rechargement, le jeton à jour permet d'enregistrer.
        $fresh = NotesModule::versionToken($this->row($id));
        $this->assertSame(200, $this->post('save', ['id' => $id, 'title' => 'Recette', 'content' => 'fusion A+B', 'version' => $fresh])->status());
        $this->assertSame('fusion A+B', (string) $this->row($id)['content']);

        // Le formulaire porte bien le jeton et plus l'horodatage brut.
        $editor = $this->get('edit/' . $id)->decodedJson()['data']['content'];
        $this->assertStringContains('name="version"', $editor);
        $this->assertFalse(str_contains($editor, 'name="updated_at"'));
    }

    /** Non-régression : un numéro de page démesuré débordait l'entier et cassait la clause OFFSET (erreur 500). */
    public function testHugePageNumberReturnsAnEmptyPageInsteadOfAnError(): void
    {
        $this->post('save', ['title' => 'Une note', 'content' => 'Texte']);
        foreach (['0', '-3', '9223372036854775807', '999999999999999999'] as $page) {
            $response = $this->get('list', ['page' => $page]);
            $this->assertSame(200, $response->status(), 'page=' . $page . ' : ' . $response->body());
        }
        $this->assertStringContains('Une note', $this->get('list')->decodedJson()['data']['content']);
    }
}
