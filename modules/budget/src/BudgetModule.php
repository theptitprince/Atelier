<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Error\ConflictException;
use Atelier\Error\ForbiddenException;
use Atelier\Error\ModuleUnavailableException;
use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Http\Response;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleContext;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Modules\TrashProviderInterface;
use Atelier\Support\Clock;
use Atelier\Support\Str;
use Atelier\View\BbCode;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Module « Budget » : comptabilité domestique.
 *
 *  - comptes (courant, épargne, espèces) et opérations à montant signé, pointage, justificatifs joints ;
 *  - import CSV de relevés bancaires sans doublons, auto-catégorisation par libellé ;
 *  - catégories hiérarchiques avec budgets (enveloppes) mensuels ou annuels, vue réalisé / budget ;
 *  - prévisionnel : opérations récurrentes à poster, échéances d'entretien (coûts estimés du module
 *    Entretien) et projection du solde ;
 *  - objectifs d'épargne et économies réalisées (budget non dépensé + gains enregistrés) ;
 *  - service intermodule : le module Entretien y reporte le coût réel de chaque intervention ;
 *  - corbeille : opérations, comptes, récurrences, objectifs et économies sont supprimés logiquement
 *    (restaurables pendant trash.retention_days) et exposés à la corbeille globale.
 */
final class BudgetModule extends AbstractModule implements TrashProviderInterface
{
    public const DATASET_TRANSACTION = 'budget.transaction';
    public const DATASET_ACCOUNT = 'budget.account';
    public const DATASET_RECURRING = 'budget.recurring';
    public const DATASET_SAVING = 'budget.saving';

    /** Types d'éléments de la corbeille : préfixe d'identifiant => jeu de données. */
    private const TRASH_KINDS = ['transaction' => self::DATASET_TRANSACTION, 'account' => self::DATASET_ACCOUNT, 'recurring' => self::DATASET_RECURRING, 'goal' => self::DATASET_SAVING, 'saving' => self::DATASET_SAVING];

    private const IMPORT_RESOURCE = 'action/import';
    private const EXPORT_RESOURCE = 'action/export';
    private const PER_PAGE_CHOICES = [25, 50, 100, 200];
    private const LABEL_MAX = 200;
    private const NAME_MAX = 150;
    private const TEXT_MAX = 20000;
    private const IMPORT_MAX_ROWS = 5000;
    private const FORECAST_CHOICES = [6, 12, 24];

    /** Colonnes reconnues à l'import CSV : champ => en-têtes acceptés (sans accent, minuscules). */
    private const IMPORT_HEADERS = [
        'date' => ['date', 'date-operation', 'date-op', 'dateop', 'date-de-l-operation', 'date-valeur', 'operation-date', 'transaction-date'],
        'label' => ['libelle', 'label', 'description', 'intitule', 'motif', 'wording', 'operation', 'libelle-operation', 'details'],
        'amount' => ['montant', 'amount', 'valeur', 'value', 'somme'],
        'debit' => ['debit', 'debit-euros', 'sortie'],
        'credit' => ['credit', 'credit-euros', 'entree'],
        'payee' => ['tiers', 'beneficiaire', 'payee', 'contrepartie', 'emetteur', 'nom'],
        'category' => ['categorie', 'category', 'rubrique'],
    ];

    private ?AccountRepository $accounts = null;
    private ?CategoryRepository $categories = null;
    private ?TransactionRepository $transactions = null;
    private ?RecurringRepository $recurrings = null;
    private ?SavingsRepository $savings = null;
    private ?BudgetService $serviceInstance = null;

    public function boot(ModuleContext $context): void
    {
        parent::boot($context);
        $this->accounts = null;
        $this->categories = null;
        $this->transactions = null;
        $this->recurrings = null;
        $this->savings = null;
        $this->serviceInstance = null;
    }

    public function routes(RouteCollection $r): void
    {
        $r->view('dashboard', [$this, 'dashboard'], permission: 'open');
        $r->view('transactions', [$this, 'transactions'], permission: 'open');
        $r->view('transaction/new', [$this, 'transactionNew'], permission: 'create');
        $r->view('transaction/{id}/edit', [$this, 'transactionEdit'], permission: 'open');
        $r->view('import', [$this, 'importForm'], permission: 'import', resource: self::IMPORT_RESOURCE);
        $r->view('envelopes', [$this, 'envelopes'], permission: 'open');
        $r->view('forecast', [$this, 'forecast'], permission: 'open');
        $r->view('recurring/new', [$this, 'recurringNew'], permission: 'create');
        $r->view('recurring/{id}/edit', [$this, 'recurringEdit'], permission: 'update');
        $r->view('savings', [$this, 'savings'], permission: 'open');
        $r->view('goal/new', [$this, 'goalNew'], permission: 'create');
        $r->view('goal/{id}/edit', [$this, 'goalEdit'], permission: 'update');
        $r->view('saving/new', [$this, 'savingNew'], permission: 'create');
        $r->view('saving/{id}/edit', [$this, 'savingEdit'], permission: 'update');
        $r->view('accounts', [$this, 'accounts'], permission: 'open');
        $r->view('account/new', [$this, 'accountNew'], permission: 'create');
        $r->view('account/{id}/edit', [$this, 'accountEdit'], permission: 'update');
        $r->view('categories', [$this, 'categories'], permission: 'open');
        $r->view('category/new', [$this, 'categoryNew'], permission: 'create');
        $r->view('category/{id}/edit', [$this, 'categoryEdit'], permission: 'update');
        $r->view('settings', [$this, 'settings'], permission: 'admin');
        $r->view('trash', [$this, 'trash'], permission: 'delete');

        $r->action('badge', [$this, 'badge'], permission: 'open', methods: ['GET']);
        $r->action('trash-restore', [$this, 'trashRestore'], permission: 'update');
        $r->action('trash-purge', [$this, 'trashPurge'], permission: 'delete');
        $r->action('filter-transactions', [$this, 'filterTransactions'], permission: 'open');
        $r->action('transaction-save', [$this, 'transactionSave'], permission: 'open');
        $r->action('transaction-delete', [$this, 'transactionDelete'], permission: 'delete');
        $r->action('transaction-clear', [$this, 'transactionClear'], permission: 'update');
        $r->action('transactions-bulk', [$this, 'transactionsBulk'], permission: 'update');
        $r->action('import', [$this, 'importRun'], permission: 'import', resource: self::IMPORT_RESOURCE);
        $r->action('envelope-save', [$this, 'envelopeSave'], permission: 'update');
        $r->action('envelope-delete', [$this, 'envelopeDelete'], permission: 'update');
        $r->action('recurring-save', [$this, 'recurringSave'], permission: 'open');
        $r->action('recurring-delete', [$this, 'recurringDelete'], permission: 'delete');
        $r->action('recurring-post', [$this, 'recurringPost'], permission: 'create');
        $r->action('recurring-post-due', [$this, 'recurringPostDue'], permission: 'create');
        $r->action('goal-save', [$this, 'goalSave'], permission: 'open');
        $r->action('goal-delete', [$this, 'goalDelete'], permission: 'delete');
        $r->action('saving-save', [$this, 'savingSave'], permission: 'open');
        $r->action('saving-delete', [$this, 'savingDelete'], permission: 'delete');
        $r->action('account-save', [$this, 'accountSave'], permission: 'open');
        $r->action('account-archive', [$this, 'accountArchive'], permission: 'update');
        $r->action('account-delete', [$this, 'accountDelete'], permission: 'delete');
        $r->action('category-save', [$this, 'categorySave'], permission: 'open');
        $r->action('category-archive', [$this, 'categoryArchive'], permission: 'update');
        $r->action('category-delete', [$this, 'categoryDelete'], permission: 'delete');
        $r->action('categories-defaults', [$this, 'categoriesDefaults'], permission: 'create');
        $r->action('settings-save', [$this, 'settingsSave'], permission: 'admin');
        $r->action('attach', [$this, 'attach'], permission: 'update');
        $r->action('attachment-delete', [$this, 'attachmentDelete'], permission: 'update');

        $r->raw('export.csv', [$this, 'exportCsv'], permission: 'export', resource: self::EXPORT_RESOURCE);
    }

    public function service(): ?object
    {
        return $this->serviceInstance ??= new BudgetService($this->ctx, $this->accountRepo(), $this->categoryRepo(), $this->transactionRepo());
    }

