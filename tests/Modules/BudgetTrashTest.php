<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Clock;
use Atelier\Testing\TestCase;

/**
 * Module Budget 1.1.0 : corbeille (suppression logique) des opérations, comptes, récurrences, objectifs
 * et économies ; exclusion des soldes et listes ; corbeille globale ; purge ; catégories protégées.
 */
final class BudgetTrashTest extends TestCase
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

    private function createAccount(string $name = 'Courant', string $initial = '1 000'): int
    {
        $result = $this->post('account-save', ['name' => $name, 'kind' => 'checking', 'initial_balance' => $initial]);
        $this->assertSame(200, $result['_status'], json_encode($result));
        return (int) $result['data']['id'];
    }

    private function createTransaction(int $account, string $amount, string $label, string $type = 'expense', ?int $category = null): int
    {
        $result = $this->post('transaction-save', ['account_id' => $account, 'type' => $type, 'amount' => $amount, 'done_at' => '2026-09-12', 'label' => $label, 'payee' => 'Carrefour', 'category_id' => $category]);
        $this->assertSame(200, $result['_status'], json_encode($result));
        return (int) $result['data']['id'];
    }

    /** Badge du module (route propre : la route /core/badges passerait par le cache de l'agrégateur de la corbeille). */
    private function badge(): int
    {
        return (int) $this->view('badge')['data']['count'];
    }

    /** @return list<array<string, mixed>> éléments du module dans la corbeille globale */
    private function globalTrash(): array
    {
        $result = $this->view('list', ['source' => 'module', 'module' => 'budget'], 'trash');
        $this->assertSame(200, $result['_status'], json_encode($result));
        return [$result['data']['content']];
    }

    public function testTransactionTrashRestoreAndPurge(): void
    {
        $account = $this->createAccount();
        $courses = $this->createTransaction($account, '45,20', 'Courses');
        $this->createTransaction($account, '2 500', 'Salaire', 'income');
        $this->assertStringContains('3 454,80 €', $this->view('accounts')['data']['content'], 'solde initial 1 000 − 45,20 + 2 500');

        // Mise en corbeille : message, liste, solde, fiche.
        $deleted = $this->post('transaction-delete', ['id' => $courses]);
        $this->assertSame(200, $deleted['_status'], json_encode($deleted));
        $this->assertStringContains('placée dans la corbeille', (string) $deleted['message']);
        $this->assertSame(404, $this->view('transaction/' . $courses . '/edit')['_status']);
        $this->assertFalse(str_contains($this->view('transactions')['data']['content'], '>Courses</a>'));
        $this->assertStringContains('3 500,00 €', $this->view('accounts')['data']['content'], 'l’opération en corbeille ne compte plus');
        $this->assertStringContains('2 500,00 €', $this->view('dashboard')['data']['content']);
        $this->assertNotNull($this->app->db->scalar('SELECT deleted_at FROM budget_transaction WHERE id = :id', ['id' => $courses]));

        // Vue corbeille du module et corbeille globale : libellé explicite.
        $trash = $this->view('trash')['data']['content'];
        $this->assertStringContains('Opération du 12/09/2026 — Courses — −45,20 €', $trash);
        $this->assertStringContains('Voir toute la corbeille', $this->view('trash')['data']['banner']);
        [$global] = $this->globalTrash();
        $this->assertStringContains('Opération du 12/09/2026 — Courses — −45,20 €', $global);
        $this->assertStringContains('Opérations', $global, 'nom du jeu de données');

        // Restauration via la corbeille globale.
        $restored = $this->post('restore', ['source' => 'module', 'module' => 'budget', 'id' => 'transaction:' . $courses], 'trash');
        $this->assertSame(200, $restored['_status'], json_encode($restored));
        $this->assertStringContains('3 454,80 €', $this->view('accounts')['data']['content']);
        $this->assertSame(200, $this->view('transaction/' . $courses . '/edit')['_status']);

        // Suppression groupée puis purge définitive depuis la vue du module.
        $this->assertSame(200, $this->post('transactions-bulk', ['ids' => [$courses], 'op' => 'delete'])['_status']);
        $this->assertSame(0, (int) $this->post('transactions-bulk', ['ids' => [$courses], 'op' => 'clear'])['data']['count'], 'une opération en corbeille ne se pointe plus');
        $purged = $this->post('trash-purge', ['id' => 'transaction:' . $courses]);
        $this->assertSame(200, $purged['_status'], json_encode($purged));
        $this->assertNull($this->app->db->selectOne('SELECT id FROM budget_transaction WHERE id = :id', ['id' => $courses]));
        $this->assertSame(404, $this->post('trash-purge', ['id' => 'transaction:' . $courses])['_status']);
    }

    public function testAccountTrashMasksTransactionsAndPurgeRemovesThem(): void
    {
        $account = $this->createAccount('Courant', '1 000');
        $livret = $this->createAccount('Livret A', '500');
        $t1 = $this->createTransaction($account, '45,20', 'Courses');
        $this->createTransaction($account, '2 500', 'Salaire', 'income');
        $recurring = (int) $this->post('recurring-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '850', 'label' => 'Loyer', 'interval_unit' => 'month', 'interval_count' => '1', 'next_at' => '2026-09-05'])['data']['id'];
        $goal = (int) $this->post('goal-save', ['name' => 'Vacances', 'target' => '3000', 'account_id' => $account])['data']['id'];
        $this->assertSame(1, $this->badge());

        // Compte en corbeille : opérations masquées des soldes, listes et totaux ; récurrence masquée ; objectif en mode manuel.
        $deleted = $this->post('account-delete', ['id' => $account]);
        $this->assertSame(200, $deleted['_status'], json_encode($deleted));
        $this->assertStringContains('placé dans la corbeille avec ses 2 opérations', (string) $deleted['message']);
        $accounts = $this->view('accounts')['data'];
        $this->assertFalse(str_contains($accounts['content'], 'Courant</strong>'));
        $this->assertStringContains('1 compte · solde total 500,00 €', $accounts['banner']);
        $this->assertStringContains('Aucune opération', $this->view('transactions')['data']['content']);
        $this->assertSame(404, $this->view('transaction/' . $t1 . '/edit')['_status']);
        $this->assertSame(0, $this->badge());
        $this->assertFalse(str_contains($this->view('forecast')['data']['content'], 'Loyer'));
        $this->assertStringContains('montant saisi à la main', $this->view('savings')['data']['content']);
        $csv = $this->app->handle(Request::create('GET', '/m/budget/export.csv', [], [], $this->headers()));
        $this->assertFalse(str_contains($csv->body(), 'Courses'), 'export sans les opérations masquées');
        $this->assertNull($this->app->db->scalar('SELECT deleted_at FROM budget_transaction WHERE id = :id', ['id' => $t1]), 'les opérations ne sont pas supprimées elles-mêmes');
        $trash = $this->view('trash')['data']['content'];
        $this->assertStringContains('Compte « Courant »', $trash);
        $this->assertStringContains('2 opération(s) masquée(s)', $trash);

        // Service intermodule : seuls les comptes vivants.
        $service = $this->app->context(Request::create('GET', '/'))->moduleService('budget');
        $this->assertCount(1, $service->accounts($this->userId));

        // Restauration depuis la vue du module : tout revient.
        $this->assertSame(200, $this->post('trash-restore', ['id' => 'account:' . $account])['_status']);
        $this->assertStringContains('3 454,80 €', $this->view('accounts')['data']['content']);
        $this->assertSame(1, $this->badge());
        $this->assertStringContains('solde de « Courant »', $this->view('savings')['data']['content']);

        // Purge définitive via la corbeille globale : opérations et récurrences effacées, objectif détaché.
        $this->post('account-delete', ['id' => $account]);
        $purged = $this->post('purge', ['key' => 'module:budget:account:' . $account], 'trash');
        $this->assertSame(200, $purged['_status'], json_encode($purged));
        $this->assertSame(0, $this->app->db->count('SELECT COUNT(*) FROM budget_transaction WHERE account_id = :a', ['a' => $account]));
        $this->assertSame(0, $this->app->db->count('SELECT COUNT(*) FROM budget_recurring WHERE id = :id', ['id' => $recurring]));
        $this->assertNull($this->app->db->scalar('SELECT account_id FROM budget_goal WHERE id = :id', ['id' => $goal]));
        $this->assertNull($this->app->db->selectOne('SELECT id FROM budget_account WHERE id = :id', ['id' => $account]));
        $this->assertStringContains('Livret A', $this->view('accounts')['data']['content']);
    }

    public function testOtherKindsCategoriesAndRetention(): void
    {
        $account = $this->createAccount();
        $food = (int) $this->post('category-save', ['name' => 'Alimentation', 'kind' => 'expense'])['data']['id'];
        $transaction = $this->createTransaction($account, '12', 'Pain', 'expense', $food);
        $recurring = (int) $this->post('recurring-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '9,99', 'label' => 'Streaming', 'interval_unit' => 'month', 'interval_count' => '1', 'next_at' => '2026-10-01'])['data']['id'];
        $goal = (int) $this->post('goal-save', ['name' => 'Vélo', 'target' => '800'])['data']['id'];
        $saving = (int) $this->post('saving-save', ['label' => 'Électricité', 'kind' => 'monthly', 'amount' => '18', 'effective_from' => '2026-05-01'])['data']['id'];

        // Suppressions logiques : messages et disparition des écrans.
        $this->assertStringContains('placée dans la corbeille', (string) $this->post('recurring-delete', ['id' => $recurring])['message']);
        $this->assertStringContains('placé dans la corbeille', (string) $this->post('goal-delete', ['id' => $goal])['message']);
        $this->assertStringContains('placée dans la corbeille', (string) $this->post('saving-delete', ['id' => $saving])['message']);
        $this->assertFalse(str_contains($this->view('forecast')['data']['content'], 'Streaming'));
        $savings = $this->view('savings')['data'];
        $this->assertFalse(str_contains($savings['content'], 'Vélo'));
        $this->assertFalse(str_contains($savings['content'], 'Électricité'));
        $this->assertStringContains('0,00 € de gains enregistrés', $savings['banner'], 'gains enregistrés sans l’économie en corbeille');
        $this->assertSame(404, $this->view('recurring/' . $recurring . '/edit')['_status']);
        $this->assertSame(404, $this->view('goal/' . $goal . '/edit')['_status']);
        $trash = $this->view('trash')['data']['content'];
        foreach (['Récurrence « Streaming »', 'Objectif d’épargne « Vélo »', 'Économie « Électricité »'] as $label) {
            $this->assertStringContains($label, $trash);
        }
        [$global] = $this->globalTrash();
        $this->assertStringContains('Épargne et économies', $global, 'jeu de données des objectifs et économies');
        $this->assertSame(200, $this->post('restore', ['key' => 'module:budget:goal:' . $goal], 'trash')['_status']);
        $this->assertStringContains('Vélo', $this->view('savings')['data']['content']);

        // Catégorie : suppression physique refusée tant que des opérations (même en corbeille) s'y rattachent.
        $this->post('transaction-delete', ['id' => $transaction]);
        $refused = $this->post('category-delete', ['id' => $food]);
        $this->assertSame(409, $refused['_status'], json_encode($refused));
        $this->assertStringContains('1 opération(s)', (string) ($refused['error']['message'] ?? $refused['message'] ?? ''));
        $this->assertSame(200, $this->post('trash-purge', ['id' => 'transaction:' . $transaction])['_status']);
        $this->assertSame(200, $this->post('category-delete', ['id' => $food])['_status']);

        // Droits : un simple lecteur ne voit pas les boutons et ne peut ni restaurer ni purger.
        $bob = $this->app->users->create(['username' => 'bob', 'password_hash' => $this->app->passwords->hash('Mot-de-passe-solide'), 'must_change_password' => 0]);
        $this->app->acl->setRule('user', $bob, AclService::module('budget'), 'open', 'allow');
        $this->app->acl->setRule('user', $bob, AclService::module('trash'), 'open', 'allow');
        $this->app->acl->clearCache();
        $this->app->auth->logout();
        $this->app->auth->login('bob', 'Mot-de-passe-solide', '127.0.0.1');
        $this->assertSame(403, $this->view('trash')['_status']);
        $this->assertSame(403, $this->post('restore', ['key' => 'module:budget:recurring:' . $recurring], 'trash')['_status']);
        $this->assertSame(403, $this->post('purge', ['key' => 'module:budget:recurring:' . $recurring], 'trash')['_status']);
        $this->app->auth->logout();
        $this->app->auth->login('alice', 'Mot-de-passe-solide', '127.0.0.1');

        // Rétention : le hook purge() efface les éléments expirés et laisse les récents.
        $this->app->db->execute("UPDATE budget_recurring SET deleted_at = '2026-08-01 00:00:00' WHERE id = :id", ['id' => $recurring]);
        $context = $this->app->context(Request::create('GET', '/'));
        $this->app->modules->reset();
        $this->app->modules->discover();
        [$module] = $this->app->modules->boot('budget', $context);
        $summary = $module->purge();
        $this->assertStringContains('1 récurrence(s)', $summary);
        $this->assertStringContains('0 économie(s)', $summary);
        $this->assertNull($this->app->db->selectOne('SELECT id FROM budget_recurring WHERE id = :id', ['id' => $recurring]));
        $this->assertNotNull($this->app->db->selectOne('SELECT id FROM budget_saving WHERE id = :id', ['id' => $saving]), 'économie récente conservée');
    }
}
