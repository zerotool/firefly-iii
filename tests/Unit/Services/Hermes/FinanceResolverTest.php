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
use FireflyIII\Services\Hermes\FinanceResolver;
use FireflyIII\User;
use Log;
use Tests\TestCase;

class FinanceResolverTest extends TestCase
{
    /** @var User */
    private $user;

    /** @var TransactionCurrency */
    private $eur;

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

    /** @var BudgetLimit */
    private $budgetLimit;

    /** @var string */
    private $suffix;

    public function setUp(): void
    {
        parent::setUp();
        Log::info(sprintf('Now in %s.', \get_class($this)));

        $this->user   = $this->user();
        $this->eur    = TransactionCurrency::where('code', 'EUR')->first();
        $this->suffix = 'Hermes ' . random_int(100000, 999999);

        $this->asset        = $this->account('Стас / CGD (ЕВРО) ' . $this->suffix, AccountType::ASSET);
        $this->expenseActor = $this->account('Стас ' . $this->suffix, AccountType::EXPENSE);
        $this->revenueActor = $this->account('Стас ' . $this->suffix, AccountType::REVENUE);
        $this->category     = Category::create(['user_id' => $this->user->id, 'name' => 'Еда ' . $this->suffix]);
        $this->budget       = Budget::create(['user_id' => $this->user->id, 'name' => 'Стас и Таня в Португалии (EUR) ' . $this->suffix, 'active' => true, 'order' => 1]);
        $this->budgetLimit  = $this->budgetLimit($this->budget, '2026-06-01', '2026-06-30', '2000.00');

        config()->set(
            'hermes_finance_aliases.asset_accounts',
            [['name' => $this->asset->name, 'aliases' => ['cgd']]]
        );
        config()->set(
            'hermes_finance_aliases.actors',
            [['name' => $this->expenseActor->name, 'aliases' => ['я', 'стас']]]
        );
        config()->set(
            'hermes_finance_aliases.categories',
            [['name' => $this->category->name, 'aliases' => ['еда']]]
        );
        config()->set(
            'hermes_finance_aliases.budgets',
            [['name' => $this->budget->name, 'aliases' => ['португалия']]]
        );
        config()->set(
            'hermes_finance_aliases.currencies',
            [['name' => 'EUR', 'aliases' => ['евро']]]
        );
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceResolver
     * @covers \FireflyIII\Services\Hermes\ResolvedFinanceIntent
     */
    public function testResolvesConfiguredWithdrawalSlots(): void
    {
        $result = $this->resolver()->resolve(
            $this->user,
            [
                'transaction_type' => 'withdrawal',
                'source_account'   => 'cgd',
                'actor'            => 'я',
                'category'         => 'еда',
                'budget'           => 'португалия',
                'currency'         => 'евро',
                'date'             => '2026-06-29',
            ]
        )->toArray();

        $this->assertFalse($result['requires_confirmation'], json_encode($result));
        $this->assertSame($this->asset->id, $result['entities']['source_account']['selected']['id']);
        $this->assertSame($this->expenseActor->id, $result['entities']['actor']['selected']['id']);
        $this->assertSame($this->category->id, $result['entities']['category']['selected']['id']);
        $this->assertSame($this->budget->id, $result['entities']['budget']['selected']['id']);
        $this->assertSame($this->budgetLimit->id, $result['entities']['budget_limit']['selected']['id']);
        $this->assertSame('EUR', $result['entities']['currency']['selected']['code']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceResolver
     */
    public function testDepositActorUsesRevenueSide(): void
    {
        config()->set(
            'hermes_finance_aliases.actors',
            [['name' => $this->revenueActor->name, 'aliases' => ['я', 'стас']]]
        );

        $result = $this->resolver()->resolve(
            $this->user,
            [
                'transaction_type' => 'deposit',
                'actor'            => 'я',
            ]
        )->toArray();

        $this->assertSame($this->revenueActor->id, $result['entities']['actor']['selected']['id']);
        $this->assertSame(AccountType::REVENUE, $result['entities']['actor']['selected']['account_type']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceResolver
     */
    public function testOverlappingBudgetLimitsAreAmbiguous(): void
    {
        $this->budgetLimit($this->budget, '2026-06-15', '2026-07-15', '1000.00');

        $result = $this->resolver()->resolve(
            $this->user,
            [
                'budget'   => 'португалия',
                'currency' => 'евро',
                'date'     => '2026-06-29',
            ]
        )->toArray();

        $this->assertTrue($result['requires_confirmation']);
        $this->assertContains('budget_limit', $result['ambiguous']);
        $this->assertCount(2, $result['entities']['budget_limit']['candidates']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceResolver
     */
    public function testAmbiguousSourceAccountsRequireConfirmation(): void
    {
        config()->set('hermes_finance_aliases.asset_accounts', []);
        $this->account('CGD backup ' . $this->suffix, AccountType::ASSET);
        $this->account('CGD savings ' . $this->suffix, AccountType::ASSET);

        $result = $this->resolver()->resolve(
            $this->user,
            [
                'source_account' => 'CGD',
            ]
        )->toArray();

        $this->assertTrue($result['requires_confirmation']);
        $this->assertContains('source_account', $result['ambiguous']);
        $this->assertGreaterThanOrEqual(2, \count($result['entities']['source_account']['candidates']));
    }

    private function resolver(): FinanceResolver
    {
        return app(FinanceResolver::class);
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
