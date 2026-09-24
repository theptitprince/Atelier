<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Clock;
use Atelier\Testing\TestCase;

/**
 * Module Projets : création, tâches, journal, relations transversales, documents, statut, badge,
 * service intermodule, corbeille du module et corbeille globale.
 */
final class ProjectModuleTest extends TestCase
{
    private Application $app;
    private int $userId;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        foreach (['project', 'wiki', 'geo', 'trash', 'explorer'] as $module) {
            $this->app->acl->setRule('user', $this->userId, AclService::module($module), 'admin', 'allow');
        }
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

    private function get(string $route, array $query = []): \Atelier\Http\Response
    {
        return $this->app->handle(Request::create('GET', '/m/project/' . $route, $query, [], $this->headers()));
    }

    private function view(string $route, array $query = []): array
    {
        $response = $this->get($route, $query);
        $this->assertSame(200, $response->status(), $response->body());
        return $response->decodedJson()['data'];
    }

    public function testCreateTasksJournalLinksAndDocuments(): void
    {
        // Validation : titre obligatoire, échéance antérieure au début refusée, montant invalide
        $response = $this->post('project', 'save', ['title' => '', 'start_date' => '2026-10-10', 'due_date' => '2026-10-01', 'budget_estimate' => 'abc']);
        $this->assertSame(422, $response->status(), $response->body());
        $fields = $response->decodedJson()['error']['fields'];
        $this->assertTrue(isset($fields['title'], $fields['due_date'], $fields['budget_estimate']));

        $response = $this->post('project', 'save', ['title' => 'Voyage en Écosse', 'status' => 'planned', 'summary' => 'Dix jours dans les Highlands.', 'description' => "[h2]Itinéraire[/h2]\nÉdimbourg puis Skye. <script>x</script>", 'start_date' => '2026-11-01', 'due_date' => '2026-11-10', 'budget_estimate' => '3 200,50', 'priority' => 1, 'tags' => 'voyage, Écosse']);
        $this->assertSame(200, $response->status(), $response->body());
        $data = $response->decodedJson()['data'];
        $this->assertSame('voyage-en-ecosse', $data['slug']);
        $projectId = (int) $data['id'];
        $this->assertSame(422, $this->post('project', 'save', ['title' => 'VOYAGE en Écosse'])->status(), 'doublon de titre refusé');

        $show = $this->view('show/' . $projectId);
        $content = $show['content'];
        $this->assertStringContains('Planifié', $content);
        $this->assertStringContains('3 200,50 €', $content);
        $this->assertStringContains('<h2', $content, 'description rendue en HTML');
        $this->assertStringContains('&lt;script&gt;', $content, 'le HTML saisi est échappé');
        $this->assertStringContains('voyage', $content, 'tag affiché');
        $bySlug = $this->view('show/voyage-en-ecosse');
        $this->assertStringContains('Voyage en Écosse', $bySlug['banner'], 'fiche accessible par identifiant lisible');
        $this->assertStringContains('voyage-en-ecosse', $bySlug['content']);

        // Tâches : ajout, cocher, réordonner, retard
        $this->assertSame(422, $this->post('project', 'task-add', ['id' => $projectId, 'title' => ''])->status());
        $taskA = (int) $this->post('project', 'task-add', ['id' => $projectId, 'title' => 'Réserver les billets', 'due_date' => '2020-01-01'])->decodedJson()['data']['id'];
        $taskB = (int) $this->post('project', 'task-add', ['id' => $projectId, 'title' => 'Louer une voiture'])->decodedJson()['data']['id'];
        $content = $this->view('show/' . $projectId)['content'];
        $this->assertTrue(strpos($content, 'Réserver les billets') < strpos($content, 'Louer une voiture'), 'ordre initial');
        $this->assertStringContains('is-late', $content, 'tâche en retard signalée');
        $this->assertSame(200, $this->post('project', 'task-move', ['id' => $projectId, 'task_id' => $taskB, 'direction' => 'up'])->status());
        $content = $this->view('show/' . $projectId)['content'];
        $this->assertTrue(strpos($content, 'Louer une voiture') < strpos($content, 'Réserver les billets'), 'tâche remontée');
        $this->assertSame(200, $this->post('project', 'task-toggle', ['id' => $projectId, 'task_id' => $taskA])->status());
        $this->assertStringContains('1/2 tâches', $this->view('show/' . $projectId)['content']);

        // Badge : ne compte que les projets « en cours » ayant une tâche non faite en retard
        $this->assertSame(0, $this->get('badge')->decodedJson()['data']['count'], 'tâche en retard mais faite : pas comptée');
        $this->post('project', 'task-toggle', ['id' => $projectId, 'task_id' => $taskA, 'done' => false]);
        $this->assertSame(0, $this->get('badge')->decodedJson()['data']['count'], 'projet planifié : pas compté');
        $this->assertSame(200, $this->post('project', 'set-status', ['id' => $projectId, 'status' => 'active'])->status());
        $this->assertSame(1, $this->get('badge')->decodedJson()['data']['count'], 'projet en cours avec une tâche en retard');
        $this->assertSame(422, $this->post('project', 'set-status', ['id' => $projectId, 'status' => 'bizarre'])->status());
        $this->assertSame(200, $this->post('project', 'task-delete', ['id' => $projectId, 'task_id' => $taskA])->status());
        $this->assertSame(0, $this->get('badge')->decodedJson()['data']['count']);

        // Journal de bord
        $this->assertSame(422, $this->post('project', 'note-add', ['id' => $projectId, 'content' => '   '])->status());
        $noteId = (int) $this->post('project', 'note-add', ['id' => $projectId, 'content' => 'Billets [b]pris[/b].'])->decodedJson()['data']['id'];
        $content = $this->view('show/' . $projectId)['content'];
        $this->assertStringContains('<strong>pris</strong>', $content);
        $this->assertSame(200, $this->post('project', 'note-delete', ['id' => $projectId, 'note_id' => $noteId])->status());
        $this->assertStringContains('Le journal est vide', $this->view('show/' . $projectId)['content']);

        // Relation vers une page wiki via la recherche transversale (lookup), puis retrait
        $this->assertSame(200, $this->post('wiki', 'save', ['title' => 'Accueil', 'content' => 'Bienvenue'])->status());
        $results = $this->get('lookup', ['q' => 'Accueil'])->decodedJson()['data']['results'];
        $this->assertCount(1, $results);
        $this->assertSame('wiki.page', $results[0]['dataset']);
        $this->assertSame(200, $this->post('project', 'link', ['id' => $projectId, 'to' => $results[0]['id']])->status());
        $this->assertSame(422, $this->post('project', 'link', ['id' => $projectId, 'to' => 'inexistant'])->status());
        $content = $this->view('show/' . $projectId)['content'];
        $this->assertStringContains('Fait partie du projet', $content);
        // Le module Pages déclare openRoute "page/{key}" : la page liée s'ouvre dans son module.
        $this->assertMatches('/data-open-module="wiki"[^>]*data-open-route="page\/\d+"[^>]*>Accueil</', $content, 'page wiki avec openRoute : lien vers le module Pages');
        $infoId = (string) $this->app->shared->registry->find('project.project', (string) $projectId)['id'];
        $relations = $this->app->shared->relations->relationsOf($infoId);
        $this->assertCount(1, $relations);
        $this->assertSame('in', $relations[0]['direction'], 'part_of : de la page vers le projet');

        // Point GPS relié par le service geo (relation located_at) et affiché dans « Lieux »
        $point = (int) $this->post('geo', 'save', ['name' => 'Édimbourg', 'code' => 'EDI', 'coordinates' => '55.95, -3.19'])->decodedJson()['data']['id'];
        $pointInfo = (string) $this->app->shared->registry->find('geo.point', (string) $point)['id'];
        $this->assertSame(200, $this->post('project', 'link', ['id' => $projectId, 'to' => $pointInfo])->status());
        $content = $this->view('show/' . $projectId)['content'];
        $this->assertStringContains('data-open-route="show/' . $point . '"', $content);
        $this->assertStringContains('Édimbourg [EDI]', $content);

        // Liste : indicateurs, recherche et filtre par tag
        $list = $this->view('list')['content'];
        $this->assertStringContains('2 lié(s)', $list);
        $this->assertStringContains('En cours', $list);
        $this->assertStringContains('Voyage', $this->view('list', ['q' => 'highlands'])['content']);
        $this->assertFalse(str_contains($this->view('list', ['q' => 'inexistant'])['content'], 'voyage-en-ecosse'));
        $this->assertStringContains('Voyage', $this->view('list', ['tag' => 'écosse'])['content']);
        $this->assertFalse(str_contains($this->view('list', ['tag' => 'autre'])['content'], 'Voyage'));

        // Documents : pièce jointe rattachée puis retirée
        $file = $this->app->shared->attachments->storeContent('a,b', 'liste.csv', $infoId, $this->userId);
        $this->assertStringContains('liste.csv', $this->view('show/' . $projectId)['content']);
        $this->assertSame(200, $this->post('project', 'attachment-delete', ['id' => $projectId, 'attachment_id' => $file['id']])->status());
        $this->assertFalse(str_contains($this->view('show/' . $projectId)['content'], 'liste.csv'));
        $this->assertSame(404, $this->post('project', 'attachment-delete', ['id' => $projectId, 'attachment_id' => $file['id']])->status());

        // Retrait de la relation wiki
        $this->assertSame(200, $this->post('project', 'unlink', ['id' => $projectId, 'relation_id' => (int) $relations[0]['id']])->status());
        $this->assertFalse(str_contains($this->view('show/' . $projectId)['content'], '>Accueil<'), 'page retirée des éléments liés');
        $this->assertSame(404, $this->post('project', 'unlink', ['id' => $projectId, 'relation_id' => (int) $relations[0]['id']])->status());

        // Service intermodule
        $context = $this->app->context(Request::create('GET', '/'));
        $this->app->modules->reset();
        $this->app->modules->discover();
        $service = $context->moduleService('project');
        $this->assertSame('Voyage en Écosse', $service->find($this->userId, $projectId)['title']);
        $this->assertSame('active', $service->list($this->userId)[0]['status']);
        $this->assertCount(0, $service->list($this->userId, 'idea'));
        $linked = $service->linkedInfoIds($this->userId, $projectId);
        $this->assertCount(1, $linked);
        $this->assertSame('geo.point', $linked[0]['dataset']);
        $this->assertSame('located_at', $linked[0]['type']);
    }

