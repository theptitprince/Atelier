<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Shared\TagService;
use Atelier\Support\Clock;
use Atelier\Testing\TestCase;

/**
 * Module Entretien 1.3.0 : registre commun et corbeille, tags des interventions, routes d'ouverture.
 *
 * Symptôme d'origine : un équipement, une tâche ou une intervention mis en corbeille (et les tâches et
 * interventions d'un équipement en corbeille) restaient listés sous leurs tags, dans l'Explorateur et
 * dans les éléments liés d'un projet, avec un lien menant à une erreur 404.
 */
final class MaintenanceRegistryTest extends TestCase
{
    private Application $app;
    private int $userId;

    public function setUp(): void
    {
        $_SESSION = [];
        Clock::freeze(new \DateTimeImmutable('2026-09-22 10:00:00', new \DateTimeZone('UTC')));
        $this->app = $this->application();
        $this->app->synchronizer()->syncAll();
        $this->userId = $this->app->users->create(['username' => 'alice', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');
        foreach (['maintenance', 'trash'] as $module) {
            $this->app->acl->setRule('user', $this->userId, AclService::module($module), 'admin', 'allow');
        }
        $this->app->acl->clearCache();
    }

    public function tearDown(): void
    {
        Clock::freeze(null);
    }

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    private function post(string $route, array $data = [], string $module = 'maintenance'): array
    {
        $response = $this->app->handle(Request::create('POST', '/m/' . $module . '/' . $route, [], $data, $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function view(string $route, array $query = []): array
    {
        $response = $this->app->handle(Request::create('GET', '/m/maintenance/' . $route, $query, [], $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function createAsset(string $name = 'Voiture'): int
    {
        $result = $this->post('asset-save', ['name' => $name, 'category' => 'vehicle', 'meter_unit' => 'km', 'meter_value' => '61 200', 'tags' => 'garage']);
        $this->assertSame(200, $result['_status'], json_encode($result));
        return (int) $result['data']['id'];
    }

    private function createJob(int $asset, string $title): int
    {
        $result = $this->post('job-save', ['asset_id' => $asset, 'title' => $title, 'kind' => 'preventive', 'interval_days' => '365', 'tags' => 'garage']);
        $this->assertSame(200, $result['_status'], json_encode($result));
        return (int) $result['data']['id'];
    }

    private function createLog(int $asset, string $title, string $tags = ''): int
    {
        $result = $this->post('log-save', ['asset_id' => $asset, 'done_at' => '2026-09-01', 'title' => $title, 'tags' => $tags]);
        $this->assertSame(200, $result['_status'], json_encode($result));
        return (int) $result['data']['id'];
    }

    private function infoOf(string $dataset, int $id): string
    {
        $info = $this->app->shared->registry->find($dataset, (string) $id);
        $this->assertNotNull($info, $dataset . ' ' . $id . ' inscrit au registre');
        return (string) $info['id'];
    }

    /** Tague une information par les services partagés (indépendamment des formulaires du module). */
    private function tag(string $dataset, int $id, string $tag): string
    {
        $infoId = $this->infoOf($dataset, $id);
        $this->app->shared->tags->attach($infoId, $tag, TagService::SHARED, $this->userId);
        return $infoId;
    }

    /** @return list<string> « jeu:clé » des informations portant le tag (vue « Tags ») */
    private function taggedKeys(string $tag): array
    {
        $found = $this->app->shared->tags->findOrCreate($tag, TagService::SHARED);
        $keys = array_map(static fn (array $r): string => $r['dataset_code'] . ':' . $r['local_key'], $this->app->shared->tags->infosWithTag((int) $found['id']));
        sort($keys);
        return $keys;
    }

    /** @return list<string> « jeu:clé » des informations liées (vue « éléments liés ») */
    private function relatedKeys(string $infoId): array
    {
        $keys = array_map(static fn (array $r): string => $r['other_dataset'] . ':' . $r['other_key'], $this->app->shared->relations->relationsOf($infoId));
        sort($keys);
        return $keys;
    }

    /** @param list<string> $keys @return list<string> */
    private function sorted(array $keys): array
    {
        sort($keys);
        return $keys;
    }

    public function testLogTagsFromFormAreStoredAndSearchable(): void
    {
        $car = $this->createAsset();
        $log = $this->createLog($car, 'Vidange', 'moteur, #Huile');
        $names = array_map(static fn (array $t): string => (string) $t['name'], $this->app->shared->tags->tagsOf($this->infoOf('maintenance.log', $log)));
        sort($names, SORT_FLAG_CASE | SORT_STRING);
        $this->assertSame(['Huile', 'moteur'], $names);
        $this->assertSame(['maintenance.log:' . $log], $this->taggedKeys('moteur'));
        $this->assertStringContains('data-tags-input', $this->view('log/new', ['asset' => $car])['data']['content']);
        $this->assertMatches('/<input[^>]*name="tags"[^>]*value="Huile, moteur"[^>]*data-tags-input/', $this->view('log/' . $log . '/edit')['data']['content']);
        $this->assertStringContains('<span class="chip">moteur</span>', $this->view('history')['data']['content']);

        // Modification : la liste des tags est remplacée ; un tag trop long est refusé.
        $result = $this->post('log-save', ['id' => $log, 'asset_id' => $car, 'done_at' => '2026-09-01', 'title' => 'Vidange', 'tags' => 'moteur']);
        $this->assertSame(200, $result['_status'], json_encode($result));
        $this->assertSame([], $this->taggedKeys('huile'));
        $this->assertSame(422, $this->post('log-save', ['id' => $log, 'asset_id' => $car, 'done_at' => '2026-09-01', 'title' => 'Vidange', 'tags' => str_repeat('x', 61)])['_status']);
    }

    public function testTrashedJobAndLogLeaveTagsAndRelatedItemsUntilRestored(): void
    {
        $car = $this->createAsset();
        $job = $this->createJob($car, 'Vidange');
        $log = $this->createLog($car, 'Pneus');
        $this->tag('maintenance.log', $log, 'garage');
        $project = $this->app->shared->registry->register('project.project', '1', 'Voiture 2026', $this->userId);
        $this->app->shared->relations->relate('related', $project, $this->infoOf('maintenance.job', $job), $this->userId);
        $this->app->shared->relations->relate('related', $project, $this->infoOf('maintenance.log', $log), $this->userId);
        $all = $this->sorted(['maintenance.asset:' . $car, 'maintenance.job:' . $job, 'maintenance.log:' . $log]);
        $this->assertSame($all, $this->taggedKeys('garage'));

        // Non-régression : tâche puis intervention en corbeille, absentes des tags et des éléments liés.
        $this->assertSame(200, $this->post('job-delete', ['id' => $job])['_status']);
        $this->assertSame(200, $this->post('log-delete', ['id' => $log])['_status']);
        $this->assertSame(['maintenance.asset:' . $car], $this->taggedKeys('garage'));
        $this->assertSame([], $this->relatedKeys($project));

        // Restauration : depuis la vue du module (tâche) et depuis la corbeille globale (intervention).
        $this->assertSame(200, $this->post('trash-restore', ['id' => 'job:' . $job])['_status']);
        $this->assertSame(['maintenance.job:' . $job], $this->relatedKeys($project));
        $restored = $this->post('restore', ['key' => 'module:maintenance:log:' . $log], 'trash');
        $this->assertSame(200, $restored['_status'], json_encode($restored));
        $this->assertSame($all, $this->taggedKeys('garage'));
        $this->assertSame($this->sorted(['maintenance.job:' . $job, 'maintenance.log:' . $log]), $this->relatedKeys($project));

        // La purge définitive retire toujours l'information du registre.
        $this->post('log-delete', ['id' => $log]);
        $this->assertSame(200, $this->post('trash-purge', ['id' => 'log:' . $log])['_status']);
        $this->assertNull($this->app->shared->registry->find('maintenance.log', (string) $log));
    }

    /**
     * Non-régression : une intervention masquée par son équipement en corbeille restait
     * modifiable par son adresse directe, alors qu'une tâche dans le même cas répondait 404.
     */
    public function testLogOfATrashedAssetIsNotEditable(): void
    {
        $car = $this->createAsset();
        $log = $this->createLog($car, 'Pneus');
        $this->assertSame(200, $this->view('log/' . $log . '/edit')['_status']);

        $this->assertSame(200, $this->post('asset-delete', ['id' => $car])['_status']);
        $this->assertSame(404, $this->view('log/' . $log . '/edit')['_status'], 'masquée avec son équipement');
        $this->assertSame(404, $this->post('log-delete', ['id' => $log])['_status']);

        $this->assertSame(200, $this->post('asset-restore', ['id' => $car])['_status']);
        $this->assertSame(200, $this->view('log/' . $log . '/edit')['_status'], 'revient avec lui');
    }

    public function testAssetTrashMasksItsJobsAndLogsInTheRegistry(): void
    {
        $car = $this->createAsset();
        $job = $this->createJob($car, 'Vidange');
        $alone = $this->createJob($car, 'Contrôle technique');
        $log = $this->createLog($car, 'Pneus');
        $this->tag('maintenance.log', $log, 'garage');
        $project = $this->app->shared->registry->register('project.project', '1', 'Voiture 2026', $this->userId);
        $this->app->shared->relations->relate('related', $project, $this->infoOf('maintenance.log', $log), $this->userId);

        // « Contrôle technique » part d'abord seul à la corbeille, puis l'équipement entier.
        $this->assertSame(200, $this->post('job-delete', ['id' => $alone])['_status']);
        $this->assertSame(200, $this->post('asset-delete', ['id' => $car])['_status']);

        // Non-régression : les tâches et interventions masquées par l'équipement ne restent pas visibles.
        $this->assertSame([], $this->taggedKeys('garage'));
        $this->assertSame([], $this->relatedKeys($project));

        // Restauration de l'équipement : ses éléments vivants réapparaissent, pas la tâche en corbeille pour son propre compte.
        $this->assertSame(200, $this->post('asset-restore', ['id' => $car])['_status']);
        $this->assertSame($this->sorted(['maintenance.asset:' . $car, 'maintenance.job:' . $job, 'maintenance.log:' . $log]), $this->taggedKeys('garage'));
        $this->assertSame(['maintenance.log:' . $log], $this->relatedKeys($project));
        $this->assertTrue($this->app->shared->registry->isTrashed('maintenance.job', (string) $alone));

        // Restaurer une tâche dont l'équipement est en corbeille restaure l'équipement… et ses autres éléments.
        $this->assertSame(200, $this->post('asset-delete', ['id' => $car])['_status']);
        $this->assertSame([], $this->taggedKeys('garage'));
        $restored = $this->post('restore', ['key' => 'module:maintenance:job:' . $alone], 'trash');
        $this->assertSame(200, $restored['_status'], json_encode($restored));
        $this->assertSame($this->sorted(['maintenance.asset:' . $car, 'maintenance.job:' . $job, 'maintenance.job:' . $alone, 'maintenance.log:' . $log]), $this->taggedKeys('garage'));
    }

    public function testOpenRoutesOfSharedDatasetsRespond(): void
    {
        $car = $this->createAsset();
        $job = $this->createJob($car, 'Vidange');
        $log = $this->createLog($car, 'Pneus');
        $keys = ['maintenance.asset' => $car, 'maintenance.job' => $job, 'maintenance.log' => $log];
        $routes = [];
        foreach ($this->app->modules->get('maintenance')->manifest->datasets() as $dataset) {
            $routes[$dataset['code']] = $dataset['openRoute'] ?? null;
        }
        foreach ($keys as $code => $key) {
            $this->assertNotNull($routes[$code] ?? null, $code . ' déclare une route d’ouverture');
            $route = str_replace('{key}', (string) $key, (string) $routes[$code]);
            $result = $this->view($route);
            $this->assertSame(200, $result['_status'], $code . ' → ' . $route . ' : ' . json_encode($result));
        }
    }

    public function testMigrationMarksExistingTrashAndIsReplayable(): void
    {
        $car = $this->createAsset('Voiture');
        $mower = $this->createAsset('Tondeuse');
        $maskedJob = $this->createJob($car, 'Vidange');
        $maskedLog = $this->createLog($car, 'Pneus');
        $trashedJob = $this->createJob($mower, 'Affûtage');
        $aliveLog = $this->createLog($mower, 'Nettoyage');
        $this->tag('maintenance.log', $maskedLog, 'garage');
        $this->tag('maintenance.log', $aliveLog, 'garage');

        // État hérité d'avant la correction : corbeille posée dans les tables, registre ignorant.
        $this->app->db->execute("UPDATE maintenance_asset SET deleted_at = '2026-09-01 08:00:00' WHERE id = :id", ['id' => $car]);
        $this->app->db->execute("UPDATE maintenance_job SET deleted_at = '2026-09-02 08:00:00' WHERE id = :id", ['id' => $trashedJob]);
        $this->app->db->execute('UPDATE info_registry SET trashed_at = NULL');
        $this->assertCount(6, $this->taggedKeys('garage'), 'avant la migration : éléments en corbeille encore listés');

        $migration = require dirname(__DIR__, 2) . '/modules/maintenance/migrations/003_registry_trash.php';
        $migration($this->app->db);
        $this->assertSame($this->sorted(['maintenance.asset:' . $mower, 'maintenance.log:' . $aliveLog]), $this->taggedKeys('garage'));
        $registry = $this->app->shared->registry;
        $this->assertSame('2026-09-01 08:00:00', $registry->find('maintenance.asset', (string) $car)['trashed_at']);
        $this->assertSame('2026-09-01 08:00:00', $registry->find('maintenance.job', (string) $maskedJob)['trashed_at'], 'date de l’équipement pour une tâche masquée');
        $this->assertSame('2026-09-01 08:00:00', $registry->find('maintenance.log', (string) $maskedLog)['trashed_at']);
        $this->assertSame('2026-09-02 08:00:00', $registry->find('maintenance.job', (string) $trashedJob)['trashed_at']);

        $before = $this->app->db->select('SELECT id, trashed_at FROM info_registry ORDER BY id');
        $migration($this->app->db);
        $this->assertSame($before, $this->app->db->select('SELECT id, trashed_at FROM info_registry ORDER BY id'));
    }
}
