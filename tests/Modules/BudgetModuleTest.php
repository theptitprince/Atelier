<?php

declare(strict_types=1);

namespace Atelier\Tests\Modules;

use Atelier\Error\ForbiddenException;
use Atelier\Http\Request;
use Atelier\Kernel\Application;
use Atelier\Security\Acl\AclService;
use Atelier\Support\Clock;
use Atelier\Testing\TestCase;

/**
 * Module Budget : comptes, opérations, import CSV, budgets par catégorie, récurrences et projection,
 * épargne et économies, exports, service intermodule et report des coûts du module Entretien.
 */
final class BudgetModuleTest extends TestCase
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
    }

    public function tearDown(): void
    {
        Clock::freeze(null);
    }

    private function headers(): array
    {
        return ['X-Atelier-Request' => 'json', 'X-CSRF-Token' => $this->app->csrf->token()];
    }

    private function allowAll(string $module = 'budget'): void
    {
        $this->app->acl->setRule('user', $this->userId, AclService::module($module), 'admin', 'allow');
        $this->app->acl->clearCache();
    }

    private function post(string $route, array $data, string $module = 'budget'): array
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

    private function createCategory(string $name, string $kind = 'expense', ?int $parent = null): int
    {
        $result = $this->post('category-save', ['name' => $name, 'kind' => $kind, 'parent_id' => $parent]);
        $this->assertSame(200, $result['_status'], json_encode($result));
        return (int) $result['data']['id'];
    }

    public function testModuleIsDiscoveredAndLockedWithoutRights(): void
    {
        $descriptor = $this->app->modules->get('budget');
        $this->assertNotNull($descriptor);
        $this->assertTrue($descriptor->isUsable(), implode(' ', $descriptor->errors));
        $this->assertSame(403, $this->app->handle(Request::create('GET', '/m/budget/dashboard', [], [], $this->headers()))->status());
        $this->assertNotNull($this->app->shared->catalog->findShared('budget.transaction'));
        $this->assertNull($this->app->shared->catalog->findShared('budget.recurring'), 'les récurrences sont privées');
    }

    public function testAccountsTransactionsAndValidation(): void
    {
        $this->allowAll();
        $this->assertStringContains('Aucun compte', $this->view('dashboard')['data']['content']);
        $account = $this->createAccount();
        $food = $this->createCategory('Alimentation');
        $courses = $this->createCategory('Courses', 'expense', $food);
        $salary = $this->createCategory('Salaires', 'income');

        // Validation : sous-catégorie d'une nature différente, montant nul.
        $this->assertSame(422, $this->post('category-save', ['name' => 'Prime', 'kind' => 'income', 'parent_id' => $food])['_status']);
        $bad = $this->post('transaction-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '0', 'done_at' => '22/09/2026', 'label' => '']);
        $this->assertSame(422, $bad['_status']);
        $this->assertTrue(isset($bad['error']['fields']['amount']) && isset($bad['error']['fields']['label']));

        // Saisie : dépense (signe négatif), recette, dates JJ/MM/AAAA acceptées.
        $t1 = $this->post('transaction-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '45,90', 'done_at' => '20/09/2026', 'label' => 'Courses', 'payee' => 'Supermarché', 'category_id' => $courses, 'cleared' => '1']);
        $this->assertSame(200, $t1['_status'], json_encode($t1));
        $t2 = $this->post('transaction-save', ['account_id' => $account, 'type' => 'income', 'amount' => '2 500', 'done_at' => '2026-09-01', 'label' => 'Salaire', 'category_id' => $salary, 'again' => '1']);
        $this->assertSame(200, $t2['_status']);
        $this->assertStringContains('transaction/new', $t2['directives']['navigate'], '« enregistrer et saisir une autre »');
        $t3 = $this->post('transaction-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '12', 'done_at' => '2026-09-21', 'label' => 'Sans catégorie']);

        // Soldes : initial 1 000 − 45,90 + 2 500 − 12 ; solde pointé initial − 45,90.
        $accounts = $this->view('accounts')['data']['content'];
        $this->assertStringContains('3 442,10 €', $accounts);
        $this->assertStringContains('954,10 €', $accounts);

        // Liste et filtres.
        $list = $this->view('transactions', ['month' => '2026-09', 'category' => $food])['data']['content'];
        $this->assertStringContains('Courses', $list);
        $this->assertFalse(str_contains($list, '>Salaire</a>'), 'filtre par catégorie parente');
        $this->assertStringContains('Sans catégorie', $this->view('transactions', ['category' => -1])['data']['content']);
        $this->assertStringContains('module-budget', $list);

        // Pointage, action groupée, suppression.
        $id3 = (int) $t3['data']['id'];
        $this->assertSame(200, $this->post('transaction-clear', ['id' => $id3])['_status']);
        $this->assertSame(200, $this->post('transactions-bulk', ['ids' => [$id3], 'op' => 'categorize', 'category_id' => $courses])['_status']);
        $this->assertSame($courses, (int) $this->app->db->scalar('SELECT category_id FROM budget_transaction WHERE id = :id', ['id' => $id3]));
        $this->assertSame(200, $this->view('transaction/' . $id3 . '/edit')['_status']);
        $this->assertSame(200, $this->post('transaction-delete', ['id' => $id3])['_status']);
        $this->assertSame(404, $this->view('transaction/' . $id3 . '/edit')['_status']);

        // Un compte s'archive (conservé dans l'historique) ; sa suppression est logique (voir BudgetTrashTest).
        $this->assertSame(200, $this->post('account-archive', ['id' => $account])['_status']);
        $this->assertStringContains('archivé', $this->view('accounts')['data']['content']);
        $this->post('account-archive', ['id' => $account]);

        // Export CSV (droit admin du module).
        $csv = $this->app->handle(Request::create('GET', '/m/budget/export.csv', ['month' => '2026-09'], [], $this->headers()));
        $this->assertSame(200, $csv->status(), $csv->body());
        $this->assertStringContains('Supermarché', $csv->body());
        $this->assertStringContains('-45,90', $csv->body());
    }

    public function testEnvelopesReportAndSavings(): void
    {
        $this->allowAll();
        $account = $this->createAccount();
        $this->assertSame(200, $this->post('categories-defaults', [])['_status']);
        $categories = new \Atelier\Modules\Budget\CategoryRepository($this->app->db);
        $food = $categories->findByName('Alimentation', 'expense')['id'];
        $courses = $this->app->db->selectOne('SELECT id FROM budget_category WHERE parent_id = :p AND name = :n', ['p' => $food, 'n' => 'Courses']);
        $coursesId = (int) $courses['id'];
        $leisure = $categories->findByName('Loisirs', 'expense')['id'];

        // Budgets : 600 €/mois sur Alimentation à partir de janvier, 2 400 €/an sur Loisirs (200 €/mois).
        $this->assertSame(200, $this->post('envelope-save', ['category_id' => $food, 'period' => 'month', 'amount' => '600', 'month' => '2026-01'])['_status']);
        $this->assertSame(200, $this->post('envelope-save', ['category_id' => $leisure, 'period' => 'year', 'amount' => '2400', 'month' => '2026-01'])['_status']);
        $this->assertSame(422, $this->post('envelope-save', ['category_id' => $food, 'period' => 'month', 'amount' => '-5', 'month' => '2026-01'])['_status']);

        foreach ([['2026-09-03', '150'], ['2026-09-10', '250'], ['2026-08-12', '700']] as [$day, $amount]) {
            $this->post('transaction-save', ['account_id' => $account, 'type' => 'expense', 'amount' => $amount, 'done_at' => $day, 'label' => 'Courses', 'category_id' => $coursesId]);
        }
        $this->post('transaction-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '350', 'done_at' => '2026-09-15', 'label' => 'Concert', 'category_id' => $leisure]);

        // Budget du mois : Alimentation 400/600, Loisirs 350/200 (dépassé).
        $envelopes = $this->view('envelopes', ['month' => '2026-09'])['data'];
        $this->assertStringContains('septembre 2026', $envelopes['content']);
        $this->assertStringContains('400,00 €', $envelopes['content']);
        $this->assertStringContains('600,00 €', $envelopes['content']);
        $this->assertStringContains('is-over', $envelopes['content'], 'Loisirs dépasse son douzième');
        $this->assertStringContains('dépenses 750,00 € sur 800,00 € budgétés', $envelopes['banner']);
        $august = $this->view('envelopes', ['month' => '2026-08'])['data']['content'];
        $this->assertStringContains('700,00 €', $august);

        // Économies 2026 : seuls les mois révolus comptent (janvier → août, le 22 septembre est incomplet).
        // Alimentation 8×600 − 700 ; Loisirs 8×200 − 0 (le concert est de septembre) ; + registre manuel.
        $this->assertSame(200, $this->post('saving-save', ['label' => 'Fournisseur d’électricité', 'kind' => 'monthly', 'amount' => '18', 'effective_from' => '2026-05-01'])['_status']);
        $this->assertSame(200, $this->post('saving-save', ['label' => 'Prime remboursée', 'kind' => 'one_off', 'amount' => '120', 'effective_from' => '2026-02-10'])['_status']);
        $savings = $this->view('savings')['data'];
        $this->assertStringContains('(8 mois)', $savings['content'], 'le mois en cours, forcément incomplet, est exclu');
        $this->assertStringContains('janvier → août', $savings['content']);
        $this->assertStringContains('4 100,00 €', $savings['content'], 'Alimentation : 4 800 − 700');
        $this->assertStringContains('1 600,00 €', $savings['content'], 'Loisirs : 1 600 − 0');
        $this->assertStringContains('+72,00 €', $savings['content'], 'électricité : 4 mois × 18');
        $this->assertStringContains('+192,00 €', $savings['banner'], 'gains enregistrés : 72 + 120');
        $this->assertStringContains('+5 892,00 €', $savings['banner'], 'total : 5 700 + 192');

        // Objectif lié à un compte d'épargne : progression = solde du compte.
        $livret = $this->post('account-save', ['name' => 'Livret', 'kind' => 'savings', 'initial_balance' => '1500'])['data']['id'];
        $goal = $this->post('goal-save', ['name' => 'Vacances', 'target' => '3000', 'account_id' => $livret, 'due_at' => '2027-06-01']);
        $this->assertSame(200, $goal['_status'], json_encode($goal));
        $content = $this->view('savings')['data']['content'];
        $this->assertStringContains('1 500,00 €', $content, 'progression = solde du livret');
        $this->assertStringContains('50 %', $content);
        $this->assertSame(200, $this->post('goal-delete', ['id' => (int) $goal['data']['id']])['_status']);
    }

    public function testRecurringPostingForecastAndBadge(): void
    {
        $this->allowAll();
        $account = $this->createAccount('Courant', '500');
        $rent = $this->post('recurring-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '850', 'label' => 'Loyer', 'interval_unit' => 'month', 'interval_count' => '1', 'next_at' => '2026-09-05']);
        $this->assertSame(200, $rent['_status'], json_encode($rent));
        $salary = $this->post('recurring-save', ['account_id' => $account, 'type' => 'income', 'amount' => '2500', 'label' => 'Salaire', 'interval_unit' => 'month', 'interval_count' => '1', 'next_at' => '2026-09-28']);
        $this->assertSame(422, $this->post('recurring-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '10', 'label' => 'x', 'interval_unit' => 'month', 'interval_count' => '1', 'next_at' => '2026-10-01', 'ends_at' => '2026-09-01'])['_status']);

        // Badge : le loyer du 5 septembre est à poster, le salaire du 28 non.
        $badge = $this->app->handle(Request::create('GET', '/core/badges', [], [], $this->headers()))->decodedJson()['data']['badges']['budget'];
        $this->assertSame(1, $badge['count']);
        $dashboard = $this->view('dashboard')['data']['content'];
        $this->assertStringContains('Loyer', $dashboard);

        // Poster : opération créée à l'échéance, échéance avancée d'un mois.
        $posted = $this->post('recurring-post', ['id' => (int) $rent['data']['id']]);
        $this->assertSame(200, $posted['_status'], json_encode($posted));
        $list = $this->view('transactions', ['source' => 'recurring'])['data']['content'];
        $this->assertStringContains('05/09/2026', $list);
        $this->assertStringContains('Récurrence', $list);
        $this->assertSame(0, $this->app->handle(Request::create('GET', '/core/badges', [], [], $this->headers()))->decodedJson()['data']['badges']['budget']['count']);
        $this->assertStringContains('05/10/2026', $this->view('forecast')['data']['content']);

        // Projection sur 6 mois : solde de départ 500 − 850 = −350 ; +2 500 le 28/09, puis −850 +2 500 chaque mois.
        $forecast = $this->view('forecast', ['months' => 6])['data'];
        $this->assertStringContains('Solde projeté dans 6 mois', $forecast['banner']);
        $this->assertStringContains('2 150,00 €', $forecast['content'], 'septembre : −350 + 2 500');
        $this->assertStringContains('10 400,00 €', $forecast['banner'], 'fin février : 2 150 + 5 × 1 650');

        // Tout poster jusqu'à aujourd'hui (le 28/09 est encore à venir : rien).
        $this->assertSame('info', $this->post('recurring-post-due', [])['level']);
        $this->assertSame(200, $this->post('recurring-delete', ['id' => (int) $salary['data']['id']])['_status']);
    }

    public function testImportCsvWithDuplicatesAndGuessedCategories(): void
    {
        $this->allowAll();
        $account = $this->createAccount();
        $fuel = $this->createCategory('Carburant');
        $this->post('transaction-save', ['account_id' => $account, 'type' => 'expense', 'amount' => '60', 'done_at' => '2026-08-01', 'label' => 'STATION TOTAL', 'category_id' => $fuel]);

        $csv = "Date;Libellé;Débit;Crédit\r\n05/09/2026;STATION TOTAL;65,20;\r\n06/09/2026;VIREMENT EMPLOYEUR;;2 500,00\r\n07/09/2026;CARTE BOULANGERIE;4,50;\r\nmauvaise;LIGNE;1;\r\n";
        $upload = function () use ($csv, $account): array {
            $file = tempnam(sys_get_temp_dir(), 'csv');
            file_put_contents($file, $csv);
            $files = ['file' => ['name' => 'releve.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => strlen($csv)]];
            $request = new Request('POST', '/m/budget/import', [], ['account_id' => $account, 'cleared' => '1'], $files, [], array_change_key_case($this->headers(), CASE_LOWER), ['REMOTE_ADDR' => '127.0.0.1']);
            $response = $this->app->handle($request);
            $json = $response->decodedJson();
            $json['_status'] = $response->status();
            return $json;
        };
        $first = $upload();
        $this->assertSame(200, $first['_status'], json_encode($first));
        $this->assertSame(3, $first['data']['created']);
        $this->assertCount(1, $first['data']['errors']);
        $second = $upload();
        $this->assertSame(0, $second['data']['created']);
        $this->assertSame(3, $second['data']['skipped'], 'doublons ignorés au second import');

        $list = $this->view('transactions', ['source' => 'import'])['data']['content'];
        $this->assertStringContains('VIREMENT EMPLOYEUR', $list);
        $this->assertStringContains('+2 500,00 €', $list);
        $this->assertStringContains('−65,20 €', $list);
        $imported = $this->app->db->selectOne('SELECT category_id FROM budget_transaction WHERE label = :l AND source = :s', ['l' => 'STATION TOTAL', 's' => 'import']);
        $this->assertSame($fuel, (int) $imported['category_id'], 'catégorie devinée depuis le libellé connu');
    }

    public function testMaintenanceCostsAreReportedAndForecast(): void
    {
        $this->allowAll('budget');
        $this->allowAll('maintenance');
        $account = $this->createAccount();
        $car = (int) $this->post('asset-save', ['name' => 'Voiture', 'category' => 'vehicle', 'meter_unit' => 'km', 'meter_value' => '61200'], 'maintenance')['data']['id'];
        $job = (int) $this->post('job-save', ['asset_id' => $car, 'title' => 'Vidange', 'kind' => 'preventive', 'interval_days' => '365', 'next_due_at' => '2026-11-15', 'estimated_cost' => '120'], 'maintenance')['data']['id'];

        // Coût estimé dans le prévisionnel.
        $forecast = $this->view('forecast')['data']['content'];
        $this->assertStringContains('Entretien : Vidange (Voiture)', $forecast);
        $this->assertStringContains('−120,00 €', $forecast);

        // Coût réel reporté comme dépense d'origine « Entretien ».
        $done = $this->post('log-save', ['asset_id' => $car, 'job_id' => $job, 'done_at' => '2026-09-20', 'meter_value' => '62000', 'cost' => '135,50', 'performed_by' => 'Garage Martin'], 'maintenance');
        $this->assertSame(200, $done['_status'], json_encode($done));
        $this->assertStringContains('reportée dans le budget', $done['message']);
        $transactionId = (int) $done['data']['budget_transaction_id'];
        $this->assertTrue($transactionId > 0);
        $transaction = $this->view('transaction/' . $transactionId . '/edit')['data']['content'];
        $this->assertStringContains('Entretien', $transaction);
        $this->assertStringContains('maintenance_log:', $transaction);
        $this->assertStringContains('Garage Martin', $transaction);
        $this->assertStringContains('135,50', $transaction);
        $this->assertStringContains('Entretien et réparations', $transaction, 'catégorie de repli créée');

        // Modification du coût : même opération mise à jour ; suppression de l'intervention : opération retirée.
        $logId = (int) $done['data']['id'];
        $this->post('log-save', ['id' => $logId, 'asset_id' => $car, 'job_id' => $job, 'done_at' => '2026-09-20', 'meter_value' => '62000', 'cost' => '140', 'title' => 'Vidange'], 'maintenance');
        $this->assertSame(1, $this->app->db->count('SELECT COUNT(*) FROM budget_transaction'));
        $this->assertSame(-14000, (int) $this->app->db->scalar('SELECT amount FROM budget_transaction WHERE id = :id', ['id' => $transactionId]));
        $this->assertSame(200, $this->post('log-delete', ['id' => $logId], 'maintenance')['_status']);
        $this->assertSame(0, $this->app->db->count('SELECT COUNT(*) FROM budget_transaction WHERE deleted_at IS NULL'), 'opération placée dans la corbeille');
        $this->assertSame(1, $this->app->db->count('SELECT COUNT(*) FROM budget_transaction WHERE deleted_at IS NOT NULL'));

        // Report désactivé dans les réglages : plus d'opération créée.
        $this->assertSame(200, $this->post('settings-save', ['account_id' => $account, 'category_id' => '', 'auto' => '0'])['_status']);
        $again = $this->post('log-save', ['asset_id' => $car, 'done_at' => '2026-09-21', 'title' => 'Pneus', 'cost' => '400'], 'maintenance');
        $this->assertSame(200, $again['_status']);
        $this->assertNull($again['data']['budget_transaction_id']);
    }

    public function testServiceChecksDatasetRights(): void
    {
        $this->allowAll();
        $this->createAccount('Courant', '250');
        $context = $this->app->context(Request::create('GET', '/'));
        $this->app->modules->reset();
        $this->app->modules->discover();
        $service = $context->moduleService('budget');
        $accounts = $service->accounts($this->userId);
        $this->assertCount(1, $accounts);
        $this->assertSame(25000, $accounts[0]['balance']);

        $id = $service->recordExternal($this->userId, 'test:1', 'maintenance', ['label' => 'Test', 'amount' => -1000, 'done_at' => '2026-09-01']);
        $this->assertNotNull($id);
        $this->assertSame($id, $service->recordExternal($this->userId, 'test:1', 'maintenance', ['label' => 'Test 2', 'amount' => -2000, 'done_at' => '2026-09-02']), 'mise à jour idempotente');
        $this->assertSame(-2000, $service->externalTransaction($this->userId, 'test:1')['amount']);
        $this->assertTrue($service->removeExternal($this->userId, 'test:1'));
        $this->assertNull($service->externalTransaction($this->userId, 'test:1'));

        $this->app->acl->setRule('user', $this->userId, AclService::module('budget') . '/data/transaction', 'create', 'deny');
        $this->app->acl->clearCache();
        $this->assertThrows(ForbiddenException::class, fn () => $service->recordExternal($this->userId, 'test:2', 'maintenance', ['label' => 'x', 'amount' => -1, 'done_at' => '2026-09-01']));
    }

    /**
     * Non-régression : le prévisionnel projetait les récurrences des comptes archivés alors que son
     * solde de départ ne compte que les comptes actifs — départ et projection sur deux périmètres.
     */
    public function testForecastIgnoresRecurringsOfArchivedAccounts(): void
    {
        $this->allowAll();
        $this->createAccount('Courant', '1 000');
        $old = $this->createAccount('Ancien compte', '400');
        $closed = $this->post('recurring-save', ['account_id' => $old, 'type' => 'expense', 'amount' => '100', 'label' => 'Abonnement clos', 'interval_unit' => 'month', 'interval_count' => '1', 'next_at' => '2026-10-10']);
        $this->assertSame(200, $closed['_status'], json_encode($closed));
        $this->assertSame(200, $this->post('account-archive', ['id' => $old])['_status']);

        // Horizon de 6 mois (septembre à février) : 5 échéances, ignorées tant que le compte est archivé.
        $forecast = $this->view('forecast', ['months' => 6])['data'];
        $this->assertStringContains('Solde projeté dans 6 mois : 1 000,00 €', $forecast['banner'], 'la récurrence d’un compte archivé n’est pas projetée');
        $this->assertStringContains('1 000,00 €)', $forecast['banner'], 'solde de départ : comptes actifs seulement');
        $this->assertStringContains('compte archivé', $forecast['content'], 'la récurrence reste listée, signalée hors projection');

        // Compte réactivé : les deux périmètres se rejoignent, 1 400 − 5 × 100.
        $this->assertSame(200, $this->post('account-archive', ['id' => $old])['_status']);
        $back = $this->view('forecast', ['months' => 6])['data'];
        $this->assertStringContains('Solde projeté dans 6 mois : 900,00 € (aujourd’hui 1 400,00 €)', $back['banner']);
        $this->assertFalse(str_contains($back['content'], 'compte archivé'));
    }

    /**
     * Non-régression : une récurrence échue d'un compte archivé restait proposée « à poster »
     * (bulle du module, « Tout poster »). La poster créait une opération sur un compte dont le
     * solde n'est compté ni dans le total ni dans le prévisionnel.
     */
    public function testDueRecurringsOfArchivedAccountsAreNotProposed(): void
    {
        $this->allowAll();
        $this->createAccount('Courant', '1 000');
        $old = $this->createAccount('Ancien compte', '400');
        $this->post('recurring-save', ['account_id' => $old, 'type' => 'expense', 'amount' => '100', 'label' => 'Abonnement clos', 'interval_unit' => 'month', 'interval_count' => '1', 'next_at' => '2026-09-01']);
        $badge = fn (): int => (int) $this->app->handle(Request::create('GET', '/core/badges', [], [], $this->headers()))->decodedJson()['data']['badges']['budget']['count'];
        $this->assertSame(1, $badge(), 'échéance dépassée sur un compte actif');

        $this->assertSame(200, $this->post('account-archive', ['id' => $old])['_status']);
        $this->assertSame(0, $badge(), 'compte archivé : plus rien à poster');
        $this->assertSame('info', $this->post('recurring-post-due', [])['level'], 'aucune opération créée');

        $this->assertSame(200, $this->post('account-archive', ['id' => $old])['_status']);
        $this->assertSame(1, $badge(), 'compte réactivé : l’échéance revient');
    }

    /**
     * Non-régression : deux lignes rigoureusement identiques d'un même relevé étaient fusionnées en une
     * seule opération (ligne perdue), l'empreinte d'import ignorant le rang de la ligne dans le fichier.
     */
    public function testImportKeepsIdenticalLinesAndStaysIdempotent(): void
    {
        $this->allowAll();
        $account = $this->createAccount();
        $csv = "Date;Libellé;Débit;Crédit\r\n05/09/2026;PEAGE A10;9,40;\r\n05/09/2026;PEAGE A10;9,40;\r\n06/09/2026;BOULANGERIE;4,50;\r\n";
        $upload = function () use ($csv, $account): array {
            $file = tempnam(sys_get_temp_dir(), 'csv');
            file_put_contents($file, $csv);
            $files = ['file' => ['name' => 'releve.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => strlen($csv)]];
            $request = new Request('POST', '/m/budget/import', [], ['account_id' => $account], $files, [], array_change_key_case($this->headers(), CASE_LOWER), ['REMOTE_ADDR' => '127.0.0.1']);
            $response = $this->app->handle($request);
            $json = $response->decodedJson();
            $json['_status'] = $response->status();
            return $json;
        };
        $first = $upload();
        $this->assertSame(200, $first['_status'], json_encode($first));
        $this->assertSame(3, $first['data']['created'], 'les deux péages du même jour sont deux dépenses réelles');
        $this->assertSame(2, $this->app->db->count('SELECT COUNT(*) FROM budget_transaction WHERE label = :l', ['l' => 'PEAGE A10']));

        // Idempotence : le même fichier réimporté ne crée plus rien.
        $second = $upload();
        $this->assertSame(0, $second['data']['created']);
        $this->assertSame(3, $second['data']['skipped'], 'doublons ignorés au second import');
        $this->assertSame(3, $this->app->db->count('SELECT COUNT(*) FROM budget_transaction'));
    }

    public function testSeedIsIdempotent(): void
    {
        $this->allowAll();
        $context = $this->app->context(Request::create('GET', '/'));
        $module = $this->app->modules->instance('budget');
        $module->boot($context);
        $this->assertStringContains('2 comptes', $module->seed());
        $this->assertStringContains('déjà présents', $module->seed());
        $dashboard = $this->view('dashboard')['data']['content'];
        $this->assertStringContains('Compte courant', $dashboard);
        $this->assertStringContains('Vacances d’été', $dashboard);
    }
}