    /** Corbeille du module et corbeille globale (TrashProviderInterface) : suppression, restauration, purge. */
    public function testTrashAndGlobalTrashProvider(): void
    {
        $projectId = (int) $this->post('project', 'save', ['title' => 'Établi d’atelier', 'status' => 'idea', 'budget_estimate' => '450'])->decodedJson()['data']['id'];
        $this->post('project', 'task-add', ['id' => $projectId, 'title' => 'Dessiner le plan']);
        $this->post('project', 'note-add', ['id' => $projectId, 'content' => 'Première idée.']);

        $module = $this->app->modules->instance('project');
        $module->boot($this->app->context(Request::create('GET', '/')));
        $this->assertTrue($module instanceof \Atelier\Modules\TrashProviderInterface);
        $this->assertSame([], $module->trashItems());

        $this->assertSame(200, $this->post('project', 'delete', ['id' => $projectId])->status());
        $this->assertSame(404, $this->get('show/' . $projectId)->status());
        $this->assertSame(404, $this->post('project', 'task-add', ['id' => $projectId, 'title' => 'x'])->status());
        $this->assertStringContains('Établi d’atelier', $this->view('trash')['content']);
        $this->assertFalse(str_contains($this->view('list')['content'], 'Établi'));

        $items = $module->trashItems();
        $this->assertCount(1, $items);
        $this->assertSame((string) $projectId, $items[0]['id']);
        $this->assertSame('project.project', $items[0]['dataset']);
        $this->assertTrue($items[0]['can_restore'] && $items[0]['can_purge']);
        $this->assertTrue(strtotime($items[0]['purge_at'] . ' UTC') > strtotime($items[0]['deleted_at'] . ' UTC'));

        // Le module Corbeille agrège le projet
        $list = $this->app->handle(Request::create('GET', '/m/trash/list', ['source' => 'module', 'module' => 'project'], [], $this->headers()));
        $this->assertSame(200, $list->status(), $list->body());
        $this->assertStringContains('Établi d’atelier', $list->decodedJson()['data']['content']);

        // Restauration par le contrat, puis par l'action du module
        $module->restoreTrashItem((string) $projectId);
        $this->assertSame([], $module->trashItems());
        $this->assertStringContains('Dessiner le plan', $this->view('show/' . $projectId)['content'], 'tâches conservées');
        $this->assertThrows(\Atelier\Error\NotFoundException::class, fn () => $module->restoreTrashItem((string) $projectId));
        $this->post('project', 'delete', ['id' => $projectId]);
        $this->assertSame(200, $this->post('project', 'restore', ['id' => $projectId])->status());
        $this->assertSame(200, $this->get('show/' . $projectId)->status());

        // Purge : projet, tâches, journal et inscription au registre disparaissent
        $this->post('project', 'delete', ['id' => $projectId]);
        $module->purgeTrashItem((string) $projectId);
        $this->assertSame([], $module->trashItems());
        $this->assertNull($this->app->shared->registry->find('project.project', (string) $projectId));
        $this->assertSame(0, $this->app->db->count('SELECT COUNT(*) FROM project_task WHERE project_id = :p', ['p' => $projectId]));
        $this->assertSame(0, $this->app->db->count('SELECT COUNT(*) FROM project_note WHERE project_id = :p', ['p' => $projectId]));
        $this->assertThrows(\Atelier\Error\NotFoundException::class, fn () => $module->purgeTrashItem((string) $projectId));
    }

