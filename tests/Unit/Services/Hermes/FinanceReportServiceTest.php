<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Hermes;

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Journal\JournalRepository;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\Services\Hermes\FinanceActionService;
use FireflyIII\Services\Hermes\FinanceReportService;
use FireflyIII\User;
use Log;
use Tests\TestCase;

class FinanceReportServiceTest extends TestCase
{
    /** @var User */
    private $user;

    /** @var TransactionCurrency */
    private $eur;

    /** @var TransactionCurrency */
    private $rub;

    /** @var Account */
    private $asset;

    /** @var Account */
    private $expenseActor;

    /** @var Account */
    private $revenueActor;

    /** @var Category */
    private $category;

    /** @var Budget */
    private $budget;

    /** @var string */
    private $suffix;

    /** @var Carbon */
    private $periodStart;

    /** @var Carbon */
    private $periodEnd;

    /** @var Carbon */
    private $transactionDate;

    public function setUp(): void
    {
        parent::setUp();
        Log::info(sprintf('Now in %s.', \get_class($this)));

        $this->app->instance(JournalRepositoryInterface::class, new JournalRepository);

        $this->user         = $this->user();
        $this->eur          = TransactionCurrency::where('code', 'EUR')->first();
        $this->rub          = TransactionCurrency::where('code', 'RUB')->first();
        $this->rub->enabled = true;
        $this->rub->save();
        $this->suffix       = 'Hermes report ' . random_int(100000, 999999);
        $this->periodStart  = Carbon::create(2040 + random_int(0, 40), random_int(1, 12), 1)->startOfMonth();
        $this->periodEnd    = $this->periodStart->copy()->endOfMonth();
        $this->transactionDate = $this->periodStart->copy()->addDays(14);
        $this->asset        = $this->account('Стас / CGD ' . $this->suffix, AccountType::ASSET);
        $this->expenseActor = $this->account('Стас ' . $this->suffix, AccountType::EXPENSE);
        $this->revenueActor = $this->account('Работа ' . $this->suffix, AccountType::REVENUE);
        $this->category     = Category::create(['user_id' => $this->user->id, 'name' => 'Еда ' . $this->suffix]);
        $this->budget       = Budget::create(['user_id' => $this->user->id, 'name' => 'Португалия ' . $this->suffix, 'active' => true, 'order' => 1]);
        $this->budgetLimit($this->budget, $this->periodStart->toDateString(), $this->periodEnd->toDateString(), '100.00');

        config()->set('hermes_finance.preview_ttl_minutes', 15);
        config()->set('hermes_finance_aliases.asset_accounts', [['name' => $this->asset->name, 'aliases' => ['report-cgd']]]);
        config()->set(
            'hermes_finance_aliases.actors',
            [
                ['name' => $this->expenseActor->name, 'aliases' => ['report-me']],
                ['name' => $this->revenueActor->name, 'aliases' => ['report-work']],
            ]
        );
        config()->set('hermes_finance_aliases.categories', [['name' => $this->category->name, 'aliases' => ['report-food']]]);
        config()->set('hermes_finance_aliases.budgets', [['name' => $this->budget->name, 'aliases' => ['report-portugal']]]);
        config()->set(
            'hermes_finance_aliases.currencies',
            [
                ['name' => 'EUR', 'aliases' => ['report-eur']],
                ['name' => 'RUB', 'aliases' => ['report-rub']],
            ]
        );
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceReportService
     */
    public function testBudgetRemainingUsesBudgetLimitCurrencyOnly(): void
    {
        $this->createWithdrawal('25.00', 'report-eur', 'EUR lunch');
        $this->createWithdrawal('50.00', 'report-rub', 'RUB lunch');

        $result = $this->reports()->report(
            $this->user,
            [
                'report'   => 'budget_remaining',
                'budget'   => 'report-portugal',
                'currency' => 'report-eur',
                'date'     => $this->transactionDate->toDateString(),
            ]
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame(100.0, (float)$result['payload']['budgeted']);
        $this->assertSame(-25.0, (float)$result['payload']['spent']);
        $this->assertSame(75.0, (float)$result['payload']['left']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceReportService
     */
    public function testPeriodSummaryGroupsIncomeExpensesAndNetByCurrency(): void
    {
        $this->createWithdrawal('25.00', 'report-eur', 'EUR summary lunch');
        $this->createDeposit('100.00', 'report-eur', 'EUR salary');

        $result = $this->reports()->report(
            $this->user,
            [
                'report' => 'period_summary',
                'start'  => $this->periodStart->toDateString(),
                'end'    => $this->periodEnd->toDateString(),
            ]
        );

        $eur = $this->currencySummary($result['payload']['currencies'], 'EUR');
        $this->assertSame([], $result['errors']);
        $this->assertSame(100.0, (float)$eur['income']);
        $this->assertSame(-25.0, (float)$eur['expenses']);
        $this->assertSame(75.0, (float)$eur['net']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceReportService
     */
    public function testAccountBalanceMatchesSteamBalance(): void
    {
        $this->createWithdrawal('12.00', 'report-eur', 'Balance lunch');

        $result = $this->reports()->report(
            $this->user,
            [
                'report'         => 'account_balances',
                'source_account' => 'report-cgd',
                'date'           => $this->periodEnd->toDateString(),
            ]
        );

        $expected = app('steam')->balance($this->asset, $this->periodEnd);
        $this->assertSame([], $result['errors']);
        $this->assertSame($expected, $result['payload']['accounts'][0]['balance']);
    }

    private function reports(): FinanceReportService
    {
        return app(FinanceReportService::class);
    }

    private function createWithdrawal(string $amount, string $currencyAlias, string $description): void
    {
        $this->createTransaction(
            [
                'action'           => 'create',
                'transaction_type' => 'withdrawal',
                'amount'           => $amount,
                'description'      => $description . ' ' . $this->suffix,
                'date'             => $this->transactionDate->toDateString(),
                'source_account'   => 'report-cgd',
                'actor'            => 'report-me',
                'category'         => 'report-food',
                'budget'           => 'report-portugal',
                'currency'         => $currencyAlias,
                'source'           => 'phpunit-report',
            ]
        );
    }

    private function createDeposit(string $amount, string $currencyAlias, string $description): void
    {
        $this->createTransaction(
            [
                'action'              => 'create',
                'transaction_type'    => 'deposit',
                'amount'              => $amount,
                'description'         => $description . ' ' . $this->suffix,
                'date'                => $this->transactionDate->copy()->addDay()->toDateString(),
                'actor'               => 'report-work',
                'destination_account' => 'report-cgd',
                'currency'            => $currencyAlias,
                'source'              => 'phpunit-report',
            ]
        );
    }

    private function createTransaction(array $input): void
    {
        /** @var FinanceActionService $service */
        $service = app(FinanceActionService::class);
        $preview = $service->preview($this->user, $input)->toArray();
        $this->assertTrue($preview['can_apply'], implode(', ', $preview['errors']));
        $result = $service->apply(
            $this->user,
            [
                'preview_token'   => $preview['preview_token'],
                'idempotency_key' => 'report-' . md5($input['description'] . random_int(1, 999999)),
                'source'          => 'phpunit-report',
            ]
        );
        $this->assertTrue($result['applied']);
    }

    private function currencySummary(array $summaries, string $code): array
    {
        foreach ($summaries as $summary) {
            if ($summary['currency']['code'] === $code) {
                return $summary;
            }
        }

        $this->fail('Currency summary not found: ' . $code);
    }

    private function account(string $name, string $type): Account
    {
        $accountType = AccountType::where('type', $type)->first();

        return Account::create(
            [
                'user_id'          => $this->user->id,
                'account_type_id'  => $accountType->id,
                'name'             => $name,
                'active'           => true,
                'virtual_balance'  => '0',
            ]
        );
    }

    private function budgetLimit(Budget $budget, string $start, string $end, string $amount): BudgetLimit
    {
        $limit                          = new BudgetLimit;
        $limit->budget_id               = $budget->id;
        $limit->start_date              = new Carbon($start);
        $limit->end_date                = new Carbon($end);
        $limit->amount                  = $amount;
        $limit->transaction_currency_id = $this->eur->id;
        $limit->save();

        return $limit;
    }
}
