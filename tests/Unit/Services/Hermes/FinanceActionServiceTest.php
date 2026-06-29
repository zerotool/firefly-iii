<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Hermes;

use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\HermesFinanceAudit;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Journal\JournalRepository;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\Services\Hermes\FinanceActionService;
use FireflyIII\User;
use Log;
use Tests\TestCase;

class FinanceActionServiceTest extends TestCase
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

    /** @var string */
    private $suffix;

    public function setUp(): void
    {
        parent::setUp();
        Log::info(sprintf('Now in %s.', \get_class($this)));

        $this->app->instance(JournalRepositoryInterface::class, new JournalRepository);

        $this->user         = $this->user();
        $this->eur          = TransactionCurrency::where('code', 'EUR')->first();
        $this->suffix       = 'Hermes action ' . random_int(100000, 999999);
        $this->asset        = $this->account('Стас / CGD ' . $this->suffix, AccountType::ASSET);
        $this->expenseActor = $this->account('Стас ' . $this->suffix, AccountType::EXPENSE);
        $this->revenueActor = $this->account('Работа ' . $this->suffix, AccountType::REVENUE);
        $this->category     = Category::create(['user_id' => $this->user->id, 'name' => 'Еда ' . $this->suffix]);
        $this->budget       = Budget::create(['user_id' => $this->user->id, 'name' => 'Португалия ' . $this->suffix, 'active' => true, 'order' => 1]);

        config()->set('hermes_finance.preview_ttl_minutes', 15);
        config()->set('hermes_finance_aliases.asset_accounts', [['name' => $this->asset->name, 'aliases' => ['cgd-action']]]);
        config()->set('hermes_finance_aliases.actors', [['name' => $this->expenseActor->name, 'aliases' => ['я-action']]]);
        config()->set('hermes_finance_aliases.categories', [['name' => $this->category->name, 'aliases' => ['еда-action']]]);
        config()->set('hermes_finance_aliases.budgets', [['name' => $this->budget->name, 'aliases' => ['португалия-action']]]);
        config()->set('hermes_finance_aliases.currencies', [['name' => 'EUR', 'aliases' => ['евро-action']]]);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceActionService
     * @covers \FireflyIII\Services\Hermes\FinanceTransactionPreview
     */
    public function testPreviewCreateBuildsFactorySafePayload(): void
    {
        $preview = $this->service()->preview($this->user, $this->createInput())->toArray();

        $this->assertTrue($preview['can_apply']);
        $this->assertSame('create', $preview['action']);
        $this->assertNotEmpty($preview['preview_token']);
        $this->assertSame($this->asset->id, $preview['payload']['transactions'][0]['source_id']);
        $this->assertSame($this->expenseActor->id, $preview['payload']['transactions'][0]['destination_id']);
        $this->assertSame($this->category->id, $preview['payload']['transactions'][0]['category_id']);
        $this->assertSame($this->budget->id, $preview['payload']['transactions'][0]['budget_id']);
        $this->assertSame($this->eur->id, $preview['payload']['transactions'][0]['currency_id']);
        $this->assertArrayHasKey('foreign_currency_code', $preview['payload']['transactions'][0]);
        $this->assertArrayHasKey('original-source', $preview['payload']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceActionService
     */
    public function testApplyCreateStoresJournalAndReplaysIdempotently(): void
    {
        $preview = $this->service()->preview($this->user, $this->createInput())->toArray();

        $result = $this->service()->apply(
            $this->user,
            [
                'preview_token'   => $preview['preview_token'],
                'idempotency_key' => 'create-' . $this->suffix,
                'source'          => 'phpunit',
            ]
        );

        $this->assertTrue($result['applied']);
        $journal = TransactionJournal::find($result['journal_ids'][0]);
        $this->assertNotNull($journal);
        $this->assertSame(2, $journal->transactions()->count());
        $this->assertSame(1, HermesFinanceAudit::where('mode', 'apply')->where('status', 'applied')->where('idempotency_key', 'create-' . $this->suffix)->count());

        $replay = $this->service()->apply(
            $this->user,
            [
                'preview_token'   => $preview['preview_token'],
                'idempotency_key' => 'create-' . $this->suffix,
                'source'          => 'phpunit',
            ]
        );

        $this->assertTrue($replay['applied']);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame($result['journal_ids'], $replay['journal_ids']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceActionService
     */
    public function testPreviewDepositUsesRevenueSourceAndAssetDestination(): void
    {
        config()->set('hermes_finance_aliases.actors', [['name' => $this->revenueActor->name, 'aliases' => ['работа-action']]]);

        $preview = $this->service()->preview(
            $this->user,
            [
                'action'              => 'create',
                'transaction_type'    => 'deposit',
                'amount'              => '123.45',
                'description'         => 'Salary ' . $this->suffix,
                'date'                => '2026-06-29',
                'actor'               => 'работа-action',
                'destination_account' => 'cgd-action',
                'currency'            => 'евро-action',
                'source'              => 'phpunit',
            ]
        )->toArray();

        $this->assertTrue($preview['can_apply']);
        $this->assertSame($this->revenueActor->id, $preview['payload']['transactions'][0]['source_id']);
        $this->assertSame($this->asset->id, $preview['payload']['transactions'][0]['destination_id']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceActionService
     */
    public function testUpdatePreviewRejectsPartialExplicitSplitPayload(): void
    {
        $journal = $this->createJournal();

        $preview = $this->service()->preview(
            $this->user,
            [
                'action'       => 'update',
                'journal_id'   => $journal->id,
                'transactions' => [],
            ]
        )->toArray();

        $this->assertFalse($preview['can_apply']);
        $this->assertContains('Update payload must include every existing split identifier.', $preview['errors']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceActionService
     */
    public function testDeletePreviewRequiresDisambiguationForMultipleCandidates(): void
    {
        $this->createJournal('Lunch first');
        $this->createJournal('Lunch second');

        $preview = $this->service()->preview(
            $this->user,
            [
                'action'           => 'delete',
                'transaction_type' => 'withdrawal',
                'category'         => 'еда-action',
                'from'             => '2026-06-01',
                'to'               => '2026-06-30',
            ]
        )->toArray();

        $this->assertFalse($preview['can_apply']);
        $this->assertGreaterThanOrEqual(2, \count($preview['candidates']));
        $this->assertContains('Multiple matching transactions found; choose a journal_id explicitly.', $preview['errors']);
    }

    /**
     * @covers \FireflyIII\Services\Hermes\FinanceActionService
     */
    public function testDeleteApplyUsesPreviewTokenAndRepositoryDestroy(): void
    {
        $journal = $this->createJournal('Delete me');

        $preview = $this->service()->preview(
            $this->user,
            [
                'action'     => 'delete',
                'journal_id' => $journal->id,
            ]
        )->toArray();

        $result = $this->service()->apply(
            $this->user,
            [
                'preview_token'   => $preview['preview_token'],
                'idempotency_key' => 'delete-' . $this->suffix,
                'source'          => 'phpunit',
            ]
        );

        $this->assertTrue($result['applied']);
        $this->assertNull(TransactionJournal::find($journal->id));
    }

    private function service(): FinanceActionService
    {
        return app(FinanceActionService::class);
    }

    private function createInput(string $description = 'Lunch'): array
    {
        return [
            'action'           => 'create',
            'transaction_type' => 'withdrawal',
            'amount'           => '12.34',
            'description'      => $description . ' ' . $this->suffix,
            'date'             => '2026-06-29',
            'source_account'   => 'cgd-action',
            'actor'            => 'я-action',
            'category'         => 'еда-action',
            'budget'           => 'португалия-action',
            'currency'         => 'евро-action',
            'notes'            => 'Created by Hermes test.',
            'source'           => 'phpunit',
        ];
    }

    private function createJournal(string $description = 'Lunch'): TransactionJournal
    {
        $preview = $this->service()->preview($this->user, $this->createInput($description))->toArray();
        $result  = $this->service()->apply(
            $this->user,
            [
                'preview_token'   => $preview['preview_token'],
                'idempotency_key' => 'seed-' . $description . '-' . $this->suffix,
                'source'          => 'phpunit',
            ]
        );

        return TransactionJournal::find($result['journal_ids'][0]);
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
}