    /** Données de démonstration : deux comptes, les catégories courantes, quelques budgets, opérations, récurrences, un objectif et une économie. */
    public function seed(): string
    {
        if ($this->accountRepo()->count() > 0) {
            return 'comptes déjà présents';
        }
        $userId = $this->ctx->auth->userId();
        $today = $this->today();
        $month = $today->format('Y-m');
        $day = static fn (int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
        $this->categoryRepo()->createDefaults();
        $cat = fn (string $name, string $kind = 'expense'): ?int => $this->categoryRepo()->findByName($name, $kind)['id'] ?? null;

        $checking = $this->accountRepo()->create(['name' => 'Compte courant', 'kind' => 'checking', 'initial_balance' => 185000, 'opened_at' => null, 'notes' => null, 'sort_order' => 1], $userId);
        $savingsAccount = $this->accountRepo()->create(['name' => 'Livret A', 'kind' => 'savings', 'initial_balance' => 420000, 'opened_at' => null, 'notes' => null, 'sort_order' => 2], $userId);

        foreach ([['Logement', 'month', 95000], ['Alimentation', 'month', 60000], ['Véhicule', 'month', 25000], ['Abonnements', 'month', 9000], ['Loisirs', 'month', 20000], ['Impôts et taxes', 'year', 240000]] as [$name, $period, $amount]) {
            $id = $cat($name);
            if ($id !== null) {
                $this->categoryRepo()->saveEnvelope($id, $period, $amount, Period::addMonths($month, -12) . '-01');
            }
        }

        $transactions = [
            [$day(-40), 250000, 'Salaire', 'Employeur', 'Salaires', 'income'],
            [$day(-38), -85000, 'Loyer', 'Agence immobilière', 'Loyer ou crédit', 'expense'],
            [$day(-35), -12450, 'Courses hebdomadaires', 'Supermarché', 'Courses', 'expense'],
            [$day(-30), -6500, 'Plein d’essence', 'Station', 'Carburant', 'expense'],
            [$day(-28), -13290, 'Courses hebdomadaires', 'Supermarché', 'Courses', 'expense'],
            [$day(-25), -2999, 'Box internet', 'Opérateur', 'Téléphone et internet', 'expense'],
            [$day(-20), -4800, 'Cinéma et restaurant', null, 'Sorties', 'expense'],
            [$day(-10), 250000, 'Salaire', 'Employeur', 'Salaires', 'income'],
            [$day(-8), -85000, 'Loyer', 'Agence immobilière', 'Loyer ou crédit', 'expense'],
            [$day(-6), -11875, 'Courses hebdomadaires', 'Supermarché', 'Courses', 'expense'],
            [$day(-3), -7200, 'Plein d’essence', 'Station', 'Carburant', 'expense'],
            [$day(-1), -1499, 'Streaming', 'Plateforme', 'Streaming', 'expense'],
        ];
        foreach ($transactions as [$date, $amount, $label, $payee, $category, $kind]) {
            $this->transactionRepo()->create(['account_id' => $checking, 'category_id' => $cat($category, $kind), 'done_at' => $date, 'amount' => $amount, 'label' => $label, 'payee' => $payee, 'cleared' => $date < $day(-5), 'source' => 'manual'], $userId);
        }
        $this->transactionRepo()->create(['account_id' => $savingsAccount, 'category_id' => null, 'done_at' => $day(-9), 'amount' => 20000, 'label' => 'Virement épargne', 'payee' => null, 'cleared' => true, 'source' => 'manual'], $userId);

        $nextMonth = Period::addMonths($month, 1);
        foreach ([
            ['Salaire', 'Employeur', 250000, 'month', 1, $nextMonth . '-28', 'Salaires', 'income'],
            ['Loyer', 'Agence immobilière', -85000, 'month', 1, $nextMonth . '-05', 'Loyer ou crédit', 'expense'],
            ['Box internet', 'Opérateur', -2999, 'month', 1, $day(5), 'Téléphone et internet', 'expense'],
            ['Assurance auto', 'Assureur', -42000, 'year', 1, $today->modify('+3 months')->format('Y-m-d'), 'Assurance auto', 'expense'],
            ['Taxe foncière', 'Trésor public', -120000, 'year', 1, $today->modify('+1 month')->format('Y-m-d'), 'Impôts et taxes', 'expense'],
        ] as [$label, $payee, $amount, $unit, $count, $next, $category, $kind]) {
            $this->recurringRepo()->create(['account_id' => $checking, 'category_id' => $cat($category, $kind), 'label' => $label, 'payee' => $payee, 'amount' => $amount, 'interval_unit' => $unit, 'interval_count' => $count, 'next_at' => $next, 'ends_at' => null, 'active' => true, 'notes' => null], $userId);
        }
        $this->savingsRepo()->createGoal(['name' => 'Vacances d’été', 'target' => 300000, 'current' => 0, 'account_id' => $savingsAccount, 'due_at' => $today->modify('+9 months')->format('Y-m-d'), 'notes' => null], $userId);
        $this->savingsRepo()->createGoal(['name' => 'Nouveau vélo', 'target' => 80000, 'current' => 35000, 'account_id' => null, 'due_at' => null, 'notes' => null], $userId);
        $this->savingsRepo()->createSaving(['label' => 'Changement de fournisseur d’électricité', 'kind' => 'monthly', 'amount' => 1800, 'effective_from' => Period::addMonths($month, -4) . '-01', 'effective_to' => null, 'category_id' => $cat('Énergie'), 'notes' => 'Offre à prix fixe.'], $userId);
        $this->savingsRepo()->createSaving(['label' => 'Renégociation de l’assurance habitation', 'kind' => 'yearly', 'amount' => 9600, 'effective_from' => Period::addMonths($month, -2) . '-01', 'effective_to' => null, 'category_id' => $cat('Assurance habitation'), 'notes' => null], $userId);
        return '2 comptes, catégories courantes, 6 budgets, 13 opérations, 5 récurrences, 2 objectifs et 2 économies d’exemple créés';
    }

    // =====================================================================
    // Vues
    // =====================================================================

    public function dashboard(Request $request, array $params): ModuleView
    {
        $today = $this->today();
        $todayDay = $today->format('Y-m-d');
        $month = $today->format('Y-m');
        [$from, $to] = Period::monthRange($month);
        $accounts = $this->accountRepo()->all();
        $totals = $this->transactionRepo()->totals($from, $to);
        $envelopes = $this->envelopeReport($month);
        $due = $this->recurringRepo()->due($todayDay);
        $savingsYear = $this->savingsReport((int) $today->format('Y'));
        $rights = $this->rights(['create', 'update']);

        $content = $this->render('dashboard', [
            'accounts' => $accounts,
            'totalBalance' => array_sum(array_column($accounts, 'balance')),
            'month' => $month,
            'monthLabel' => Period::monthLabel($month),
            'totals' => $totals,
            'envelopes' => $envelopes,
            'due' => $due,
            'goals' => array_slice($this->savingsRepo()->goals(), 0, 4),
            'savings' => $savingsYear,
            'recent' => $this->transactionRepo()->recent(8),
            'uncategorized' => $this->transactionRepo()->paginate(['category' => -1, 'month' => $month], 1, 1)['total'],
            'rights' => $rights,
            'hasAccounts' => $accounts !== [],
        ]);
        $subtitle = $accounts === [] ? 'Commencez par créer un compte' : 'Solde total ' . Money::format(array_sum(array_column($accounts, 'balance'))) . ' · ' . count($due) . ' échéance' . (count($due) > 1 ? 's' : '') . ' à poster';
        $banner = $this->renderCore('banner', ['icon' => 'book', 'title' => 'Budget', 'subtitle' => $subtitle, 'actions' => $this->navLinks('dashboard')]);
        return ModuleView::make('Budget')->banner($banner)->content($content)->status($subtitle);
    }

    public function transactions(Request $request, array $params): ModuleView
    {
        $query = $this->transactionsQuery($request->allQuery());
        $result = $this->transactionRepo()->paginate($this->transactionCriteria($query), $query['page'], $query['per_page']);
        $rights = $this->rights(['create', 'update', 'delete']);
        $canExport = $this->can('export', self::EXPORT_RESOURCE);
        $content = $this->render('transactions', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'sum' => $result['sum'],
            'income' => $result['income'],
            'expense' => $result['expense'],
            'query' => $query,
            'accounts' => $this->accountRepo()->all(true),
            'categories' => $this->categoryRepo()->tree(),
            'sources' => TransactionRepository::SOURCES,
            'rights' => $rights,
            'canExport' => $canExport,
            'exportUrl' => $this->url('export.csv') . $this->queryString($this->transactionsRoute($query)),
            'perPageChoices' => self::PER_PAGE_CHOICES,
            'attachmentCounts' => $this->attachmentCounts(array_map(static fn (array $r): int => $r['id'], $result['rows'])),
        ]);
        $actions = $this->navLinks('transactions');
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="transaction/new">' . $this->icon('plus') . '<span>Nouvelle opération</span></a>';
        }
        $subtitle = $this->countLabel($result['total'], 'opération', $this->isFiltered($query)) . ' · ' . Money::format($result['sum'], '0,00 €', true);
        $banner = $this->renderCore('banner', ['icon' => 'list', 'title' => 'Opérations', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Opérations')->banner($banner)->content($content)->status($subtitle)->route($this->transactionsRoute($query));
    }

    public function transactionNew(Request $request, array $params): ModuleView
    {
        $this->requireAccounts();
        $accountId = (int) $request->query('account', 0);
        $kind = $request->query('kind') === 'income' ? 'income' : 'expense';
        $transaction = ['id' => null, 'account_id' => $accountId > 0 ? $accountId : ($this->accountRepo()->findDefault()['id'] ?? 0), 'category_id' => null, 'done_at' => $this->today()->format('Y-m-d'), 'amount' => null, 'label' => '', 'payee' => '', 'notes' => '', 'cleared' => false, 'source' => 'manual', 'source_ref' => null];
        $content = $this->render('transaction_form', $this->transactionFormVars($transaction, true, $kind));
        $banner = $this->renderCore('banner', ['icon' => 'plus', 'title' => 'Nouvelle opération', 'subtitle' => $kind === 'income' ? 'Recette' : 'Dépense', 'actions' => $this->backLink('transactions', 'Opérations')]);
        return ModuleView::make('Nouvelle opération')->banner($banner)->content($content)->status('Saisie d’une opération');
    }

    public function transactionEdit(Request $request, array $params): ModuleView
    {
        $transaction = $this->requireTransaction((int) ($params['id'] ?? 0));
        $info = $this->ctx->shared->registry->find(self::DATASET_TRANSACTION, (string) $transaction['id']);
        $infoId = $info === null ? null : (string) $info['id'];
        $rights = $this->rights(['update', 'delete']);
        $vars = $this->transactionFormVars($transaction, false, $transaction['amount'] >= 0 ? 'income' : 'expense') + [
            'infoId' => $infoId,
            'attachments' => $infoId === null ? [] : $this->ctx->shared->attachments->listFor($infoId),
            'attachmentsModule' => $this->ctx->modules()->has('attachments'),
            'rights' => $rights,
            'readonly' => !$rights['update'],
        ];
        $content = $this->render('transaction_form', $vars);
        $banner = $this->renderCore('banner', ['icon' => 'edit', 'title' => (string) $transaction['label'], 'subtitle' => Money::format($transaction['amount'], '', true) . ' · ' . Period::dayLabel($transaction['done_at']) . ' · ' . $transaction['account_name'], 'actions' => $this->backLink('transactions', 'Opérations')]);
        return ModuleView::make('Opération · ' . $transaction['label'])->banner($banner)->content($content)->status('Opération n° ' . $transaction['id']);
    }

    public function importForm(Request $request, array $params): ModuleView
    {
        $this->requireAccounts();
        $content = $this->render('import', ['accounts' => $this->accountRepo()->all(), 'categories' => $this->categoryRepo()->tree(), 'headers' => self::IMPORT_HEADERS, 'maxRows' => self::IMPORT_MAX_ROWS]);
        $banner = $this->renderCore('banner', ['icon' => 'upload', 'title' => 'Importer un relevé', 'subtitle' => 'Fichier CSV (UTF-8 ou Windows-1252, séparateur ; , ou tabulation)', 'actions' => $this->backLink('transactions', 'Opérations')]);
        return ModuleView::make('Import de relevé')->banner($banner)->content($content)->status('Import CSV');
    }

    public function envelopes(Request $request, array $params): ModuleView
    {
        $month = Period::normalizeMonth((string) $request->query('month', '')) ?? $this->today()->format('Y-m');
        $report = $this->envelopeReport($month);
        $rights = $this->rights(['update']);
        $content = $this->render('envelopes', [
            'month' => $month,
            'monthLabel' => Period::monthLabel($month),
            'previous' => Period::addMonths($month, -1),
            'next' => Period::addMonths($month, 1),
            'report' => $report,
            'periods' => CategoryRepository::PERIODS,
            'rights' => $rights,
            'hasCategories' => $this->categoryRepo()->count() > 0,
        ]);
        $subtitle = Period::monthLabel($month) . ' · dépenses ' . Money::format($report['expense']['spent']) . ' sur ' . Money::format($report['expense']['budget']) . ' budgétés';
        $banner = $this->renderCore('banner', ['icon' => 'grid', 'title' => 'Budget du mois', 'subtitle' => $subtitle, 'actions' => $this->navLinks('envelopes')]);
        return ModuleView::make('Budget · ' . Period::monthLabel($month))->banner($banner)->content($content)->status($subtitle)->route($month === $this->today()->format('Y-m') ? 'envelopes' : 'envelopes?month=' . $month);
    }

    public function forecast(Request $request, array $params): ModuleView
    {
        $months = (int) $request->query('months', 12);
        $months = in_array($months, self::FORECAST_CHOICES, true) ? $months : 12;
        $today = $this->today();
        $recurrings = $this->recurringRepo()->all();
        // Même horizon que la projection : jusqu'à la fin du dernier mois projeté.
        $horizonEnd = Period::monthRange(Period::addMonths($today->format('Y-m'), $months - 1))[1];
        $externals = array_values(array_filter($this->maintenanceCosts($months), static fn (array $item): bool => $item['day'] <= $horizonEnd));
        $opening = $this->accountRepo()->totalBalance();
        $projection = Forecast::project($opening, $today->format('Y-m'), $months, array_values(array_filter($recurrings, static fn (array $r): bool => $r['active'])), $externals);
        $rights = $this->rights(['create', 'update', 'delete']);
        $content = $this->render('forecast', [
            'months' => $months,
            'choices' => self::FORECAST_CHOICES,
            'opening' => $opening,
            'projection' => $projection,
            'recurrings' => $recurrings,
            'due' => $this->recurringRepo()->due($today->format('Y-m-d')),
            'externals' => $externals,
            'maintenanceAvailable' => $this->ctx->modules()->has('maintenance'),
            'units' => RecurringRepository::UNITS,
            'rights' => $rights,
            'today' => $today->format('Y-m-d'),
        ]);
        $last = $projection[count($projection) - 1] ?? null;
        $subtitle = 'Solde projeté dans ' . $months . ' mois : ' . Money::format($last['closing'] ?? $opening) . ' (aujourd’hui ' . Money::format($opening) . ')';
        $actions = $this->navLinks('forecast');
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="recurring/new">' . $this->icon('plus') . '<span>Nouvelle récurrence</span></a>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'calendar', 'title' => 'Prévisionnel', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Prévisionnel')->banner($banner)->content($content)->status($subtitle)->route($months === 12 ? 'forecast' : 'forecast?months=' . $months);
    }

    public function recurringNew(Request $request, array $params): ModuleView
    {
        $this->requireAccounts();
        $recurring = ['id' => null, 'account_id' => $this->accountRepo()->findDefault()['id'] ?? 0, 'category_id' => null, 'label' => '', 'payee' => '', 'amount' => null, 'interval_unit' => 'month', 'interval_count' => 1, 'next_at' => $this->today()->format('Y-m-d'), 'ends_at' => '', 'active' => true, 'notes' => ''];
        $content = $this->render('recurring_form', $this->recurringFormVars($recurring, true, 'expense'));
        $banner = $this->renderCore('banner', ['icon' => 'refresh', 'title' => 'Nouvelle récurrence', 'subtitle' => 'Loyer, salaire, abonnement…', 'actions' => $this->backLink('forecast', 'Prévisionnel')]);
        return ModuleView::make('Nouvelle récurrence')->banner($banner)->content($content)->status('Nouvelle récurrence');
    }

    public function recurringEdit(Request $request, array $params): ModuleView
    {
        $recurring = $this->requireRecurring((int) ($params['id'] ?? 0));
        $content = $this->render('recurring_form', $this->recurringFormVars($recurring, false, $recurring['amount'] >= 0 ? 'income' : 'expense'));
        $banner = $this->renderCore('banner', ['icon' => 'refresh', 'title' => (string) $recurring['label'], 'subtitle' => Money::format($recurring['amount'], '', true) . ' · ' . $this->intervalLabel($recurring), 'actions' => $this->backLink('forecast', 'Prévisionnel')]);
        return ModuleView::make('Récurrence · ' . $recurring['label'])->banner($banner)->content($content)->status('Modification de « ' . $recurring['label'] . ' »');
    }

    public function savings(Request $request, array $params): ModuleView
    {
        $currentYear = (int) $this->today()->format('Y');
        $year = (int) $request->query('year', $currentYear);
        $year = $year >= 2000 && $year <= 2100 ? $year : $currentYear;
        $report = $this->savingsReport($year);
        $rights = $this->rights(['create', 'update', 'delete']);
        $years = array_values(array_unique(array_merge([$currentYear], $this->transactionRepo()->years())));
        rsort($years);
        $content = $this->render('savings', [
            'year' => $year,
            'years' => $years,
            'goals' => $this->savingsRepo()->goals(),
            'report' => $report,
            'kinds' => SavingsRepository::SAVING_KINDS,
            'rights' => $rights,
        ]);
        $subtitle = $year . ' : ' . Money::format($report['total'], '0,00 €', true) . ' économisés (' . Money::format($report['automatic_total'], '0,00 €', true) . ' de budget non dépensé, ' . Money::format($report['manual_total'], '0,00 €', true) . ' de gains enregistrés)';
        $actions = $this->navLinks('savings');
        if ($rights['create']) {
            $actions .= '<a class="btn" href="#" data-route="goal/new">' . $this->icon('plus') . '<span>Objectif</span></a>';
            $actions .= '<a class="btn btn--primary" href="#" data-route="saving/new">' . $this->icon('plus') . '<span>Économie réalisée</span></a>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'archive', 'title' => 'Épargne et économies', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Épargne et économies')->banner($banner)->content($content)->status($subtitle)->route($year === $currentYear ? 'savings' : 'savings?year=' . $year);
    }

    public function goalNew(Request $request, array $params): ModuleView
    {
        $goal = ['id' => null, 'name' => '', 'target' => null, 'current' => 0, 'account_id' => null, 'due_at' => '', 'notes' => ''];
        $content = $this->render('goal_form', ['goal' => $goal, 'isNew' => true, 'accounts' => $this->accountRepo()->all()]);
        $banner = $this->renderCore('banner', ['icon' => 'archive', 'title' => 'Nouvel objectif d’épargne', 'subtitle' => 'Vacances, projet, réserve de sécurité…', 'actions' => $this->backLink('savings', 'Épargne et économies')]);
        return ModuleView::make('Nouvel objectif')->banner($banner)->content($content)->status('Nouvel objectif');
    }

    public function goalEdit(Request $request, array $params): ModuleView
    {
        $goal = $this->savingsRepo()->findGoal((int) ($params['id'] ?? 0));
        if ($goal === null) {
            throw new NotFoundException('Objectif introuvable.');
        }
        $content = $this->render('goal_form', ['goal' => $goal, 'isNew' => false, 'accounts' => $this->accountRepo()->all()]);
        $banner = $this->renderCore('banner', ['icon' => 'archive', 'title' => (string) $goal['name'], 'subtitle' => Money::format($goal['progress']) . ' sur ' . Money::format($goal['target']), 'actions' => $this->backLink('savings', 'Épargne et économies')]);
        return ModuleView::make('Objectif · ' . $goal['name'])->banner($banner)->content($content)->status('Modification de « ' . $goal['name'] . ' »');
    }

    public function savingNew(Request $request, array $params): ModuleView
    {
        $saving = ['id' => null, 'label' => '', 'kind' => 'monthly', 'amount' => null, 'effective_from' => $this->today()->format('Y-m-01'), 'effective_to' => '', 'category_id' => null, 'notes' => ''];
        $content = $this->render('saving_form', ['saving' => $saving, 'isNew' => true, 'kinds' => SavingsRepository::SAVING_KINDS, 'categories' => $this->categoryRepo()->tree(false, 'expense')]);
        $banner = $this->renderCore('banner', ['icon' => 'archive', 'title' => 'Économie réalisée', 'subtitle' => 'Changement de fournisseur, renégociation, réparation faite soi-même…', 'actions' => $this->backLink('savings', 'Épargne et économies')]);
        return ModuleView::make('Économie réalisée')->banner($banner)->content($content)->status('Nouvelle économie');
    }

    public function savingEdit(Request $request, array $params): ModuleView
    {
        $saving = $this->savingsRepo()->findSaving((int) ($params['id'] ?? 0));
        if ($saving === null) {
            throw new NotFoundException('Économie introuvable.');
        }
        $content = $this->render('saving_form', ['saving' => $saving, 'isNew' => false, 'kinds' => SavingsRepository::SAVING_KINDS, 'categories' => $this->categoryRepo()->tree(false, 'expense')]);
        $banner = $this->renderCore('banner', ['icon' => 'archive', 'title' => (string) $saving['label'], 'subtitle' => Money::format($saving['amount']) . ' ' . strtolower(SavingsRepository::SAVING_KINDS[$saving['kind']] ?? ''), 'actions' => $this->backLink('savings', 'Épargne et économies')]);
        return ModuleView::make('Économie · ' . $saving['label'])->banner($banner)->content($content)->status('Modification de « ' . $saving['label'] . ' »');
    }

    public function accounts(Request $request, array $params): ModuleView
    {
        $accounts = $this->accountRepo()->all(true);
        $rights = $this->rights(['create', 'update', 'delete']);
        $content = $this->render('accounts', ['accounts' => $accounts, 'kinds' => AccountRepository::KINDS, 'rights' => $rights]);
        $active = array_filter($accounts, static fn (array $a): bool => !$a['archived']);
        $actions = $this->navLinks('accounts');
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="account/new">' . $this->icon('plus') . '<span>Nouveau compte</span></a>';
        }
        $subtitle = count($active) . ' compte' . (count($active) > 1 ? 's' : '') . ' · solde total ' . Money::format(array_sum(array_column($active, 'balance')));
        $banner = $this->renderCore('banner', ['icon' => 'database', 'title' => 'Comptes', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Comptes')->banner($banner)->content($content)->status($subtitle);
    }

    public function accountNew(Request $request, array $params): ModuleView
    {
        $account = ['id' => null, 'name' => '', 'kind' => 'checking', 'initial_balance' => 0, 'opened_at' => '', 'notes' => '', 'sort_order' => 0];
        $content = $this->render('account_form', ['account' => $account, 'isNew' => true, 'kinds' => AccountRepository::KINDS]);
        $banner = $this->renderCore('banner', ['icon' => 'database', 'title' => 'Nouveau compte', 'subtitle' => 'Courant, épargne, espèces…', 'actions' => $this->backLink('accounts', 'Comptes')]);
        return ModuleView::make('Nouveau compte')->banner($banner)->content($content)->status('Création d’un compte');
    }

    public function accountEdit(Request $request, array $params): ModuleView
    {
        $account = $this->requireAccount((int) ($params['id'] ?? 0), true);
        $content = $this->render('account_form', ['account' => $account, 'isNew' => false, 'kinds' => AccountRepository::KINDS]);
        $banner = $this->renderCore('banner', ['icon' => 'database', 'title' => (string) $account['name'], 'subtitle' => 'Solde ' . Money::format($account['balance']), 'actions' => $this->backLink('accounts', 'Comptes')]);
        return ModuleView::make('Compte · ' . $account['name'])->banner($banner)->content($content)->status('Modification de « ' . $account['name'] . ' »');
    }

    public function categories(Request $request, array $params): ModuleView
    {
        $rights = $this->rights(['create', 'update', 'delete']);
        $month = $this->today()->format('Y-m');
        $content = $this->render('categories', [
            'tree' => $this->categoryRepo()->tree(true),
            'envelopes' => $this->categoryRepo()->envelopesFor($month),
            'kinds' => CategoryRepository::KINDS,
            'periods' => CategoryRepository::PERIODS,
            'rights' => $rights,
            'month' => $month,
        ]);
        $count = $this->categoryRepo()->count();
        $actions = $this->navLinks('categories');
        if ($rights['create']) {
            $actions .= '<a class="btn btn--primary" href="#" data-route="category/new">' . $this->icon('plus') . '<span>Nouvelle catégorie</span></a>';
        }
        $banner = $this->renderCore('banner', ['icon' => 'tag', 'title' => 'Catégories', 'subtitle' => $count . ' catégorie' . ($count > 1 ? 's' : ''), 'actions' => $actions]);
        return ModuleView::make('Catégories')->banner($banner)->content($content)->status($count . ' catégorie(s)');
    }

    public function categoryNew(Request $request, array $params): ModuleView
    {
        $kind = $request->query('kind') === 'income' ? 'income' : 'expense';
        $category = ['id' => null, 'parent_id' => (int) $request->query('parent', 0) ?: null, 'name' => '', 'kind' => $kind, 'sort_order' => 0];
        $content = $this->render('category_form', ['category' => $category, 'isNew' => true, 'kinds' => CategoryRepository::KINDS, 'parents' => $this->categoryRepo()->tree(true)]);
        $banner = $this->renderCore('banner', ['icon' => 'tag', 'title' => 'Nouvelle catégorie', 'subtitle' => CategoryRepository::KINDS[$kind], 'actions' => $this->backLink('categories', 'Catégories')]);
        return ModuleView::make('Nouvelle catégorie')->banner($banner)->content($content)->status('Création d’une catégorie');
    }

    public function categoryEdit(Request $request, array $params): ModuleView
    {
        $category = $this->requireCategory((int) ($params['id'] ?? 0));
        $content = $this->render('category_form', ['category' => $category, 'isNew' => false, 'kinds' => CategoryRepository::KINDS, 'parents' => $this->categoryRepo()->tree(true)]);
        $banner = $this->renderCore('banner', ['icon' => 'tag', 'title' => (string) $category['path'], 'subtitle' => CategoryRepository::KINDS[$category['kind']] ?? '', 'actions' => $this->backLink('categories', 'Catégories')]);
        return ModuleView::make('Catégorie · ' . $category['name'])->banner($banner)->content($content)->status('Modification de « ' . $category['name'] . ' »');
    }

    public function settings(Request $request, array $params): ModuleView
    {
        $this->require('admin');
        $settings = $this->ctx->settings;
        $content = $this->render('settings', [
            'accounts' => $this->accountRepo()->all(),
            'categories' => $this->categoryRepo()->tree(false, 'expense'),
            'accountId' => (int) $settings->get(BudgetService::SETTING_ACCOUNT, 0, 'budget'),
            'categoryId' => (int) $settings->get(BudgetService::SETTING_CATEGORY, 0, 'budget'),
            'auto' => (bool) $settings->get(BudgetService::SETTING_AUTO, true, 'budget'),
            'fallbackCategory' => BudgetService::FALLBACK_CATEGORY,
            'maintenanceAvailable' => $this->ctx->modules()->has('maintenance'),
        ]);
        $banner = $this->renderCore('banner', ['icon' => 'settings', 'title' => 'Réglages du budget', 'subtitle' => 'Report des coûts du module Entretien', 'actions' => $this->navLinks('settings')]);
        return ModuleView::make('Réglages du budget')->banner($banner)->content($content)->status('Réglages');
    }

    /** Corbeille du module : éléments supprimés depuis moins de N jours, restauration et suppression définitive. */
    public function trash(Request $request, array $params): ModuleView
    {
        $retention = $this->retentionDays();
        $rows = $this->trashRows($retention);
        $rights = $this->rights(['update', 'delete']);
        $content = $this->render('trash', ['rows' => $rows, 'retention' => $retention, 'rights' => $rights]);
        $actions = $this->navLinks('trash');
        if ($this->ctx->modules()->has('trash')) {
            $actions .= '<a class="btn btn--ghost" href="#" data-open-module="trash" title="Corbeille globale : tous les modules et les pièces jointes">' . $this->icon('trash') . '<span>Voir toute la corbeille</span></a>';
        }
        $subtitle = count($rows) . ' élément' . (count($rows) > 1 ? 's' : '') . ' · purge automatique après ' . $retention . ' jours';
        $banner = $this->renderCore('banner', ['icon' => 'trash', 'title' => 'Corbeille du budget', 'subtitle' => $subtitle, 'actions' => $actions]);
        return ModuleView::make('Corbeille · budget')->banner($banner)->content($content)->status($subtitle);
    }

    // =====================================================================
    // Actions
    // =====================================================================

    /** Badge de la colonne : échéances récurrentes à poster. */
    public function badge(Request $request, array $params): array
    {
        $count = $this->recurringRepo()->countDue($this->today()->format('Y-m-d'));
        return ['count' => $count, 'label' => $count === 0 ? 'Aucune échéance à poster' : $count . ' échéance' . ($count > 1 ? 's' : '') . ' à poster'];
    }

    public function filterTransactions(Request $request, array $params): ActionResult
    {
        $query = $this->transactionsQuery($request->all());
        $query['page'] = 1;
        return ActionResult::ok()->navigate($this->transactionsRoute($query));
    }

    public function transactionSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update', null, 'Vous n’avez pas le droit d’enregistrer des opérations.');
        $existing = $isNew ? null : $this->requireTransaction($id);
        $data = $this->validateTransaction($request->all(), $existing);
        if ($isNew) {
            $id = $this->transactionRepo()->create($data, $this->ctx->userId());
            $this->log('budget.transaction_create', 'success', 'budget_transaction:' . $id, 'Opération créée : ' . $data['label'], ['amount' => $data['amount'], 'account_id' => $data['account_id']]);
            $message = 'Opération « ' . $data['label'] . ' » enregistrée (' . Money::format($data['amount'], '', true) . ').';
            $result = ActionResult::ok(['id' => $id], $message)->dirty(false);
            return $request->bool('again') ? $result->navigate('transaction/new?account=' . $data['account_id'] . '&kind=' . ($data['amount'] >= 0 ? 'income' : 'expense')) : $result->navigate('transactions');
        }
        $this->transactionRepo()->update($id, $data);
        $this->registerTransaction($id, $data['label'], $data['done_at']);
        $this->log('budget.transaction_update', 'success', 'budget_transaction:' . $id, 'Opération modifiée : ' . $data['label'], ['amount' => $data['amount']]);
        return ActionResult::ok(['id' => $id], 'Opération « ' . $data['label'] . ' » enregistrée.')->dirty(false)->navigate('transactions');
    }

    /** Mise en corbeille (les justificatifs joints restent rattachés jusqu'à la purge). */
    public function transactionDelete(Request $request, array $params): ActionResult
    {
        $transaction = $this->requireTransaction($this->requireId($request));
        $this->transactionRepo()->softDelete($transaction['id'], $this->ctx->userId());
        $this->log('budget.transaction_delete', 'success', 'budget_transaction:' . $transaction['id'], 'Opération placée dans la corbeille : ' . $transaction['label'], ['amount' => $transaction['amount']]);
        return ActionResult::ok(null, 'Opération « ' . $transaction['label'] . ' » placée dans la corbeille.')->navigate('transactions');
    }

    /** Pointage / dépointage d'une opération. */
    public function transactionClear(Request $request, array $params): ActionResult
    {
        $transaction = $this->requireTransaction($this->requireId($request));
        $cleared = !$transaction['cleared'];
        $this->transactionRepo()->setCleared($transaction['id'], $cleared);
        return ActionResult::ok(['id' => $transaction['id'], 'cleared' => $cleared], $cleared ? 'Opération pointée.' : 'Opération dépointée.')->refresh();
    }

    /** Action groupée : ids[] + op (clear|unclear|categorize|delete) [+ category_id]. */
    public function transactionsBulk(Request $request, array $params): ActionResult
    {
        $ids = array_values(array_filter(array_map('intval', $request->arrayInput('ids')), static fn (int $id): bool => $id > 0));
        $op = $request->string('op');
        if ($ids === []) {
            return ActionResult::warning(null, 'Aucune opération sélectionnée.');
        }
        switch ($op) {
            case 'clear':
            case 'unclear':
                $count = $this->transactionRepo()->bulkUpdate($ids, ['cleared' => $op === 'clear']);
                $message = $count . ' opération' . ($count > 1 ? 's' : '') . ($op === 'clear' ? ' pointée' : ' dépointée') . ($count > 1 ? 's' : '') . '.';
                break;
            case 'categorize':
                $categoryId = (int) ($request->int('category_id') ?? 0);
                if ($categoryId > 0 && $this->categoryRepo()->find($categoryId) === null) {
                    throw ValidationException::single('category_id', 'Catégorie inconnue.');
                }
                $count = $this->transactionRepo()->bulkUpdate($ids, ['category_id' => $categoryId > 0 ? $categoryId : null]);
                $message = $count . ' opération' . ($count > 1 ? 's' : '') . ' recatégorisée' . ($count > 1 ? 's' : '') . '.';
                break;
            case 'delete':
                $this->require('delete');
                $count = $this->transactionRepo()->softDeleteMany($ids, $this->ctx->userId());
                $message = $count . ' opération' . ($count > 1 ? 's' : '') . ' placée' . ($count > 1 ? 's' : '') . ' dans la corbeille.';
                break;
            default:
                throw ValidationException::single('op', 'Opération groupée inconnue.');
        }
        $this->log('budget.transactions_bulk', 'success', 'budget_transaction', 'Action groupée ' . $op, ['ids' => $ids]);
        return ActionResult::ok(['count' => $count], $message)->refresh();
    }

    /** Import CSV d'un relevé : une opération par ligne, doublons ignorés, erreurs rapportées. */
    public function importRun(Request $request, array $params): ActionResult
    {
        $this->require('import', self::IMPORT_RESOURCE, 'Vous n’êtes pas autorisé à importer des relevés.');
        $account = $this->requireAccount((int) ($request->int('account_id') ?? 0));
        $defaultCategory = (int) ($request->int('category_id') ?? 0);
        if ($defaultCategory > 0 && $this->categoryRepo()->find($defaultCategory) === null) {
            throw ValidationException::single('category_id', 'Catégorie inconnue.');
        }
        $invert = $request->bool('invert');
        $markCleared = $request->bool('cleared');
        $upload = $request->file('file');
        if ($upload === null || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ValidationException::single('file', 'Choisissez un fichier CSV.');
        }
        $rows = $this->readCsv((string) $upload['tmp_name']);
        if ($rows === []) {
            throw ValidationException::single('file', 'Le fichier ne contient aucune ligne exploitable (en-tête attendu : date ; libellé ; montant ou débit/crédit).');
        }
        $created = 0;
        $skipped = 0;
        $errors = [];
        $userId = $this->ctx->userId();
        $categoriesByName = [];
        foreach ($this->categoryRepo()->all() as $category) {
            $categoriesByName[mb_strtolower($category['name'], 'UTF-8')] = $category['id'];
        }
        foreach ($rows as $line => $row) {
            $day = Period::normalizeDay($row['date'] ?? '');
            $label = trim((string) ($row['label'] ?? ''));
            $amount = null;
            if (($row['amount'] ?? '') !== '') {
                $amount = Money::parse($row['amount']);
            } elseif (($row['debit'] ?? '') !== '' || ($row['credit'] ?? '') !== '') {
                $debit = ($row['debit'] ?? '') !== '' ? Money::parse($row['debit']) : 0;
                $credit = ($row['credit'] ?? '') !== '' ? Money::parse($row['credit']) : 0;
                $amount = $debit === null || $credit === null ? null : abs($credit) - abs($debit);
            }
            if ($day === null || $label === '' || $amount === null) {
                $errors[] = ['line' => $line, 'label' => $label, 'message' => $day === null ? 'Date illisible.' : ($label === '' ? 'Libellé vide.' : 'Montant illisible.')];
                continue;
            }
            if ($invert) {
                $amount = -$amount;
            }
            $hash = sha1($account['id'] . '|' . $day . '|' . $amount . '|' . mb_strtolower($label, 'UTF-8'));
            if ($this->transactionRepo()->hashExists($hash)) {
                $skipped++;
                continue;
            }
            $categoryId = null;
            $categoryName = mb_strtolower(trim((string) ($row['category'] ?? '')), 'UTF-8');
            if ($categoryName !== '' && isset($categoriesByName[$categoryName])) {
                $categoryId = $categoriesByName[$categoryName];
            }
            $categoryId ??= $this->transactionRepo()->guessCategory($label) ?? ($defaultCategory > 0 ? $defaultCategory : null);
            $this->transactionRepo()->create([
                'account_id' => $account['id'],
                'category_id' => $categoryId,
                'done_at' => $day,
                'amount' => $amount,
                'label' => mb_substr($label, 0, self::LABEL_MAX, 'UTF-8'),
                'payee' => mb_substr(trim((string) ($row['payee'] ?? '')), 0, 150, 'UTF-8') ?: null,
                'cleared' => $markCleared,
                'source' => 'import',
                'import_hash' => $hash,
            ], $userId);
            $created++;
        }
        $this->log('budget.import', $errors === [] ? 'success' : 'failure', 'budget_transaction', sprintf('Import CSV : %d créée(s), %d doublon(s), %d erreur(s)', $created, $skipped, count($errors)), ['account_id' => $account['id'], 'created' => $created, 'skipped' => $skipped, 'errors' => count($errors), 'file' => (string) ($upload['name'] ?? '')]);
        $message = sprintf('%d opération(s) importée(s) sur « %s »%s%s.', $created, $account['name'], $skipped > 0 ? ', ' . $skipped . ' doublon(s) ignoré(s)' : '', $errors !== [] ? ', ' . count($errors) . ' ligne(s) rejetée(s)' : '');
        $data = ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
        return $errors === [] ? ActionResult::ok($data, $message) : ActionResult::warning($data, $message);
    }

    public function envelopeSave(Request $request, array $params): ActionResult
    {
        $category = $this->requireCategory((int) ($request->int('category_id') ?? 0));
        $period = $request->string('period') === 'year' ? 'year' : 'month';
        $amountRaw = $request->string('amount');
        $amount = $amountRaw === '' ? 0 : Money::parse($amountRaw);
        if ($amount === null || $amount < 0) {
            throw ValidationException::single('amount', 'Montant invalide (exemple : 250).');
        }
        $month = Period::normalizeMonth($request->string('month')) ?? $this->today()->format('Y-m');
        $this->categoryRepo()->saveEnvelope($category['id'], $period, $amount, $month . '-01');
        $this->log('budget.envelope', 'success', 'budget_category:' . $category['id'], 'Budget « ' . $category['path'] . ' » : ' . Money::format($amount) . ' ' . CategoryRepository::PERIODS[$period] . ' à partir de ' . Period::monthLabel($month));
        return ActionResult::ok(['category_id' => $category['id'], 'amount' => $amount], 'Budget de « ' . $category['name'] . ' » : ' . Money::format($amount) . ' ' . CategoryRepository::PERIODS[$period] . ' à partir de ' . Period::monthLabel($month) . '.')->refresh();
    }

    public function envelopeDelete(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        if (!$this->categoryRepo()->deleteEnvelope($id)) {
            throw new NotFoundException('Budget introuvable.');
        }
        return ActionResult::ok(null, 'Budget supprimé.')->refresh();
    }

    public function recurringSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update', null, 'Vous n’avez pas le droit d’enregistrer des récurrences.');
        if (!$isNew) {
            $this->requireRecurring($id);
        }
        $data = $this->validateRecurring($request->all());
        if ($isNew) {
            $id = $this->recurringRepo()->create($data, $this->ctx->userId());
        } else {
            $this->recurringRepo()->update($id, $data);
        }
        $this->log('budget.recurring_' . ($isNew ? 'create' : 'update'), 'success', 'budget_recurring:' . $id, 'Récurrence ' . ($isNew ? 'créée' : 'modifiée') . ' : ' . $data['label'], ['amount' => $data['amount']]);
        return ActionResult::ok(['id' => $id], 'Récurrence « ' . $data['label'] . ' » enregistrée.')->dirty(false)->navigate('forecast');
    }

