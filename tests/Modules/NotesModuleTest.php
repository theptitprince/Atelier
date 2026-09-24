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

    /** @return list<string> identifiants locaux des notes portant le tag */
    private function taggedNotes(string $tag): array
    {
        $tagId = (int) $this->app->shared->tags->findOrCreate($tag)['id'];
        $rows = array_filter($this->app->shared->tags->infosWithTag($tagId), static fn (array $r): bool => $r['dataset_code'] === NotesModule::DATASET);
        return array_values(array_map(static fn (array $r): string => (string) $r['local_key'], $rows));
    }

    /** Non-régression : une note mise à la corbeille restait listée sous ses tags (module Tags) avec un lien en 404. */
    public function testTrashedNoteLeavesTagListingsAndComesBackOnRestore(): void
    {
        $id = (int) $this->post('save', ['title' => 'Liste de courses', 'content' => 'Pain', 'tags' => 'maison, courses'])->decodedJson()['data']['id'];
        $this->assertSame([(string) $id], $this->taggedNotes('courses'));

        $this->assertSame(200, $this->post('delete', ['id' => $id])->status());
        $this->assertSame([], $this->taggedNotes('courses'), 'note en corbeille écartée des tags');
        $this->assertTrue($this->app->shared->registry->isTrashed(NotesModule::DATASET, (string) $id));

        $this->assertSame(200, $this->post('restore', ['id' => $id])->status());
        $this->assertSame([(string) $id], $this->taggedNotes('courses'), 'restaurée par l’action du module');

        $this->post('delete', ['id' => $id]);
        $module = $this->app->modules->instance('notes');
        $module->boot($this->app->context(Request::create('GET', '/')));
        $module->restoreTrashItem((string) $id);
        $this->assertSame([(string) $id], $this->taggedNotes('courses'), 'restaurée par la corbeille globale');
        $this->assertFalse($this->app->shared->registry->isTrashed(NotesModule::DATASET, (string) $id));
    }

    /** La migration de rattrapage marque au registre les notes déjà en corbeille, et se rejoue sans effet. */
    public function testCatchUpMigrationMarksExistingTrash(): void
    {
        $trashed = (int) $this->post('save', ['title' => 'Ancienne', 'content' => 'x'])->decodedJson()['data']['id'];
        $alive = (int) $this->post('save', ['title' => 'Vivante', 'content' => 'x'])->decodedJson()['data']['id'];
        $this->app->db->update('notes_note', ['deleted_at' => '2026-09-01 10:00:00'], 'id = :id', ['id' => $trashed]);
        $this->assertFalse($this->app->shared->registry->isTrashed(NotesModule::DATASET, (string) $trashed));

        $migration = require dirname(__DIR__, 2) . '/modules/notes/migrations/002_registry_trash.php';
        $migration($this->app->db);
        $this->assertSame('2026-09-01 10:00:00', $this->app->shared->registry->find(NotesModule::DATASET, (string) $trashed)['trashed_at']);
        $this->assertNull($this->app->shared->registry->find(NotesModule::DATASET, (string) $alive)['trashed_at']);
        $migration($this->app->db);
        $this->assertSame('2026-09-01 10:00:00', $this->app->shared->registry->find(NotesModule::DATASET, (string) $trashed)['trashed_at'], 'rejouable sans effet');
    }
}