    public function testReaderCannotEdit(): void
    {
        $bob = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $bob, AclService::module('project'), 'open', 'allow');
        $this->app->acl->clearCache();
        $projectId = (int) $this->post('project', 'save', ['title' => 'Lecture seule', 'status' => 'active'])->decodedJson()['data']['id'];
        $this->app->auth->logout();
        $this->app->auth->login('bob', 'Mot-de-passe-solide', '127.0.0.1');
        $show = $this->view('show/' . $projectId);
        $this->assertStringContains('Lecture seule', $show['banner']);
        $this->assertStringContains('En cours', $show['content']);
        $this->assertFalse(str_contains($show['banner'], 'data-route="edit/'));
        $this->assertFalse(str_contains($show['content'], 'data-action="set-status"'));
        $this->assertFalse(str_contains($show['content'], 'data-action="task-add"'));
        $this->assertSame(403, $this->post('project', 'save', ['title' => 'Autre'])->status());
        $this->assertSame(403, $this->post('project', 'task-add', ['id' => $projectId, 'title' => 'x'])->status());
        $this->assertSame(403, $this->post('project', 'set-status', ['id' => $projectId, 'status' => 'done'])->status());
        $this->assertSame(403, $this->get('new')->status());
    }

    /**
     * Non-régression : le module raisonnait sur la date UTC alors que les échéances sont des jours
     * calendaires affichés en heure de Paris. Entre minuit et 2 h, les retards du jour étaient manqués.
     */
    public function testLateTasksUseTheDisplayTimezone(): void
    {
        // 23/09/2026 22:30 UTC = 24/09/2026 00:30 à Paris : une échéance au 23/09 est dépassée.
        Clock::freeze(new \DateTimeImmutable('2026-09-23 22:30:00', new \DateTimeZone('UTC')));
        try {
            $projectId = (int) $this->post('project', 'save', ['title' => 'Chantier', 'status' => 'active'])->decodedJson()['data']['id'];
            $this->assertSame(200, $this->post('project', 'task-add', ['id' => $projectId, 'title' => 'Commander le bois', 'due_date' => '2026-09-23'])->status());

            $badge = $this->get('badge')->decodedJson()['data'];
            $this->assertSame(1, (int) $badge['count'], 'la tâche du 23/09 est en retard le 24/09 à Paris');
            $this->assertStringContains('en retard', (string) $badge['label']);
            $this->assertStringContains('retard', $this->view('show/' . $projectId)['content']);
            $this->assertStringContains('retard', $this->view('list')['content']);

            // La veille au soir à Paris (22:30 heure de Paris), la même échéance n'est pas encore dépassée.
            Clock::freeze(new \DateTimeImmutable('2026-09-23 20:30:00', new \DateTimeZone('UTC')));
            $this->assertSame(0, (int) $this->get('badge')->decodedJson()['data']['count']);
        } finally {
            Clock::freeze(null);
        }
    }

    /** @return list<string> identifiants locaux des informations d'un jeu portant le tag */
    private function taggedKeys(string $tag, string $dataset): array
    {
        $tagId = (int) $this->app->shared->tags->findOrCreate($tag)['id'];
        $rows = array_filter($this->app->shared->tags->infosWithTag($tagId), static fn (array $r): bool => $r['dataset_code'] === $dataset);
        return array_values(array_map(static fn (array $r): string => (string) $r['local_key'], $rows));
    }

    /** Non-régression : un projet en corbeille restait listé sous ses tags (Explorateur, module Tags) avec un lien en 404. */
    public function testTrashedProjectLeavesTagListingsAndComesBackOnRestore(): void
    {
        $id = (int) $this->post('project', 'save', ['title' => 'Cabane', 'tags' => 'bois, jardin'])->decodedJson()['data']['id'];
        $this->assertSame([(string) $id], $this->taggedKeys('jardin', 'project.project'));

        $this->assertSame(200, $this->post('project', 'delete', ['id' => $id])->status());
        $this->assertSame([], $this->taggedKeys('jardin', 'project.project'), 'projet en corbeille écarté des tags');
        $this->assertTrue($this->app->shared->registry->isTrashed('project.project', (string) $id));

        $this->assertSame(200, $this->post('project', 'restore', ['id' => $id])->status());
        $this->assertSame([(string) $id], $this->taggedKeys('jardin', 'project.project'), 'restauré par l’action du module');

        // Corbeille globale, restauration groupée (clés « source:module:id »)
        $this->post('project', 'delete', ['id' => $id]);
        $this->assertSame([], $this->taggedKeys('jardin', 'project.project'));
        $response = $this->post('trash', 'restore-many', ['ids' => ['module:project:' . $id]]);
        $this->assertSame(200, $response->status(), $response->body());
        $this->assertSame([(string) $id], $this->taggedKeys('jardin', 'project.project'), 'restauré par la corbeille globale');
        $this->assertFalse($this->app->shared->registry->isTrashed('project.project', (string) $id));
    }

    /**
     * Non-régression (constat d'origine) : une page du module Pages mise à la corbeille restait
     * affichée dans la fiche du projet auquel elle était liée, avec un lien menant à une 404.
     */
    public function testTrashedLinkedPageDisappearsFromProjectAndComesBack(): void
    {
        $projectId = (int) $this->post('project', 'save', ['title' => 'Rénovation cuisine'])->decodedJson()['data']['id'];
        $pageId = (int) $this->post('wiki', 'save', ['title' => 'Devis du plombier', 'content' => 'Trois devis comparés.'])->decodedJson()['data']['id'];
        $pageInfo = (string) $this->app->shared->registry->find('wiki.page', (string) $pageId)['id'];
        $this->assertSame(200, $this->post('project', 'link', ['id' => $projectId, 'to' => $pageInfo])->status());
        $this->assertStringContains('Devis du plombier', $this->view('show/' . $projectId)['content']);
        $this->assertStringContains('1 élément lié', $this->view('show/' . $projectId)['banner']);

        $this->assertSame(200, $this->post('wiki', 'delete', ['id' => $pageId])->status());
        $show = $this->view('show/' . $projectId);
        $this->assertFalse(str_contains($show['content'], 'Devis du plombier'), 'page en corbeille absente de la fiche du projet');
        $this->assertFalse(str_contains($show['banner'], '1 élément lié'), 'ni comptée parmi les éléments liés');
        $this->assertFalse(str_contains($this->view('list')['content'], 'Devis du plombier'));
        $this->assertSame([], array_values(array_filter(
            (array) ($this->app->handle(Request::create('GET', '/m/project/lookup', ['q' => 'devis'], [], $this->headers()))->decodedJson()['data']['results'] ?? []),
            static fn (array $r): bool => $r['dataset'] === 'wiki.page'
        )), 'ni proposée à la liaison');

        $this->assertSame(200, $this->post('wiki', 'restore', ['id' => $pageId])->status());
        $this->assertStringContains('Devis du plombier', $this->view('show/' . $projectId)['content'], 'la page revient avec sa relation');
    }

    /** Une information en corbeille ne peut pas être liée par son identifiant global. */
    public function testLinkRefusesTrashedInformation(): void
    {
        $projectId = (int) $this->post('project', 'save', ['title' => 'Atelier photo'])->decodedJson()['data']['id'];
        $pageId = (int) $this->post('wiki', 'save', ['title' => 'Page jetée', 'content' => 'x'])->decodedJson()['data']['id'];
        $pageInfo = (string) $this->app->shared->registry->find('wiki.page', (string) $pageId)['id'];
        $this->post('wiki', 'delete', ['id' => $pageId]);
        $response = $this->post('project', 'link', ['id' => $projectId, 'to' => $pageInfo]);
        $this->assertSame(422, $response->status(), $response->body());
    }

    /** La migration de rattrapage marque au registre les projets déjà en corbeille, et se rejoue sans effet. */
    public function testCatchUpMigrationMarksExistingTrash(): void
    {
        $trashed = (int) $this->post('project', 'save', ['title' => 'Ancien projet'])->decodedJson()['data']['id'];
        $alive = (int) $this->post('project', 'save', ['title' => 'Projet vivant'])->decodedJson()['data']['id'];
        $this->app->db->update('project_project', ['deleted_at' => '2026-09-01 10:00:00'], 'id = :id', ['id' => $trashed]);
        $this->assertFalse($this->app->shared->registry->isTrashed('project.project', (string) $trashed));

        $migration = require dirname(__DIR__, 2) . '/modules/project/migrations/002_registry_trash.php';
        $migration($this->app->db);
        $this->assertSame('2026-09-01 10:00:00', $this->app->shared->registry->find('project.project', (string) $trashed)['trashed_at']);
        $this->assertNull($this->app->shared->registry->find('project.project', (string) $alive)['trashed_at']);
        $migration($this->app->db);
        $this->assertSame('2026-09-01 10:00:00', $this->app->shared->registry->find('project.project', (string) $trashed)['trashed_at'], 'rejouable sans effet');
    }
}
