<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Error\ForbiddenException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Kernel\Application;
use Atelier\Modules\Demo\DemoModule;
use Atelier\Modules\Demo\ItemRepository;
use Atelier\Security\Acl\AclService;
use Atelier\Testing\TestCase;

/**
 * Module Démonstration 1.1.0 : suppression logique des articles, corbeille du module et
 * corbeille globale, synchronisation avec le registre commun, ouverture par identifiant,
 * réinitialisation et rétention.
 */
final class DemoTrashTest extends TestCase
{
    private Application $app;
    private int $userId;

    public function setUp(): void
    {
        $_SESSION = [];
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        foreach (['demo', 'trash'] as $module) {
            $this->app->acl->setRule('user', $this->userId, AclService::module($module), 'admin', 'allow');
        }
        $this->app->acl->clearCache();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        $this->assertSame(200, $this->post('reset-items')['_status'], 'les 120 articles de démonstration sont générés');
    }

    // ----- Outils -----

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    private function post(string $route, array $data = [], string $module = 'demo'): array
    {
        $response = $this->app->handle(Request::create('POST', '/m/' . $module . '/' . $route, [], $data, $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function view(string $route, array $query = [], string $module = 'demo'): array
    {
        $response = $this->app->handle(Request::create('GET', '/m/' . $module . '/' . $route, $query, [], $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function raw(string $route, array $query = []): Response
    {
        return $this->app->handle(Request::create('GET', '/m/demo/' . $route, $query, [], ['X-CSRF-Token' => $this->app->csrf->token()]));
    }

    private function login(string $username): void
    {
        $this->app->auth->logout();
        $this->app->auth->login($username, 'Mot-de-passe-solide', '127.0.0.1');
    }

    /** Instance fraîche du module, dans le contexte de l'utilisateur connecté. */
    private function module(): DemoModule
    {
        $this->app->modules->reset();
        $this->app->modules->discover();
        [$module] = $this->app->modules->boot('demo', $this->app->context(Request::create('GET', '/')));
        $this->assertTrue($module instanceof DemoModule);
        return $module;
    }

    private function name(int $id): string
    {
        return (string) $this->app->db->scalar('SELECT name FROM demo_item WHERE id = :id', ['id' => $id]);
    }

    /** Attache un tag partagé à un article (l'inscrit au registre) et retourne l'identifiant du tag. */
    private function tag(int $id, string $tag): int
    {
        $result = $this->post('tag-attach', ['item' => $id, 'tag' => $tag]);
        $this->assertSame(200, $result['_status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        return (int) $result['data']['tag']['id'];
    }

    /** @return list<string> clés locales des articles de démonstration portant le tag */
    private function taggedKeys(int $tagId): array
    {
        $keys = [];
        foreach ($this->app->shared->tags->infosWithTag($tagId) as $info) {
            if ($info['dataset_code'] === DemoModule::DATASET) {
                $keys[] = (string) $info['local_key'];
            }
        }
        sort($keys);
        return $keys;
    }

    // ----- Tests -----

    public function testUnitDeleteMovesItemToTrashAndHidesItEverywhere(): void
    {
        $name = $this->name(5);
        $tagId = $this->tag(5, 'étagère-a');
        $this->tag(6, 'étagère-a');
        $this->assertSame(['5', '6'], $this->taggedKeys($tagId));

        $deleted = $this->post('delete', ['id' => 5]);
        $this->assertSame(200, $deleted['_status'], json_encode($deleted, JSON_UNESCAPED_UNICODE));
        $this->assertStringContains('placé dans la corbeille', (string) $deleted['message']);
        $this->assertTrue($deleted['directives']['refresh'] ?? false, 'la liste est rechargée');

        // Ligne conservée, marquée et attribuée : c'est une suppression logique.
        $row = $this->app->db->selectOne('SELECT deleted_at, deleted_by FROM demo_item WHERE id = 5');
        $this->assertNotNull($row, 'la ligne existe toujours');
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame($this->userId, (int) $row['deleted_by']);

        // Registre commun : marqué en corbeille, donc absent de la liste du tag ; le tag est conservé.
        $this->assertTrue($this->app->shared->registry->isTrashed(DemoModule::DATASET, '5'));
        $this->assertSame(['6'], $this->taggedKeys($tagId), 'infosWithTag écarte l’article en corbeille');

        // Listes, compteurs, recherches, export, sélecteurs : l'article a disparu.
        $tables = $this->view('tables', ['q' => $name]);
        $this->assertSame(0, $tables['data']['state']['total'], 'recherche du tableau');
        $this->assertSame(ItemRepository::SEED_COUNT - 1, $this->view('index')['data']['state']['items'], 'compteur de la vue d’ensemble');
        $this->assertSame(0, $this->post('quick-filter', ['q' => $name])['data']['total'], 'filtre instantané');
        $csv = $this->raw('export.csv', ['q' => $name]);
        $this->assertSame(200, $csv->status());
        $this->assertFalse(str_contains($csv->body(), $name), 'export CSV');
        $this->assertFalse(str_contains($this->view('shared')['data']['content'], $name), 'sélecteur de l’écran Données partagées');

        // Service intermodule : ni listé ni nommé.
        $service = $this->app->context(Request::create('GET', '/'))->moduleService('demo');
        $this->assertCount(ItemRepository::SEED_COUNT - 1, $service->items($this->userId));
        $this->assertSame('Article #5', $service->label($this->userId, 5));

        // Toute action courante le traite comme introuvable, en orientant vers la corbeille.
        $open = $this->view('item/5');
        $this->assertSame(404, $open['_status']);
        $this->assertStringContains('corbeille', json_encode($open, JSON_UNESCAPED_UNICODE));
        $this->assertSame(404, $this->view('shared', ['item' => 5])['_status']);
        $this->assertSame(404, $this->post('toggle', ['id' => 5])['_status']);
        $this->assertSame(404, $this->post('rename', ['id' => 5, 'name' => 'Nouveau'])['_status']);
        $this->assertSame(404, $this->post('delete', ['id' => 5])['_status'], 'déjà en corbeille');

        // La corbeille du module le montre.
        $trash = $this->view('trash');
        $this->assertSame(200, $trash['_status']);
        $this->assertStringContains($name, $trash['data']['content']);
        $this->assertSame(1, $trash['data']['state']['count']);
    }

    public function testBulkDeleteThenRestoreAndPurgeFromModuleAndGlobalTrash(): void
    {
        $tagId = $this->tag(1, 'lot');
        $this->tag(2, 'lot');
        $names = [1 => $this->name(1), 2 => $this->name(2), 3 => $this->name(3), 4 => $this->name(4)];

        $bulk = $this->post('bulk', ['op' => 'delete', 'ids' => [1, 2, 3, 4]]);
        $this->assertSame(200, $bulk['_status'], json_encode($bulk, JSON_UNESCAPED_UNICODE));
        $this->assertSame(4, $bulk['data']['count']);
        $this->assertStringContains('4 articles placés dans la corbeille', (string) $bulk['message']);
        $this->assertSame(4, $this->app->db->count('SELECT COUNT(*) FROM demo_item WHERE id IN (1, 2, 3, 4) AND deleted_at IS NOT NULL'));
        $this->assertSame([], $this->taggedKeys($tagId), 'aucun article du lot sous son tag');
        $this->assertSame(ItemRepository::SEED_COUNT - 4, $this->view('tables')['data']['state']['total']);

        $again = $this->post('bulk', ['op' => 'delete', 'ids' => [1, 2]]);
        $this->assertSame('warning', $again['level'] ?? null, 'sélection déjà en corbeille : avertissement');
        $this->assertStringContains('déjà dans la corbeille', (string) $again['message']);

        // Corbeille globale : les quatre articles, sous le nom du jeu de données, avec leurs droits.
        $global = $this->view('list', ['source' => 'module', 'module' => 'demo'], 'trash');
        $this->assertSame(200, $global['_status'], json_encode($global, JSON_UNESCAPED_UNICODE));
        foreach ($names as $name) {
            $this->assertStringContains($name, $global['data']['content']);
        }
        $this->assertStringContains('Articles de démonstration', $global['data']['content']);
        $provided = $this->module()->trashItems();
        $this->assertCount(4, $provided);
        $this->assertSame(DemoModule::DATASET, $provided[0]['dataset']);
        $this->assertSame($this->userId, $provided[0]['deleted_by']);
        $this->assertNotNull($provided[0]['purge_at']);

        // Restauration depuis la corbeille du module : article et tag réapparaissent.
        $restored = $this->post('restore', ['id' => 1]);
        $this->assertSame(200, $restored['_status'], json_encode($restored, JSON_UNESCAPED_UNICODE));
        $this->assertStringContains('restauré', (string) $restored['message']);
        $this->assertSame(1, $this->view('tables', ['q' => $names[1]])['data']['state']['total']);
        $this->assertFalse($this->app->shared->registry->isTrashed(DemoModule::DATASET, '1'));
        $this->assertSame(['1'], $this->taggedKeys($tagId));
        $this->assertSame(404, $this->post('restore', ['id' => 1])['_status'], 'n’est plus en corbeille');

        // Restauration depuis la corbeille globale : même chemin, même effet sur le registre.
        $this->assertSame(200, $this->post('restore', ['key' => 'module:demo:2'], 'trash')['_status']);
        $this->assertSame(['1', '2'], $this->taggedKeys($tagId));
        $this->assertSame(200, $this->view('item/2')['_status']);

        // Purge depuis le module : ligne effacée et retrait du registre.
        $this->app->shared->registry->register(DemoModule::DATASET, '3', $names[3], $this->userId);
        $purged = $this->post('purge', ['id' => 3]);
        $this->assertSame(200, $purged['_status'], json_encode($purged, JSON_UNESCAPED_UNICODE));
        $this->assertStringContains('supprimé définitivement', (string) $purged['message']);
        $this->assertNull($this->app->db->selectOne('SELECT id FROM demo_item WHERE id = 3'));
        $this->assertNull($this->app->shared->registry->find(DemoModule::DATASET, '3'));

        // Purge depuis la corbeille globale.
        $this->assertSame(200, $this->post('purge', ['key' => 'module:demo:4'], 'trash')['_status']);
        $this->assertNull($this->app->db->selectOne('SELECT id FROM demo_item WHERE id = 4'));

        // Un article vivant ne se purge pas : la purge n'agit que sur la corbeille.
        $this->assertSame(404, $this->post('purge', ['id' => 10])['_status']);
        $this->assertNotNull($this->app->db->selectOne('SELECT id FROM demo_item WHERE id = 10'));
        $this->assertSame(0, $this->view('trash')['data']['state']['count']);
    }

    public function testRightsAreCheckedServerSide(): void
    {
        $this->post('delete', ['id' => 7]);
        $bob = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $bob, AclService::module('demo'), 'update', 'allow');
        $this->app->acl->setRule('user', $bob, AclService::module('trash'), 'open', 'allow');
        $this->app->acl->clearCache();
        $this->login('bob');

        // Sans « delete » : ni suppression, ni corbeille, ni restauration, ni purge.
        $this->assertSame(403, $this->post('delete', ['id' => 8])['_status']);
        $this->assertSame(403, $this->post('bulk', ['op' => 'delete', 'ids' => [8]])['_status']);
        $this->assertSame(403, $this->view('trash')['_status']);
        $this->assertSame(403, $this->post('restore', ['id' => 7])['_status']);
        $this->assertSame(403, $this->post('purge', ['id' => 7])['_status']);

        // Corbeille globale : rien n'est proposé, et le fournisseur refuse même un appel direct.
        $module = $this->module();
        $this->assertSame([], $module->trashItems());
        $this->assertFalse(str_contains((string) $this->view('list', [], 'trash')['data']['content'], $this->name(7)));
        $this->assertThrows(ForbiddenException::class, static fn () => $module->restoreTrashItem('7'));
        $this->assertThrows(ForbiddenException::class, static fn () => $module->purgeTrashItem('7'));
        $this->assertNotSame(200, $this->post('restore', ['key' => 'module:demo:7'], 'trash')['_status']);

        $this->assertNotNull($this->app->db->selectOne('SELECT id FROM demo_item WHERE id = 7 AND deleted_at IS NOT NULL'), 'toujours en corbeille');
        $this->assertNotNull($this->app->db->selectOne('SELECT id FROM demo_item WHERE id = 8 AND deleted_at IS NULL'), 'toujours vivant');
    }

    public function testOpenRouteResetAndRetention(): void
    {
        // openRoute déclarée dans le manifeste et servie par une vue existante.
        $dataset = null;
        foreach ($this->app->modules->get('demo')->manifest->datasets() as $candidate) {
            if ($candidate['code'] === DemoModule::DATASET) {
                $dataset = $candidate;
            }
        }
        $this->assertSame('item/{key}', $dataset['openRoute'] ?? null);
        $opened = $this->view('item/12');
        $this->assertSame(200, $opened['_status'], json_encode($opened, JSON_UNESCAPED_UNICODE));
        $this->assertStringContains($this->name(12), $opened['data']['content']);
        $this->assertSame(12, $opened['data']['state']['item']);
        $this->assertSame(404, $this->view('item/999')['_status']);

        // Réinitialisation : les articles de départ sortent de la corbeille (table et registre),
        // les articles hors jeu de départ sont effacés physiquement (outil de démonstration).
        $tagId = $this->tag(8, 'remis');
        $this->post('delete', ['id' => 8]);
        $this->app->db->insert('demo_item', ['id' => 121, 'name' => 'Article en trop', 'category' => 'Outillage', 'quantity' => 1, 'price' => 100, 'active' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->app->shared->registry->register(DemoModule::DATASET, '121', 'Article en trop', $this->userId);
        $this->assertSame(200, $this->post('reset-items')['_status']);
        $this->assertSame(200, $this->view('item/8')['_status']);
        $this->assertSame(['8'], $this->taggedKeys($tagId));
        $this->assertNull($this->app->db->selectOne('SELECT id FROM demo_item WHERE id = 121'));
        $this->assertNull($this->app->shared->registry->find(DemoModule::DATASET, '121'));
        $this->assertStringContains('déjà présents', $this->module()->seed(), 'le seed reste idempotent');

        // Rétention : seul l'article expiré est purgé (ligne et registre) ; il n'est plus proposé à la restauration.
        $this->post('bulk', ['op' => 'delete', 'ids' => [9, 10]]);
        $this->app->shared->registry->register(DemoModule::DATASET, '9', $this->name(9), $this->userId);
        $this->app->db->execute("UPDATE demo_item SET deleted_at = '2026-01-01 00:00:00' WHERE id = 9");
        $module = $this->module();
        $this->assertSame(['10'], array_column($module->trashItems(), 'id'), 'l’article expiré n’est plus restaurable');
        $this->assertStringContains('1 article(s) purgé(s)', $module->purge());
        $this->assertNull($this->app->db->selectOne('SELECT id FROM demo_item WHERE id = 9'));
        $this->assertNull($this->app->shared->registry->find(DemoModule::DATASET, '9'));
        $this->assertNotNull($this->app->db->selectOne('SELECT id FROM demo_item WHERE id = 10 AND deleted_at IS NOT NULL'));
    }
}