    public function recurringDelete(Request $request, array $params): ActionResult
    {
        $recurring = $this->requireRecurring($this->requireId($request));
        $this->recurringRepo()->softDelete($recurring['id'], $this->ctx->userId());
        $this->log('budget.recurring_delete', 'success', 'budget_recurring:' . $recurring['id'], 'Récurrence placée dans la corbeille : ' . $recurring['label']);
        return ActionResult::ok(null, 'Récurrence « ' . $recurring['label'] . ' » placée dans la corbeille. Les opérations déjà postées sont conservées.')->refresh();
    }

    /** Poste l'occurrence courante d'une récurrence (crée l'opération, avance l'échéance). */
    public function recurringPost(Request $request, array $params): ActionResult
    {
        $recurring = $this->requireRecurring($this->requireId($request));
        $transactionId = $this->postRecurring($recurring);
        return ActionResult::ok(['id' => $transactionId], 'Opération « ' . $recurring['label'] . ' » postée au ' . Period::dayLabel($recurring['next_at']) . '.')->refresh();
    }

    /** Poste toutes les récurrences dont l'échéance est atteinte. */
    public function recurringPostDue(Request $request, array $params): ActionResult
    {
        $today = $this->today()->format('Y-m-d');
        $count = 0;
        $guard = 0;
        while (($due = $this->recurringRepo()->due($today)) !== [] && $guard++ < 500) {
            foreach ($due as $recurring) {
                $this->postRecurring($recurring);
                $count++;
            }
        }
        return $count === 0
            ? ActionResult::info(null, 'Aucune échéance à poster.')
            : ActionResult::ok(['count' => $count], $count . ' opération' . ($count > 1 ? 's' : '') . ' postée' . ($count > 1 ? 's' : '') . '.')->refresh();
    }

