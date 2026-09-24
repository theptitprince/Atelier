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
 * Module Budget 1.2.0 : registre commun et corbeille, tags des opérations, routes d'ouverture.
 *
 * Symptôme d'origine : une opération (ou toutes celles d'un compte) mise en corbeille restait listée
 * sous ses tags, dans l'Explorateur et dans les éléments liés d'un projet, avec un lien menant à une
 * erreur 404, parce que le registre commun ignorait la corbeille du module.
 */
final class BudgetRegistryTest extends TestCase
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
        foreach (['budget', 'trash'] as $module) {
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

    private function post(string $route, array $data = [], string $module = 'budget'): array
    {
        $response = $this->app->handle(Request::create('POST', '/m/' . $module . '/' . $route, [], $data, $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function view(string $route, array $query = [], string $module = 'budget'): array
    {
        $response = $this->app->handle(Request::create('GET', '/m/' . $module . '/' . $route, $query, [], $this->headers()));
        $json = $response->decodedJson();
        $json['_status'] = $response->status();
        return $json;
    }

    private function createAccount(string $name = 'Courant'): int
    {
        $result = $this->post('account-save', ['name' => $name, 'kind' => 'checking', 'initial_balance' => '1 000']);
        $this->assertSame(200, $result['_status'], json_encode($result));
        return (int) $result['data']['id'];
    }

    private function createTransaction(int $account, string $label, string $tags = ''): int
    {
        $result = $this->post('transaction-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '12,50', 'done_at' => '2026-09-12', 'label' => $label, 'tags' => $tags]);
        $this->assertSame(200, $result['_status'], json_encode($result));
        return (int) $result['data']['id'];
    }

    /** Identifiant de registre d'une opération (inscrite par le module à l'enregistrement). */
    private function infoOf(int $transaction): string
    {
        $info = $this->app->shared->registry->find('budget.transaction', (string) $transaction);
        $this->assertNotNull($info, 'opération ' . $transaction . ' inscrite au registre');
        return (string) $info['id'];
    }

    /**
     * Inscrit et tague une opération par les services partagés, comme le ferait un autre module :
     * les scénarios de corbeille ne dépendent ainsi pas du champ de tags du formulaire.
     */
    private function tagged(int $transaction, string $tag): string
    {
        $infoId = $this->app->shared->registry->register('budget.transaction', (string) $transaction, 'Opération ' . $transaction, $this->userId);
        $this->app->shared->tags->attach($infoId, $tag, TagService::SHARED, $this->userId);
        return $infoId;
    }

    /** @return list<string> clés locales des opérations portant le tag (vue « Tags ») */
    private function taggedKeys(string $tag): array
    {
        $found = $this->app->shared->tags->findOrCreate($tag, TagService::SHARED);
        $rows = $this->app->shared->tags->infosWithTag((int) $found['id']);
        return array_values(array_map(static fn (array $r): string => (string) $r['local_key'], array_filter($rows, static fn (array $r): bool => $r['dataset_code'] === 'budget.transaction')));
    }

    /** @return list<string> clés locales des opérations liées à l'information (vue « éléments liés ») */
    private function relatedKeys(string $infoId): array
    {
        $rows = $this->app->shared->relations->relationsOf($infoId);
        return array_values(array_map(static fn (array $r): string => (string) $r['other_key'], array_filter($rows, static fn (array $r): bool => $r['other_dataset'] === 'budget.transaction')));
    }

    public function testTransactionTagsFromFormAreStoredShownAndSearchable(): void
    {
        $account = $this->createAccount();
        $id = $this->createTransaction($account, 'Courses', 'alimentaire, #Maison');

        // Relecture : registre, tags partagés, formulaire (composant commun) et liste des opérations.
        $names = array_map(static fn (array $t): string => (string) $t['name'], $this->app->shared->tags->tagsOf($this->infoOf($id)));
        sort($names, SORT_FLAG_CASE | SORT_STRING);
        $this->assertSame(['alimentaire', 'Maison'], $names);
        $this->assertSame([(string) $id], $this->taggedKeys('alimentaire'));
        $form = $this->view('transaction/' . $id . '/edit')['data']['content'];
        $this->assertMatches('/<input[^>]*name="tags"[^>]*value="alimentaire, Maison"[^>]*data-tags-input/', $form);
        $this->assertStringContains('data-tags-input', $this->view('transaction/new')['data']['content']);
        $list = $this->view('transactions')['data']['content'];
        $this->assertStringContains('<span class="chip">alimentaire</span>', $list);

        // Modification : la liste des tags est remplacée.
        $result = $this->post('transaction-save', ['id' => $id, 'account_id' => $account, 'type' => 'expense', 'amount' => '12,50', 'done_at' => '2026-09-12', 'label' => 'Courses', 'tags' => 'alimentaire']);
        $this->assertSame(200, $result['_status'], json_encode($result));
        $this->assertSame([], $this->taggedKeys('maison'));
        $this->assertSame([(string) $id], $this->taggedKeys('alimentaire'));

        // Validation : un tag trop long est refusé près du champ.
        $refused = $this->post('transaction-save', ['id' => $id, 'account_id' => $account, 'type' => 'expense', 'amount' => '12,50', 'done_at' => '2026-09-12', 'label' => 'Courses', 'tags' => str_repeat('x', 61)]);
        $this->assertSame(422, $refused['_status']);
    }

    public function testTrashedTransactionLeavesTagsAndRelatedItemsUntilRestored(): void
    {
        $account = $this->createAccount();
        $id = $this->createTransaction($account, 'Courses');
        $info = $this->tagged($id, 'alimentaire');
        $note = $this->app->shared->registry->register('notes.note', '1', 'Liste de courses', $this->userId);
        $this->app->shared->relations->relate('related', $note, $info, $this->userId);
        $this->assertSame([(string) $id], $this->taggedKeys('alimentaire'));
        $this->assertSame([(string) $id], $this->relatedKeys($note));

        // Non-régression : une opération en corbeille ne doit plus apparaître sous son tag ni parmi les
        // éléments liés (le lien menait à une erreur 404).
        $this->assertSame(200, $this->post('transaction-delete', ['id' => $id])['_status']);
        $this->assertSame(404, $this->view('transaction/' . $id . '/edit')['_status']);
        $this->assertTrue($this->app->shared->registry->isTrashed('budget.transaction', (string) $id));
        $this->assertSame([], $this->taggedKeys('alimentaire'));
        $this->assertSame([], $this->relatedKeys($note));
        $this->assertSame(0, $this->app->shared->relations->countFor($note));

        // Restauration depuis la vue du module : l'opération réapparaît, tags et relation intacts.
        $this->assertSame(200, $this->post('trash-restore', ['id' => 'transaction:' . $id])['_status']);
        $this->assertSame([(string) $id], $this->taggedKeys('alimentaire'));
        $this->assertSame([(string) $id], $this->relatedKeys($note));

        // Suppression groupée puis restauration depuis la corbeille globale.
        $this->assertSame(200, $this->post('transactions-bulk', ['ids' => [$id], 'op' => 'delete'])['_status']);
        $this->assertSame([], $this->taggedKeys('alimentaire'));
        $restored = $this->post('restore', ['key' => 'module:budget:transaction:' . $id], 'trash');
        $this->assertSame(200, $restored['_status'], json_encode($restored));
        $this->assertSame([(string) $id], $this->taggedKeys('alimentaire'));

        // Service intermodule (coût d'une intervention retiré par le module Entretien).
        $service = $this->app->context(Request::create('GET', '/'))->moduleService('budget');
        $external = (int) $service->recordExternal($this->userId, 'maintenance_log:9', 'maintenance', ['label' => 'Vidange', 'amount' => -9000, 'done_at' => '2026-09-10']);
        $this->app->shared->tags->attach($this->app->shared->registry->register('budget.transaction', (string) $external, 'Vidange'), 'alimentaire');
        $this->assertContains((string) $external, $this->taggedKeys('alimentaire'));
        $this->assertTrue($service->removeExternal($this->userId, 'maintenance_log:9'));
        $this->assertFalse(in_array((string) $external, $this->taggedKeys('alimentaire'), true), 'opération externe en corbeille');

        // La purge définitive retire toujours l'information du registre.
        $this->assertSame(200, $this->post('transaction-delete', ['id' => $id])['_status']);
        $this->assertSame(200, $this->post('trash-purge', ['id' => 'transaction:' . $id])['_status']);
        $this->assertNull($this->app->shared->registry->find('budget.transaction', (string) $id));
    }

    public function testAccountTrashMasksItsTransactionsInTheRegistry(): void
    {
        $account = $this->createAccount('Courant');
        $kept = $this->createTransaction($account, 'Courses');
        $alone = $this->createTransaction($account, 'Boulangerie');
        $keptInfo = $this->tagged($kept, 'alimentaire');
        $this->tagged($alone, 'alimentaire');
        $note = $this->app->shared->registry->register('notes.note', '1', 'Liste', $this->userId);
        $this->app->shared->relations->relate('related', $note, $keptInfo, $this->userId);
        $accountInfo = $this->app->shared->registry->register('budget.account', (string) $account, 'Courant', $this->userId);
        $this->app->shared->tags->attach($accountInfo, 'alimentaire');

        // « Boulangerie » part d'abord seule à la corbeille, puis le compte entier.
        $this->assertSame(200, $this->post('transaction-delete', ['id' => $alone])['_status']);
        $this->assertSame(200, $this->post('account-delete', ['id' => $account])['_status']);

        // Non-régression : les opérations masquées par le compte en corbeille ne restent pas visibles
        // sous leurs tags ni parmi les éléments liés.
        $this->assertSame([], $this->taggedKeys('alimentaire'));
        $this->assertSame([], $this->relatedKeys($note));
        $this->assertTrue($this->app->shared->registry->isTrashed('budget.account', (string) $account));

        // Restaurer seule une opération d'un compte en corbeille la laisse masquée (le compte l'est toujours).
        $this->assertSame(200, $this->post('trash-restore', ['id' => 'transaction:' . $alone])['_status']);
        $this->assertTrue($this->app->shared->registry->isTrashed('budget.transaction', (string) $alone));
        // Elle repart à la corbeille pour son propre compte (l'écran ne l'atteint plus : son compte est en corbeille).
        $this->app->db->execute("UPDATE budget_transaction SET deleted_at = '2026-09-22 09:00:00' WHERE id = :id", ['id' => $alone]);

        // Restauration du compte : ses opérations vivantes réapparaissent, pas celle qui était en corbeille pour son propre compte.
        $this->assertSame(200, $this->post('trash-restore', ['id' => 'account:' . $account])['_status']);
        $this->assertSame([(string) $kept], $this->taggedKeys('alimentaire'));
        $this->assertSame([(string) $kept], $this->relatedKeys($note));
        $this->assertFalse($this->app->shared->registry->isTrashed('budget.account', (string) $account));
        $this->assertTrue($this->app->shared->registry->isTrashed('budget.transaction', (string) $alone));

        $this->assertSame(200, $this->post('trash-restore', ['id' => 'transaction:' . $alone])['_status']);
        $keys = $this->taggedKeys('alimentaire');
        sort($keys);
        $expected = [(string) $kept, (string) $alone];
        sort($expected);
        $this->assertSame($expected, $keys);
    }

    public function testOpenRoutesOfSharedDatasetsRespond(): void
    {
        $account = $this->createAccount();
        $category = (int) $this->post('category-save', ['name' => 'Alimentation', 'kind' => 'expense'])['data']['id'];
        $transaction = $this->createTransaction($account, 'Courses');
        $keys = ['budget.account' => $account, 'budget.category' => $category, 'budget.transaction' => $transaction];
        $routes = [];
        foreach ($this->app->modules->get('budget')->manifest->datasets() as $dataset) {
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
        $account = $this->createAccount('Courant');
        $other = $this->createAccount('Livret');
        $masked = $this->createTransaction($account, 'Courses');
        $trashed = $this->createTransaction($other, 'Cinéma');
        $alive = $this->createTransaction($other, 'Boulangerie');
        foreach ([$masked, $trashed, $alive] as $transaction) {
            $this->tagged($transaction, 'alimentaire');
        }
        $this->app->shared->registry->register('budget.account', (string) $account, 'Courant');

        // État hérité d'avant la correction : corbeille posée dans les tables, registre ignorant.
        $this->app->db->execute("UPDATE budget_account SET deleted_at = '2026-09-01 08:00:00' WHERE id = :id", ['id' => $account]);
        $this->app->db->execute("UPDATE budget_transaction SET deleted_at = '2026-09-02 08:00:00' WHERE id = :id", ['id' => $trashed]);
        $this->app->db->execute('UPDATE info_registry SET trashed_at = NULL');
        $this->assertCount(3, $this->taggedKeys('alimentaire'), 'avant la migration : éléments en corbeille encore listés');

        $migration = require dirname(__DIR__, 2) . '/modules/budget/migrations/003_registry_trash.php';
        $migration($this->app->db);
        $this->assertSame([(string) $alive], $this->taggedKeys('alimentaire'));
        $this->assertSame('2026-09-01 08:00:00', $this->app->shared->registry->find('budget.transaction', (string) $masked)['trashed_at'], 'date du compte pour une opération masquée');
        $this->assertSame('2026-09-02 08:00:00', $this->app->shared->registry->find('budget.transaction', (string) $trashed)['trashed_at']);
        $this->assertSame('2026-09-01 08:00:00', $this->app->shared->registry->find('budget.account', (string) $account)['trashed_at']);

        // Rejouée, elle ne change rien.
        $before = $this->app->db->select('SELECT id, trashed_at FROM info_registry ORDER BY id');
        $migration($this->app->db);
        $this->assertSame($before, $this->app->db->select('SELECT id, trashed_at FROM info_registry ORDER BY id'));
    }
}
