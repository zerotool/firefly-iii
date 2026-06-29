<?php

declare(strict_types=1);

namespace Tests\Api\V1\Controllers\Hermes;

use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Category;
use FireflyIII\Repositories\Journal\JournalRepository;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use Log;
use Tests\TestCase;

class FinanceTransactionControllerTest extends TestCase
{
    private const TOKEN = 'test-hermes-token';

    /** @var string */
    private $suffix;

    public function setUp(): void
    {
        parent::setUp();
        Log::info(sprintf('Now in %s.', \get_class($this)));

        $this->app->instance(JournalRepositoryInterface::class, new JournalRepository);

        $this->suffix = 'Hermes API ' . random_int(100000, 999999);
        $asset        = $this->account('Стас / CGD ' . $this->suffix, AccountType::ASSET);
        $actor        = $this->account('Стас ' . $this->suffix, AccountType::EXPENSE);
        $category     = Category::create(['user_id' => $this->user()->id, 'name' => 'Еда ' . $this->suffix]);

        config()->set('hermes_finance.enabled', true);
        config()->set('hermes_finance.token_hash', hash('sha256', self::TOKEN));
        config()->set('hermes_finance.ledger_user_id', $this->user()->id);
        config()->set('hermes_finance.allowed_cidrs', []);
        config()->set('hermes_finance_aliases.asset_accounts', [['name' => $asset->name, 'aliases' => ['api-cgd']]]);
        config()->set('hermes_finance_aliases.actors', [['name' => $actor->name, 'aliases' => ['api-me']]]);
        config()->set('hermes_finance_aliases.categories', [['name' => $category->name, 'aliases' => ['api-food']]]);
        config()->set('hermes_finance_aliases.budgets', []);
        config()->set('hermes_finance_aliases.currencies', [['name' => 'EUR', 'aliases' => ['api-eur']]]);
    }

    /**
     * @covers \FireflyIII\Api\V1\Controllers\Hermes\FinanceTransactionController
     * @covers \FireflyIII\Services\Hermes\FinanceActionService
     */
    public function testPreviewEndpointReturnsTokenForCompleteCreate(): void
    {
        $response = $this->post(
            route('api.v1.hermes.finance.transactions.preview'),
            [
                'action'           => 'create',
                'transaction_type' => 'withdrawal',
                'amount'           => '5.00',
                'description'      => 'Coffee ' . $this->suffix,
                'date'             => '2026-06-29',
                'source_account'   => 'api-cgd',
                'actor'            => 'api-me',
                'category'         => 'api-food',
                'currency'         => 'api-eur',
            ],
            ['Authorization' => 'Bearer ' . self::TOKEN]
        );

        $response->assertStatus(200);
        $response->assertJson(['data' => ['action' => 'create', 'can_apply' => true]]);
        $this->assertNotEmpty($response->json('data.preview_token'));
    }

    /**
     * @covers \FireflyIII\Api\V1\Controllers\Hermes\FinanceTransactionController
     */
    public function testSearchEndpointRequiresHermesToken(): void
    {
        $response = $this->post(route('api.v1.hermes.finance.transactions.search'), []);

        $response->assertStatus(401);
    }

    private function account(string $name, string $type): Account
    {
        $accountType = AccountType::where('type', $type)->first();

        return Account::create(
            [
                'user_id'          => $this->user()->id,
                'account_type_id'  => $accountType->id,
                'name'             => $name,
                'active'           => true,
                'virtual_balance'  => '0',
            ]
        );
    }
}