    public function goalSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update');
        if (!$isNew && $this->savingsRepo()->findGoal($id) === null) {
            throw new NotFoundException('Objectif introuvable.');
        }
        $data = $this->validateGoal($request->all());
        if ($isNew) {
            $id = $this->savingsRepo()->createGoal($data, $this->ctx->userId());
        } else {
            $this->savingsRepo()->updateGoal($id, $data);
        }
        $this->log('budget.goal_' . ($isNew ? 'create' : 'update'), 'success', 'budget_goal:' . $id, 'Objectif ' . ($isNew ? 'créé' : 'modifié') . ' : ' . $data['name'], ['target' => $data['target']]);
        return ActionResult::ok(['id' => $id], 'Objectif « ' . $data['name'] . ' » enregistré.')->dirty(false)->navigate('savings');
    }

    public function goalDelete(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $goal = $this->savingsRepo()->findGoal($id);
        if ($goal === null) {
            throw new NotFoundException('Objectif introuvable.');
        }
        $this->savingsRepo()->softDeleteGoal($id, $this->ctx->userId());
        $this->log('budget.goal_delete', 'success', 'budget_goal:' . $id, 'Objectif placé dans la corbeille : ' . $goal['name']);
        return ActionResult::ok(null, 'Objectif « ' . $goal['name'] . ' » placé dans la corbeille.')->refresh();
    }

    public function savingSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update');
        if (!$isNew && $this->savingsRepo()->findSaving($id) === null) {
            throw new NotFoundException('Économie introuvable.');
        }
        $data = $this->validateSaving($request->all());
        if ($isNew) {
            $id = $this->savingsRepo()->createSaving($data, $this->ctx->userId());
        } else {
            $this->savingsRepo()->updateSaving($id, $data);
        }
        $this->log('budget.saving_' . ($isNew ? 'create' : 'update'), 'success', 'budget_saving:' . $id, 'Économie ' . ($isNew ? 'enregistrée' : 'modifiée') . ' : ' . $data['label'], ['amount' => $data['amount'], 'kind' => $data['kind']]);
        return ActionResult::ok(['id' => $id], 'Économie « ' . $data['label'] . ' » enregistrée.')->dirty(false)->navigate('savings');
    }

    public function savingDelete(Request $request, array $params): ActionResult
    {
        $id = $this->requireId($request);
        $saving = $this->savingsRepo()->findSaving($id);
        if ($saving === null) {
            throw new NotFoundException('Économie introuvable.');
        }
        $this->savingsRepo()->softDeleteSaving($id, $this->ctx->userId());
        $this->log('budget.saving_delete', 'success', 'budget_saving:' . $id, 'Économie placée dans la corbeille : ' . $saving['label']);
        return ActionResult::ok(null, 'Économie « ' . $saving['label'] . ' » placée dans la corbeille.')->refresh();
    }

    public function accountSave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update', null, 'Vous n’avez pas le droit d’enregistrer des comptes.');
        if (!$isNew) {
            $this->requireAccount($id, true);
        }
        $data = $this->validateAccount($request->all());
        if ($isNew) {
            $id = $this->accountRepo()->create($data, $this->ctx->userId());
        } else {
            $this->accountRepo()->update($id, $data);
        }
        $this->log('budget.account_' . ($isNew ? 'create' : 'update'), 'success', 'budget_account:' . $id, 'Compte ' . ($isNew ? 'créé' : 'modifié') . ' : ' . $data['name']);
        return ActionResult::ok(['id' => $id], 'Compte « ' . $data['name'] . ' » enregistré.')->dirty(false)->navigate('accounts');
    }

    public function accountArchive(Request $request, array $params): ActionResult
    {
        $account = $this->requireAccount($this->requireId($request), true);
        $archived = !$account['archived'];
        $this->accountRepo()->setArchived($account['id'], $archived);
        $this->log('budget.account_archive', 'success', 'budget_account:' . $account['id'], ($archived ? 'Compte archivé : ' : 'Compte réactivé : ') . $account['name']);
        return ActionResult::ok(['archived' => $archived], 'Compte « ' . $account['name'] . ' » ' . ($archived ? 'archivé' : 'réactivé') . '.')->refresh();
    }

    /** Mise en corbeille : les opérations du compte sont masquées (soldes, listes) jusqu'à sa restauration. */
    public function accountDelete(Request $request, array $params): ActionResult
    {
        $account = $this->requireAccount($this->requireId($request), true);
        $this->accountRepo()->softDelete($account['id'], $this->ctx->userId());
        $masked = (int) $account['transaction_count'];
        $this->log('budget.account_delete', 'success', 'budget_account:' . $account['id'], 'Compte placé dans la corbeille : ' . $account['name'], ['transactions' => $masked]);
        return ActionResult::ok(null, 'Compte « ' . $account['name'] . ' » placé dans la corbeille' . ($masked > 0 ? ' avec ses ' . $masked . ' opération' . ($masked > 1 ? 's' : '') : '') . '.')->refresh();
    }

    public function categorySave(Request $request, array $params): ActionResult
    {
        $id = $request->int('id');
        $isNew = $id === null || $id <= 0;
        $this->require($isNew ? 'create' : 'update', null, 'Vous n’avez pas le droit d’enregistrer des catégories.');
        if (!$isNew) {
            $this->requireCategory($id);
        }
        $data = $this->validateCategory($request->all(), $isNew ? null : $id);
        if ($isNew) {
            $id = $this->categoryRepo()->create($data);
        } else {
            $this->categoryRepo()->update($id, $data);
        }
        $this->log('budget.category_' . ($isNew ? 'create' : 'update'), 'success', 'budget_category:' . $id, 'Catégorie ' . ($isNew ? 'créée' : 'modifiée') . ' : ' . $data['name']);
        return ActionResult::ok(['id' => $id], 'Catégorie « ' . $data['name'] . ' » enregistrée.')->dirty(false)->navigate('categories');
    }

    public function categoryArchive(Request $request, array $params): ActionResult
    {
        $category = $this->requireCategory($this->requireId($request));
        $archived = !$category['archived'];
        $this->categoryRepo()->setArchived($category['id'], $archived);
        return ActionResult::ok(['archived' => $archived], 'Catégorie « ' . $category['name'] . ' » ' . ($archived ? 'archivée' : 'réactivée') . '.')->refresh();
    }

    /**
     * Suppression physique d'une catégorie, refusée (409) tant que des opérations (corbeille comprise),
     * budgets, récurrences ou économies s'y rattachent : archiver est la voie normale.
     */
    public function categoryDelete(Request $request, array $params): ActionResult
    {
        $category = $this->requireCategory($this->requireId($request));
        if ($this->categoryRepo()->hasChildren($category['id'])) {
            throw new ValidationException(['id' => 'Supprimez ou déplacez d’abord ses sous-catégories.']);
        }
        $usage = $this->categoryRepo()->usage($category['id']);
        $parts = array_filter([
            $usage['transactions'] > 0 ? $usage['transactions'] . ' opération(s)' : null,
            $usage['envelopes'] > 0 ? $usage['envelopes'] . ' budget(s)' : null,
            $usage['recurrings'] > 0 ? $usage['recurrings'] . ' récurrence(s)' : null,
            $usage['savings'] > 0 ? $usage['savings'] . ' économie(s)' : null,
        ]);
        if ($parts !== []) {
            throw new ConflictException('La catégorie « ' . $category['name'] . ' » est utilisée par ' . implode(', ', $parts) . ' (corbeille comprise) : archivez-la, ou recatégorisez ces données avant de la supprimer.');
        }
        $this->categoryRepo()->delete($category['id']);
        $this->log('budget.category_delete', 'success', 'budget_category:' . $category['id'], 'Catégorie supprimée : ' . $category['name']);
        return ActionResult::ok(null, 'Catégorie « ' . $category['name'] . ' » supprimée.')->refresh();
    }

    // ----- Corbeille (vue du module) -----

    /** Restaure un élément depuis la vue corbeille du module : { id: "transaction:12" }. */
    public function trashRestore(Request $request, array $params): ActionResult
    {
        $id = $request->string('id');
        $this->restoreTrashItem($id);
        return ActionResult::ok(['id' => $id], '« ' . $this->trashLabel($id) . ' » restauré' . (str_starts_with($id, 'transaction:') || str_starts_with($id, 'recurring:') || str_starts_with($id, 'saving:') ? 'e' : '') . '.')->refresh();
    }

    /** Supprime définitivement un élément depuis la vue corbeille du module. */
    public function trashPurge(Request $request, array $params): ActionResult
    {
        $id = $request->string('id');
        $label = $this->trashLabel($id);
        $this->purgeTrashItem($id);
        return ActionResult::ok(['id' => $id], '« ' . $label . ' » supprimé définitivement.')->refresh();
    }

    // =====================================================================
    // Corbeille globale (TrashProviderInterface)
    // =====================================================================

    /**
     * Éléments en corbeille visibles par l'utilisateur courant (données partagées du module :
     * quiconque ouvre le module les voit ; restauration = update, purge = delete).
     */
    public function trashItems(): array
    {
        $retention = $this->retentionDays();
        $canRestore = $this->can('update');
        $canPurge = $this->can('delete');
        $items = [];
        foreach ($this->trashRows($retention) as $row) {
            $purgeAt = Clock::parseUtc($row['deleted_at'])?->modify('+' . $retention . ' days');
            $items[] = [
                'id' => $row['trash_id'],
                'label' => $row['trash_label'],
                'dataset' => self::TRASH_KINDS[$row['trash_kind']],
                'deleted_at' => (string) $row['deleted_at'],
                'deleted_by' => $row['deleted_by'],
                'purge_at' => $purgeAt === null ? null : Clock::utc($purgeAt),
                'can_restore' => $canRestore,
                'can_purge' => $canPurge,
            ];
        }
        return $items;
    }

    public function restoreTrashItem(string $id): void
    {
        $this->require('update', null, 'Vous n’avez pas le droit de restaurer des données du budget.');
        [$kind, $localId] = $this->parseTrashId($id);
        $row = $this->findTrashedRow($kind, $localId);
        if ($row === null) {
            throw new NotFoundException('Cet élément n’est pas dans la corbeille du budget.');
        }
        match ($kind) {
            'transaction' => $this->transactionRepo()->restore($localId),
            'account' => $this->accountRepo()->restore($localId),
            'recurring' => $this->recurringRepo()->restore($localId),
            'goal' => $this->savingsRepo()->restoreGoal($localId),
            default => $this->savingsRepo()->restoreSaving($localId),
        };
        $this->log('budget.restore', 'success', 'budget_' . $kind . ':' . $localId, 'Restauré depuis la corbeille : ' . $row['trash_label']);
    }

    public function purgeTrashItem(string $id): void
    {
        $this->require('delete', null, 'Vous n’avez pas le droit de supprimer définitivement des données du budget.');
        [$kind, $localId] = $this->parseTrashId($id);
        $row = $this->findTrashedRow($kind, $localId);
        if ($row === null) {
            throw new NotFoundException('Cet élément n’est pas dans la corbeille du budget.');
        }
        $this->destroy($kind, $localId);
        $this->log('budget.purge', 'success', 'budget_' . $kind . ':' . $localId, 'Supprimé définitivement depuis la corbeille : ' . $row['trash_label']);
    }

    /** Rétention de la corbeille (trash.retention_days) : purge physique des éléments expirés. */
    public function purge(): string
    {
        $days = $this->retentionDays();
        $counts = [];
        $sources = [
            'transaction' => $this->transactionRepo()->expiredTrashIds($days),
            'account' => $this->accountRepo()->expiredTrashIds($days),
            'recurring' => $this->recurringRepo()->expiredTrashIds($days),
            'goal' => $this->savingsRepo()->expiredTrashGoalIds($days),
            'saving' => $this->savingsRepo()->expiredTrashSavingIds($days),
        ];
        foreach ($sources as $kind => $ids) {
            $counts[$kind] = 0;
            foreach ($ids as $localId) {
                // Une opération peut avoir été purgée avec son compte au tour précédent.
                if ($this->findTrashedRow($kind, $localId) === null) {
                    continue;
                }
                $this->destroy($kind, $localId);
                $this->log('budget.purge', 'success', 'budget_' . $kind . ':' . $localId, ucfirst($kind) . ' purgé par la rétention (' . $days . ' jours)');
                $counts[$kind]++;
            }
        }
        return sprintf('%d opération(s), %d compte(s), %d récurrence(s), %d objectif(s) et %d économie(s) purgés de la corbeille du budget (> %d jours)', $counts['transaction'], $counts['account'], $counts['recurring'], $counts['goal'], $counts['saving'], $days);
    }

    public function categoriesDefaults(Request $request, array $params): ActionResult
    {
        $count = $this->categoryRepo()->createDefaults();
        $this->log('budget.categories_defaults', 'success', 'budget_category', $count . ' catégorie(s) courante(s) créée(s)');
        return $count === 0 ? ActionResult::info(null, 'Les catégories courantes existent déjà.') : ActionResult::ok(['count' => $count], $count . ' catégorie' . ($count > 1 ? 's' : '') . ' créée' . ($count > 1 ? 's' : '') . '.')->refresh();
    }

    public function settingsSave(Request $request, array $params): ActionResult
    {
        $this->require('admin');
        $accountId = (int) ($request->int('account_id') ?? 0);
        $categoryId = (int) ($request->int('category_id') ?? 0);
        if ($accountId > 0 && $this->accountRepo()->find($accountId) === null) {
            throw ValidationException::single('account_id', 'Compte inconnu.');
        }
        if ($categoryId > 0 && $this->categoryRepo()->find($categoryId) === null) {
            throw ValidationException::single('category_id', 'Catégorie inconnue.');
        }
        $userId = $this->ctx->userId();
        $this->ctx->settings->set(BudgetService::SETTING_ACCOUNT, $accountId, 'budget', $userId);
        $this->ctx->settings->set(BudgetService::SETTING_CATEGORY, $categoryId, 'budget', $userId);
        $this->ctx->settings->set(BudgetService::SETTING_AUTO, $request->bool('auto'), 'budget', $userId);
        $this->log('settings.budget', 'success', 'budget', 'Réglages du budget enregistrés', ['account_id' => $accountId, 'category_id' => $categoryId, 'auto' => $request->bool('auto')]);
        return ActionResult::ok(null, 'Réglages enregistrés.')->dirty(false);
    }

    /** Justificatif joint à une opération (facture, ticket). */
    public function attach(Request $request, array $params): ActionResult
    {
        $transaction = $this->requireTransaction($this->requireId($request));
        $files = array_merge($request->fileList('files'), $request->fileList('file'));
        if ($files === []) {
            throw ValidationException::single('files', 'Choisissez au moins un fichier à joindre.');
        }
        $userId = $this->ctx->userId();
        $infoId = $this->registerTransaction($transaction['id'], (string) $transaction['label'], (string) $transaction['done_at']);
        $stored = [];
        foreach ($files as $file) {
            $record = $this->ctx->shared->attachments->store($file, $infoId, $userId, $request->string('description') ?: null);
            $stored[] = ['id' => $record['id'], 'name' => $record['original_name'], 'size' => $record['size']];
            $this->log('budget.attach', 'success', 'attachment:' . $record['id'], 'Justificatif joint à « ' . $transaction['label'] . ' »', ['size' => $record['size']]);
        }
        return ActionResult::ok(['files' => $stored], count($stored) === 1 ? 'Fichier « ' . $stored[0]['name'] . ' » joint (' . Str::humanSize((int) $stored[0]['size']) . ').' : count($stored) . ' fichiers joints.')->refresh();
    }

    public function attachmentDelete(Request $request, array $params): ActionResult
    {
        $id = $request->string('id');
        $attachment = $id === '' ? null : $this->ctx->shared->attachments->find($id);
        if ($attachment === null) {
            throw new NotFoundException('Pièce jointe introuvable.');
        }
        $info = $attachment['info_id'] === null ? null : $this->ctx->shared->registry->get((string) $attachment['info_id']);
        if ($info === null || $info['dataset_code'] !== self::DATASET_TRANSACTION) {
            throw new ForbiddenException('Cette pièce jointe n’appartient pas au module Budget.');
        }
        $this->ctx->shared->attachments->softDelete($id);
        return ActionResult::ok(null, 'Justificatif mis à la corbeille des fichiers.')->refresh();
    }

    // =====================================================================
    // Export
    // =====================================================================

    public function exportCsv(Request $request, array $params): Response
    {
        $this->require('export', self::EXPORT_RESOURCE, 'Vous n’êtes pas autorisé à exporter les opérations.');
        $query = $this->transactionsQuery($request->allQuery());
        $rows = $this->transactionRepo()->export($this->transactionCriteria($query));
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de préparer l’export.');
        }
        $write = static function (array $fields) use ($handle): void {
            fputcsv($handle, $fields, ';', '"', '', "\r\n");
        };
        $write(['id', 'date', 'compte', 'libelle', 'tiers', 'categorie', 'montant_eur', 'pointee', 'origine', 'notes']);
        foreach ($rows as $row) {
            $write([(string) $row['id'], (string) $row['done_at'], (string) $row['account_name'], (string) $row['label'], (string) ($row['payee'] ?? ''), (string) ($row['category_path'] ?? ''), number_format($row['amount'] / 100, 2, ',', ''), $row['cleared'] ? '1' : '0', (string) $row['source'], BbCode::toText($row['notes'])]);
        }
        rewind($handle);
        $csv = "\xEF\xBB\xBF" . (string) stream_get_contents($handle);
        fclose($handle);
        $this->log('budget.export', 'success', 'budget_transaction', sprintf('Export CSV de %d opération(s)', count($rows)), ['count' => count($rows)]);
        return Response::raw($csv, 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="budget-operations-' . $this->today()->format('Ymd') . '.csv"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    // =====================================================================
    // Helpers publics (gabarits)
    // =====================================================================

    public function icon(string $name, string $extra = ''): string
    {
        return '<svg class="icon' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true"><use href="#i-' . $this->e($name) . '"></use></svg>';
    }

    public function partial(string $template, array $vars = []): string
    {
        return $this->render($template, $vars);
    }

    public function money(?int $cents, string $empty = '—', bool $withSign = false): string
    {
        return Money::format($cents, $empty, $withSign);
    }

    /** Montant avec classe de couleur : rouge dépense, vert recette. */
    public function amountHtml(int $cents, bool $withSign = true): string
    {
        $class = $cents < 0 ? 'text-danger' : ($cents > 0 ? 'text-success' : 'text-muted');
        return '<span class="mono budget__amount ' . $class . '">' . $this->e(Money::format($cents, '0,00 €', $withSign)) . '</span>';
    }

    public function day(?string $day, string $empty = '—'): string
    {
        return Period::dayLabel($day, $empty);
    }

    public function monthLabel(string $month): string
    {
        return Period::monthLabel($month);
    }

    public function bbcode(?string $source): string
    {
        return BbCode::toHtml($source);
    }

    public function sourceLabel(string $source): string
    {
        return TransactionRepository::SOURCES[$source] ?? $source;
    }

    /** « tous les mois », « tous les 3 mois », « chaque année ». @param array<string, mixed> $recurring */
    public function intervalLabel(array $recurring): string
    {
        $count = (int) $recurring['interval_count'];
        $unit = (string) $recurring['interval_unit'];
        if ($count === 1) {
            return match ($unit) { 'day' => 'chaque jour', 'week' => 'chaque semaine', 'year' => 'chaque année', default => 'chaque mois' };
        }
        return 'tous les ' . $count . ' ' . match ($unit) { 'day' => 'jours', 'week' => 'semaines', 'year' => 'ans', default => 'mois' };
    }

    /** Barre de progression simple (pourcentage borné) : classe selon dépassement. */
    public function bar(int $value, int $total, bool $overIsBad = true): string
    {
        $percent = $total > 0 ? (int) round($value * 100 / $total) : ($value > 0 ? 100 : 0);
        $class = $percent > 100 ? ($overIsBad ? 'is-over' : 'is-full') : ($percent >= 90 ? 'is-warning' : '');
        return '<span class="budget__bar ' . $class . '" role="img" aria-label="' . $percent . ' %"><span class="budget__bar-fill" style="width: ' . min(100, max(0, $percent)) . '%"></span></span><span class="text-small text-muted mono">' . $percent . ' %</span>';
    }

    /**
     * Options d'un sélecteur de catégories (groupes par nature et catégorie parente).
     *
     * @param list<array<string, mixed>> $tree
     */
    public function categoryOptions(array $tree, ?int $selected, ?string $kind = null, bool $emptyOption = true): string
    {
        $html = $emptyOption ? '<option value="">— Sans catégorie —</option>' : '';
        foreach (CategoryRepository::KINDS as $code => $label) {
            if ($kind !== null && $kind !== $code) {
                continue;
            }
            $group = '';
            foreach ($tree as $parent) {
                if ($parent['kind'] !== $code) {
                    continue;
                }
                $group .= '<option value="' . (int) $parent['id'] . '"' . ($selected === $parent['id'] ? ' selected' : '') . '>' . $this->e($parent['name']) . '</option>';
                foreach ($parent['children'] ?? [] as $child) {
                    $group .= '<option value="' . (int) $child['id'] . '"' . ($selected === $child['id'] ? ' selected' : '') . '>&nbsp;&nbsp;&nbsp;' . $this->e($child['name']) . '</option>';
                }
            }
            if ($group !== '') {
                $html .= '<optgroup label="' . $this->e($label . 's') . '">' . $group . '</optgroup>';
            }
        }
        return $html;
    }

    public function attachmentUrl(string $id, bool $inline = false): string
    {
        return $this->ctx->baseUrl() . '/files/' . rawurlencode($id) . ($inline ? '?inline=1' : '');
    }

    // =====================================================================
    // Rapports
    // =====================================================================

    /**
     * Réalisé / budget d'un mois par catégorie de premier niveau (agrégation des sous-catégories).
     *
     * @return array{month: string, expense: array{rows: list<array<string, mixed>>, budget: int, spent: int}, income: array{rows: list<array<string, mixed>>, budget: int, spent: int}, uncategorized: int, overspent: int}
     */
    private function envelopeReport(string $month): array
    {
        [$from, $to] = Period::monthRange($month);
        $sums = $this->transactionRepo()->sumByCategory($from, $to);
        $envelopes = $this->categoryRepo()->envelopesFor($month);
        $report = ['month' => $month, 'expense' => ['rows' => [], 'budget' => 0, 'spent' => 0], 'income' => ['rows' => [], 'budget' => 0, 'spent' => 0], 'uncategorized' => $sums[0] ?? 0, 'overspent' => 0];
        foreach ($this->categoryRepo()->tree() as $parent) {
            $kind = (string) $parent['kind'];
            $sign = $kind === 'expense' ? -1 : 1;
            $children = [];
            $childrenBudget = 0;
            $familySpent = $sign * ($sums[$parent['id']] ?? 0);
            foreach ($parent['children'] ?? [] as $child) {
                $childSpent = $sign * ($sums[$child['id']] ?? 0);
                $childBudget = $envelopes[$child['id']]['monthly'] ?? null;
                $childrenBudget += $childBudget ?? 0;
                $familySpent += $childSpent;
                $children[] = ['id' => $child['id'], 'name' => $child['name'], 'budget' => $childBudget, 'spent' => $childSpent, 'remaining' => $childBudget === null ? null : $childBudget - $childSpent, 'envelope' => $envelopes[$child['id']] ?? null];
            }
            $ownEnvelope = $envelopes[$parent['id']] ?? null;
            $budget = $ownEnvelope['monthly'] ?? ($childrenBudget > 0 ? $childrenBudget : null);
            $row = ['id' => $parent['id'], 'name' => $parent['name'], 'budget' => $budget, 'spent' => $familySpent, 'remaining' => $budget === null ? null : $budget - $familySpent, 'envelope' => $ownEnvelope, 'inherited' => $ownEnvelope === null && $childrenBudget > 0, 'children' => $children];
            if ($familySpent === 0 && $budget === null && $children === []) {
                continue;
            }
            $report[$kind]['rows'][] = $row;
            $report[$kind]['budget'] += $budget ?? 0;
            $report[$kind]['spent'] += $familySpent;
            if ($kind === 'expense' && $budget !== null && $familySpent > $budget) {
                $report['overspent']++;
            }
        }
        // Dans le rapport, les dépenses comme les recettes sont exprimées en valeurs positives.
        return $report;
    }

    /**
     * Économies d'une année : budget non dépensé par catégorie de dépense (cumul des mois écoulés)
     * et gains enregistrés dans le registre.
     *
     * @return array{year: int, months: int, automatic: list<array<string, mixed>>, automatic_total: int, manual: list<array<string, mixed>>, manual_total: int, total: int}
     */
    private function savingsReport(int $year): array
    {
        $today = $this->today();
        $currentYear = (int) $today->format('Y');
        $months = $year < $currentYear ? 12 : ($year > $currentYear ? 0 : (int) $today->format('n'));
        $from = sprintf('%04d-01-01', $year);
        $to = $months === 0 ? $from : Period::monthRange(sprintf('%04d-%02d', $year, $months))[1];
        $byMonth = $months === 0 ? [] : $this->transactionRepo()->sumByMonthAndCategory($from, $to);
        $tree = $this->categoryRepo()->tree(true, 'expense');
        $automatic = [];
        $automaticTotal = 0;
        for ($m = 1; $m <= $months; $m++) {
            $month = sprintf('%04d-%02d', $year, $m);
            $envelopes = $this->categoryRepo()->envelopesFor($month);
            $sums = $byMonth[$month] ?? [];
            foreach ($tree as $parent) {
                $childrenBudget = 0;
                $spent = -($sums[$parent['id']] ?? 0);
                foreach ($parent['children'] ?? [] as $child) {
                    $childrenBudget += $envelopes[$child['id']]['monthly'] ?? 0;
                    $spent -= $sums[$child['id']] ?? 0;
                }
                $budget = $envelopes[$parent['id']]['monthly'] ?? ($childrenBudget > 0 ? $childrenBudget : null);
                if ($budget === null) {
                    continue;
                }
                $automatic[$parent['id']] ??= ['id' => $parent['id'], 'name' => $parent['name'], 'budget' => 0, 'spent' => 0, 'months' => 0];
                $automatic[$parent['id']]['budget'] += $budget;
                $automatic[$parent['id']]['spent'] += $spent;
                $automatic[$parent['id']]['months']++;
            }
        }
        foreach ($automatic as &$row) {
            $row['saved'] = $row['budget'] - $row['spent'];
            $automaticTotal += $row['saved'];
        }
        unset($row);
        usort($automatic, static fn (array $a, array $b): int => $b['saved'] <=> $a['saved']);

        $manual = [];
        $manualTotal = 0;
        $yearEnd = sprintf('%04d-12-31', $year);
        foreach ($this->savingsRepo()->savings() as $saving) {
            $realized = SavingsRepository::realized($saving, $from, min($yearEnd, $today->format('Y-m-d')));
            $manual[] = $saving + ['realized' => $realized, 'yearly' => $saving['kind'] === 'monthly' ? $saving['amount'] * 12 : $saving['amount']];
            $manualTotal += $realized;
        }
        return ['year' => $year, 'months' => $months, 'automatic' => array_values($automatic), 'automatic_total' => $automaticTotal, 'manual' => $manual, 'manual_total' => $manualTotal, 'total' => $automaticTotal + $manualTotal];
    }

    /** Coûts estimés des entretiens à venir (module Entretien), pour le prévisionnel. @return list<array{day: string, label: string, amount: int, source: string}> */
    private function maintenanceCosts(int $months): array
    {
        if (!$this->ctx->modules()->has('maintenance')) {
            return [];
        }
        try {
            $service = $this->ctx->moduleService('maintenance');
            if (!method_exists($service, 'upcomingCosts')) {
                return [];
            }
            return array_map(static fn (array $item): array => ['day' => (string) $item['day'], 'label' => (string) $item['label'], 'amount' => -abs((int) $item['amount']), 'source' => 'maintenance'], $service->upcomingCosts($this->ctx->userId(), $months));
        } catch (ModuleUnavailableException | ForbiddenException) {
            return [];
        }
    }

    // =====================================================================
    // Interne
    // =====================================================================

    private function retentionDays(): int
    {
        return max(1, $this->ctx->config->int('trash.retention_days', 30));
    }

    /**
     * Lignes de la corbeille, tous types confondus, décorées de trash_kind / trash_id / trash_label,
     * les plus récemment supprimées d'abord.
     *
     * @return list<array<string, mixed>>
     */
    private function trashRows(int $retention): array
    {
        $rows = [];
        foreach ($this->transactionRepo()->trashed($retention) as $row) {
            $rows[] = $this->decorateTrashRow('transaction', $row);
        }
        foreach ($this->accountRepo()->trashed($retention) as $row) {
            $rows[] = $this->decorateTrashRow('account', $row);
        }
        foreach ($this->recurringRepo()->trashed($retention) as $row) {
            $rows[] = $this->decorateTrashRow('recurring', $row);
        }
        foreach ($this->savingsRepo()->trashedGoals($retention) as $row) {
            $rows[] = $this->decorateTrashRow('goal', $row);
        }
        foreach ($this->savingsRepo()->trashedSavings($retention) as $row) {
            $rows[] = $this->decorateTrashRow('saving', $row);
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['deleted_at'], (string) $a['deleted_at']) ?: strcmp($a['trash_id'], $b['trash_id']));
        $now = Clock::now();
        foreach ($rows as &$row) {
            $deletedAt = Clock::parseUtc($row['deleted_at']);
            $row['expires_in_days'] = $deletedAt === null ? 0 : max(0, $retention - (int) $deletedAt->diff($now)->days);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decorateTrashRow(string $kind, array $row): array
    {
        $row['trash_kind'] = $kind;
        $row['trash_id'] = $kind . ':' . $row['id'];
        $row['trash_label'] = match ($kind) {
            'transaction' => 'Opération du ' . Period::dayLabel($row['done_at']) . ' — ' . $row['label'] . ' — ' . Money::format($row['amount'], '', true),
            'account' => 'Compte « ' . $row['name'] . ' »',
            'recurring' => 'Récurrence « ' . $row['label'] . ' » — ' . Money::format($row['amount'], '', true) . ' ' . $this->intervalLabel($row),
            'goal' => 'Objectif d’épargne « ' . $row['name'] . ' » — ' . Money::format($row['target']),
            default => 'Économie « ' . $row['label'] . ' » — ' . Money::format($row['amount']) . ' ' . strtolower(SavingsRepository::SAVING_KINDS[$row['kind']] ?? ''),
        };
        $row['trash_type'] = match ($kind) {
            'transaction' => 'Opération',
            'account' => 'Compte',
            'recurring' => 'Récurrence',
            'goal' => 'Objectif',
            default => 'Économie',
        };
        return $row;
    }

    /** @return array{0: string, 1: int} type et identifiant local d'un identifiant « type:id » */
    private function parseTrashId(string $id): array
    {
        $parts = explode(':', trim($id), 2);
        $kind = $parts[0] ?? '';
        $localId = (int) ($parts[1] ?? 0);
        if (!isset(self::TRASH_KINDS[$kind]) || $localId <= 0) {
            throw new NotFoundException('Identifiant de corbeille invalide.');
        }
        return [$kind, $localId];
    }

    /** @return array<string, mixed>|null ligne en corbeille décorée, ou null */
    private function findTrashedRow(string $kind, int $localId): ?array
    {
        $row = match ($kind) {
            'transaction' => $this->transactionRepo()->findTrashed($localId),
            'account' => $this->accountRepo()->findTrashed($localId),
            'recurring' => $this->recurringRepo()->findTrashed($localId),
            'goal' => $this->savingsRepo()->findTrashedGoal($localId),
            'saving' => $this->savingsRepo()->findTrashedSaving($localId),
            default => null,
        };
        return $row === null ? null : $this->decorateTrashRow($kind, $row);
    }

    /** Libellé d'un élément en corbeille (pour les messages), ou l'identifiant brut s'il est introuvable. */
    private function trashLabel(string $id): string
    {
        try {
            [$kind, $localId] = $this->parseTrashId($id);
        } catch (NotFoundException) {
            return $id;
        }
        return $this->findTrashedRow($kind, $localId)['trash_label'] ?? $id;
    }

    /**
     * Suppression physique d'un élément en corbeille. Un compte emporte toutes ses opérations
     * (registre commun nettoyé) et ses récurrences ; ses objectifs repassent en progression manuelle.
     */
    private function destroy(string $kind, int $localId): void
    {
        $this->ctx->db->transaction(function () use ($kind, $localId): void {
            switch ($kind) {
                case 'transaction':
                    $this->transactionRepo()->purge($localId);
                    $this->ctx->shared->registry->unregister(self::DATASET_TRANSACTION, (string) $localId);
                    break;
                case 'account':
                    foreach ($this->transactionRepo()->idsForAccount($localId) as $transactionId) {
                        $this->ctx->shared->registry->unregister(self::DATASET_TRANSACTION, (string) $transactionId);
                    }
                    $this->transactionRepo()->deleteForAccount($localId);
                    $this->recurringRepo()->deleteForAccount($localId);
                    $this->savingsRepo()->detachAccount($localId);
                    $this->accountRepo()->purge($localId);
                    break;
                case 'recurring':
                    $this->recurringRepo()->purge($localId);
                    break;
                case 'goal':
                    $this->savingsRepo()->purgeGoal($localId);
                    break;
                default:
                    $this->savingsRepo()->purgeSaving($localId);
            }
        });
    }

    /** Crée l'opération d'une récurrence à son échéance courante et avance celle-ci. @param array<string, mixed> $recurring */
    private function postRecurring(array $recurring): int
    {
        return $this->ctx->db->transaction(function () use ($recurring): int {
            $id = $this->transactionRepo()->create([
                'account_id' => $recurring['account_id'],
                'category_id' => $recurring['category_id'],
                'done_at' => $recurring['next_at'],
                'amount' => $recurring['amount'],
                'label' => $recurring['label'],
                'payee' => $recurring['payee'],
                'notes' => null,
                'cleared' => false,
                'source' => 'recurring',
                'recurring_id' => $recurring['id'],
            ], $this->ctx->userId());
            $next = Period::addInterval((string) $recurring['next_at'], (string) $recurring['interval_unit'], (int) $recurring['interval_count']);
            $active = $recurring['ends_at'] === null || $next <= $recurring['ends_at'];
            $this->recurringRepo()->advance($recurring['id'], $next, $active);
            $this->log('budget.recurring_post', 'success', 'budget_transaction:' . $id, 'Récurrence postée : ' . $recurring['label'] . ' (' . Period::dayLabel($recurring['next_at']) . ')', ['amount' => $recurring['amount'], 'recurring_id' => $recurring['id']]);
            return $id;
        });
    }

    private function registerTransaction(int $id, string $label, string $day): string
    {
        return $this->ctx->shared->registry->register(self::DATASET_TRANSACTION, (string) $id, $label . ' (' . Period::dayLabel($day) . ')', $this->ctx->auth->userId());
    }

    /** @param list<int> $ids @return array<int, int> */
    private function attachmentCounts(array $ids): array
    {
        $counts = [];
        foreach ($ids as $id) {
            $info = $this->ctx->shared->registry->find(self::DATASET_TRANSACTION, (string) $id);
            $counts[$id] = $info === null ? 0 : $this->ctx->shared->attachments->countFor((string) $info['id']);
        }
        return $counts;
    }

    /** @param array<string, mixed> $transaction @return array<string, mixed> */
    private function transactionFormVars(array $transaction, bool $isNew, string $kind): array
    {
        return [
            'transaction' => $transaction,
            'isNew' => $isNew,
            'kind' => $kind,
            'accounts' => $this->accountRepo()->all($isNew ? false : true),
            'categories' => $this->categoryRepo()->tree(),
            'sources' => TransactionRepository::SOURCES,
            'today' => $this->today()->format('Y-m-d'),
        ];
    }

    /** @param array<string, mixed> $recurring @return array<string, mixed> */
    private function recurringFormVars(array $recurring, bool $isNew, string $kind): array
    {
        return [
            'recurring' => $recurring,
            'isNew' => $isNew,
            'kind' => $kind,
            'accounts' => $this->accountRepo()->all(),
            'categories' => $this->categoryRepo()->tree(),
            'units' => RecurringRepository::UNITS,
            'today' => $this->today()->format('Y-m-d'),
        ];
    }

    // ----- Validation -----

    /** @param array<string, mixed> $input @param array<string, mixed>|null $existing @return array<string, mixed> */
    private function validateTransaction(array $input, ?array $existing): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        $accountId = (int) ($input['account_id'] ?? 0);
        $account = $accountId > 0 ? $this->accountRepo()->find($accountId) : null;
        if ($account === null || ($account['archived'] && ($existing === null || $existing['account_id'] !== $accountId))) {
            $errors['account_id'] = 'Choisissez un compte actif.';
        }
        $label = $string('label');
        if ($label === '') {
            $label = $string('payee');
        }
        if ($label === '') {
            $errors['label'] = 'Le libellé est obligatoire.';
        } elseif (mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
            $errors['label'] = sprintf('Le libellé ne peut dépasser %d caractères.', self::LABEL_MAX);
        }
        $doneAt = Period::normalizeDay($string('done_at'));
        if ($doneAt === null) {
            $errors['done_at'] = 'Date invalide (format attendu : AAAA-MM-JJ).';
        }
        $amount = Money::parse($string('amount'));
        if ($amount === null || $amount === 0) {
            $errors['amount'] = 'Montant invalide (exemple : 45,90).';
        } else {
            $amount = $string('type') === 'income' ? abs($amount) : -abs($amount);
        }
        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId > 0 && $this->categoryRepo()->find($categoryId) === null) {
            $errors['category_id'] = 'Catégorie inconnue.';
        }
        if (mb_strlen($string('payee'), 'UTF-8') > 150) {
            $errors['payee'] = 'Le tiers ne peut dépasser 150 caractères.';
        }
        if (mb_strlen($string('notes'), 'UTF-8') > self::TEXT_MAX) {
            $errors['notes'] = sprintf('Les notes ne peuvent dépasser %d caractères.', self::TEXT_MAX);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'account_id' => $accountId,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'done_at' => $doneAt,
            'amount' => $amount,
            'label' => $label,
            'payee' => $string('payee'),
            'notes' => $string('notes'),
            'cleared' => !empty($input['cleared']),
            'source' => $existing['source'] ?? 'manual',
            'source_ref' => $existing['source_ref'] ?? null,
            'recurring_id' => $existing['recurring_id'] ?? null,
            'import_hash' => $existing['import_hash'] ?? null,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validateRecurring(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $accountId = (int) ($input['account_id'] ?? 0);
        $account = $accountId > 0 ? $this->accountRepo()->find($accountId) : null;
        if ($account === null || $account['archived']) {
            $errors['account_id'] = 'Choisissez un compte actif.';
        }
        $label = $string('label');
        if ($label === '') {
            $errors['label'] = 'Le libellé est obligatoire.';
        } elseif (mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
            $errors['label'] = sprintf('Le libellé ne peut dépasser %d caractères.', self::LABEL_MAX);
        }
        $amount = Money::parse($string('amount'));
        if ($amount === null || $amount === 0) {
            $errors['amount'] = 'Montant invalide (exemple : 850).';
        } else {
            $amount = $string('type') === 'income' ? abs($amount) : -abs($amount);
        }
        $unit = $string('interval_unit');
        if (!isset(RecurringRepository::UNITS[$unit])) {
            $errors['interval_unit'] = 'Unité de périodicité inconnue.';
        }
        $count = (int) ($input['interval_count'] ?? 1);
        if ($count < 1 || $count > 366) {
            $errors['interval_count'] = 'Le nombre d’unités doit être compris entre 1 et 366.';
        }
        $nextAt = Period::normalizeDay($string('next_at'));
        if ($nextAt === null) {
            $errors['next_at'] = 'Date de prochaine échéance invalide.';
        }
        $endsAt = null;
        if ($string('ends_at') !== '') {
            $endsAt = Period::normalizeDay($string('ends_at'));
            if ($endsAt === null) {
                $errors['ends_at'] = 'Date de fin invalide.';
            } elseif ($nextAt !== null && $endsAt < $nextAt) {
                $errors['ends_at'] = 'La fin doit être postérieure à la prochaine échéance.';
            }
        }
        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId > 0 && $this->categoryRepo()->find($categoryId) === null) {
            $errors['category_id'] = 'Catégorie inconnue.';
        }
        if (mb_strlen($string('payee'), 'UTF-8') > 150) {
            $errors['payee'] = 'Le tiers ne peut dépasser 150 caractères.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'account_id' => $accountId,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'label' => $label,
            'payee' => $string('payee'),
            'amount' => $amount,
            'interval_unit' => $unit,
            'interval_count' => $count,
            'next_at' => $nextAt,
            'ends_at' => $endsAt,
            'active' => !isset($input['active']) || !empty($input['active']),
            'notes' => $string('notes'),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validateGoal(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $name = $string('name');
        if ($name === '') {
            $errors['name'] = 'Le nom est obligatoire.';
        } elseif (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            $errors['name'] = sprintf('Le nom ne peut dépasser %d caractères.', self::NAME_MAX);
        }
        $target = Money::parse($string('target'));
        if ($target === null || $target <= 0) {
            $errors['target'] = 'Montant cible invalide (exemple : 3 000).';
        }
        $current = $string('current') === '' ? 0 : Money::parse($string('current'));
        if ($current === null || $current < 0) {
            $errors['current'] = 'Montant épargné invalide.';
        }
        $accountId = (int) ($input['account_id'] ?? 0);
        if ($accountId > 0 && $this->accountRepo()->find($accountId) === null) {
            $errors['account_id'] = 'Compte inconnu.';
        }
        $dueAt = null;
        if ($string('due_at') !== '') {
            $dueAt = Period::normalizeDay($string('due_at'));
            if ($dueAt === null) {
                $errors['due_at'] = 'Date invalide.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['name' => $name, 'target' => $target, 'current' => $current, 'account_id' => $accountId > 0 ? $accountId : null, 'due_at' => $dueAt, 'notes' => $string('notes')];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validateSaving(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $label = $string('label');
        if ($label === '') {
            $errors['label'] = 'Le libellé est obligatoire.';
        } elseif (mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
            $errors['label'] = sprintf('Le libellé ne peut dépasser %d caractères.', self::LABEL_MAX);
        }
        $kind = $string('kind');
        if (!isset(SavingsRepository::SAVING_KINDS[$kind])) {
            $errors['kind'] = 'Nature inconnue.';
        }
        $amount = Money::parse($string('amount'));
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'Montant invalide (exemple : 18,00).';
        }
        $from = Period::normalizeDay($string('effective_from'));
        if ($from === null) {
            $errors['effective_from'] = 'Date d’effet invalide.';
        }
        $to = null;
        if ($string('effective_to') !== '') {
            $to = Period::normalizeDay($string('effective_to'));
            if ($to === null) {
                $errors['effective_to'] = 'Date de fin invalide.';
            } elseif ($from !== null && $to < $from) {
                $errors['effective_to'] = 'La fin doit être postérieure à la date d’effet.';
            }
        }
        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId > 0 && $this->categoryRepo()->find($categoryId) === null) {
            $errors['category_id'] = 'Catégorie inconnue.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['label' => $label, 'kind' => $kind, 'amount' => $amount, 'effective_from' => $from, 'effective_to' => $to, 'category_id' => $categoryId > 0 ? $categoryId : null, 'notes' => $string('notes')];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validateAccount(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $name = $string('name');
        if ($name === '') {
            $errors['name'] = 'Le nom du compte est obligatoire.';
        } elseif (mb_strlen($name, 'UTF-8') > 100) {
            $errors['name'] = 'Le nom ne peut dépasser 100 caractères.';
        }
        $kind = $string('kind');
        if (!isset(AccountRepository::KINDS[$kind])) {
            $errors['kind'] = 'Nature de compte inconnue.';
        }
        $initial = $string('initial_balance') === '' ? 0 : Money::parse($string('initial_balance'));
        if ($initial === null) {
            $errors['initial_balance'] = 'Solde initial invalide (exemple : 1 250,00 ou -80).';
        }
        $openedAt = null;
        if ($string('opened_at') !== '') {
            $openedAt = Period::normalizeDay($string('opened_at'));
            if ($openedAt === null) {
                $errors['opened_at'] = 'Date invalide.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['name' => $name, 'kind' => $kind, 'initial_balance' => $initial, 'opened_at' => $openedAt, 'notes' => $string('notes'), 'sort_order' => (int) ($input['sort_order'] ?? 0)];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validateCategory(array $input, ?int $exceptId): array
    {
        $errors = [];
        $string = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $name = $string('name');
        if ($name === '') {
            $errors['name'] = 'Le nom est obligatoire.';
        } elseif (mb_strlen($name, 'UTF-8') > 100) {
            $errors['name'] = 'Le nom ne peut dépasser 100 caractères.';
        }
        $kind = $string('kind');
        if (!isset(CategoryRepository::KINDS[$kind])) {
            $errors['kind'] = 'Nature inconnue.';
        }
        $parentId = (int) ($input['parent_id'] ?? 0);
        if ($parentId > 0) {
            $parent = $this->categoryRepo()->find($parentId);
            if ($parent === null || $parent['parent_id'] !== null) {
                $errors['parent_id'] = 'La catégorie parente doit être une catégorie de premier niveau.';
            } elseif ($parent['kind'] !== $kind) {
                $errors['parent_id'] = 'La catégorie parente doit être de la même nature.';
            } elseif ($exceptId !== null && ($parentId === $exceptId || $this->categoryRepo()->hasChildren($exceptId))) {
                $errors['parent_id'] = 'Une catégorie qui a des sous-catégories ne peut pas devenir une sous-catégorie.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['name' => $name, 'kind' => $kind, 'parent_id' => $parentId > 0 ? $parentId : null, 'sort_order' => (int) ($input['sort_order'] ?? 0)];
    }

    // ----- Import CSV -----

    /** @return array<int, array<string, string>> lignes indexées par numéro, clés normalisées */
    private function readCsv(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $first = $lines[0] ?? '';
        $delimiter = ';';
        $best = substr_count($first, ';');
        foreach ([',' => substr_count($first, ','), "\t" => substr_count($first, "\t")] as $candidate => $count) {
            if ($count > $best) {
                $best = $count;
                $delimiter = (string) $candidate;
            }
        }
        $headers = str_getcsv($first, $delimiter, '"', '');
        $map = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);
            foreach (self::IMPORT_HEADERS as $field => $aliases) {
                if (in_array($normalized, $aliases, true) && !isset($map[$field])) {
                    $map[$field] = $index;
                }
            }
        }
        if (!isset($map['date']) || !isset($map['label']) || (!isset($map['amount']) && !isset($map['debit']) && !isset($map['credit']))) {
            return [];
        }
        $rows = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = str_getcsv($line, $delimiter, '"', '');
            $row = [];
            foreach ($map as $field => $index) {
                $row[$field] = trim((string) ($fields[$index] ?? ''));
            }
            $rows[$i + 2] = $row;
            if (count($rows) >= self::IMPORT_MAX_ROWS) {
                break;
            }
        }
        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header, " \t\"'"), 'UTF-8');
        $header = strtr($header, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', '€' => 'euros']);
        return trim(preg_replace('/[^a-z0-9-]+/', '-', $header) ?? $header, '-');
    }

    // ----- Requêtes et routes -----

    /** @param array<string, mixed> $input @return array{q: string, account: int, category: int, month: string, uncleared: bool, source: string, page: int, per_page: int} */
    private function transactionsQuery(array $input): array
    {
        $default = (int) $this->ctx->settings->preference($this->ctx->userId(), 'pageSize', 50);
        $perPage = (int) ($input['per_page'] ?? $default);
        $category = (int) ($input['category'] ?? 0);
        return [
            'q' => is_scalar($input['q'] ?? null) ? trim((string) $input['q']) : '',
            'account' => max(0, (int) ($input['account'] ?? 0)),
            'category' => $category < -1 ? 0 : $category,
            'month' => Period::normalizeMonth((string) ($input['month'] ?? '')) ?? '',
            'uncleared' => !empty($input['uncleared']),
            'source' => isset(TransactionRepository::SOURCES[(string) ($input['source'] ?? '')]) ? (string) $input['source'] : '',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PER_PAGE_CHOICES, true) ? $perPage : 50,
        ];
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function transactionCriteria(array $query): array
    {
        return ['account' => $query['account'], 'category' => $query['category'], 'month' => $query['month'], 'from' => '', 'to' => '', 'q' => $query['q'], 'uncleared' => $query['uncleared'], 'source' => $query['source']];
    }

    /** @param array<string, mixed> $query */
    private function isFiltered(array $query): bool
    {
        return $query['q'] !== '' || $query['account'] > 0 || $query['category'] !== 0 || $query['month'] !== '' || $query['uncleared'] || $query['source'] !== '';
    }

    /** @param array<string, mixed> $query */
    private function transactionsRoute(array $query): string
    {
        $params = array_filter([
            'q' => $query['q'],
            'account' => $query['account'] > 0 ? $query['account'] : null,
            'category' => $query['category'] !== 0 ? $query['category'] : null,
            'month' => $query['month'],
            'uncleared' => $query['uncleared'] ? 1 : null,
            'source' => $query['source'],
            'per_page' => $query['per_page'] !== 50 ? $query['per_page'] : null,
            'page' => $query['page'] > 1 ? $query['page'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        return $params === [] ? 'transactions' : 'transactions?' . http_build_query($params);
    }

    private function queryString(string $route): string
    {
        return str_contains($route, '?') ? '?' . explode('?', $route, 2)[1] : '';
    }

    private function today(): DateTimeImmutable
    {
        return Clock::now()->setTimezone(new DateTimeZone($this->ctx->config->string('app.timezone', 'Europe/Paris')))->setTime(0, 0);
    }

    private function countLabel(int $total, string $noun, bool $filtered): string
    {
        $label = $total === 1 ? '1 ' . $noun : number_format($total, 0, ',', ' ') . ' ' . $noun . 's';
        return $filtered ? $label . ' (filtrées)' : $label;
    }

    private function backLink(string $route, string $label): string
    {
        return '<a class="btn btn--ghost" href="#" data-route="' . $this->e($route) . '">' . $this->icon('chevron-left') . '<span>' . $this->e($label) . '</span></a>';
    }

    private function navLinks(string $current): string
    {
        $links = [
            'dashboard' => ['Tableau de bord', 'home'],
            'transactions' => ['Opérations', 'list'],
            'envelopes' => ['Budget', 'grid'],
            'forecast' => ['Prévisionnel', 'calendar'],
            'savings' => ['Épargne', 'archive'],
            'accounts' => ['Comptes', 'database'],
            'categories' => ['Catégories', 'tag'],
        ];
        if ($current === 'trash' || $this->can('delete')) {
            $links['trash'] = ['Corbeille', 'trash'];
        }
        $html = '<div class="btn-group" role="group" aria-label="Écrans du module Budget">';
        foreach ($links as $route => [$label, $icon]) {
            $active = $route === $current;
            $html .= '<a class="btn btn--sm' . ($active ? ' is-active' : '') . '" href="#" data-route="' . $this->e($route) . '" title="' . $this->e($label) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $this->icon($icon, 'icon--sm') . '<span class="budget__nav-label">' . $this->e($label) . '</span></a>';
        }
        return $html . '</div>';
    }

    private function requireAccounts(): void
    {
        if ($this->accountRepo()->all() === []) {
            throw new ValidationException(['account_id' => 'Créez d’abord un compte (écran Comptes).']);
        }
    }

    /** @return array<string, mixed> */
    private function requireAccount(int $id, bool $includeArchived = false): array
    {
        $account = $id > 0 ? $this->accountRepo()->find($id) : null;
        if ($account === null || (!$includeArchived && $account['archived'])) {
            throw new NotFoundException('Compte introuvable.');
        }
        return $account;
    }

    /** @return array<string, mixed> */
    private function requireCategory(int $id): array
    {
        $category = $id > 0 ? $this->categoryRepo()->find($id) : null;
        if ($category === null) {
            throw new NotFoundException('Catégorie introuvable.');
        }
        return $category;
    }

    /** @return array<string, mixed> */
    private function requireTransaction(int $id): array
    {
        $transaction = $id > 0 ? $this->transactionRepo()->find($id) : null;
        if ($transaction === null) {
            throw new NotFoundException('Opération introuvable.');
        }
        return $transaction;
    }

    /** @return array<string, mixed> */
    private function requireRecurring(int $id): array
    {
        $recurring = $id > 0 ? $this->recurringRepo()->find($id) : null;
        if ($recurring === null) {
            throw new NotFoundException('Récurrence introuvable.');
        }
        return $recurring;
    }

    private function requireId(Request $request, string $key = 'id'): int
    {
        $id = $request->int($key);
        if ($id === null || $id <= 0) {
            throw ValidationException::single($key, 'Identifiant manquant.');
        }
        return $id;
    }

    private function accountRepo(): AccountRepository
    {
        return $this->accounts ??= new AccountRepository($this->ctx->db);
    }

    private function categoryRepo(): CategoryRepository
    {
        return $this->categories ??= new CategoryRepository($this->ctx->db);
    }

    private function transactionRepo(): TransactionRepository
    {
        return $this->transactions ??= new TransactionRepository($this->ctx->db);
    }

    private function recurringRepo(): RecurringRepository
    {
        return $this->recurrings ??= new RecurringRepository($this->ctx->db);
    }

    private function savingsRepo(): SavingsRepository
    {
        return $this->savings ??= new SavingsRepository($this->ctx->db);
    }
}
