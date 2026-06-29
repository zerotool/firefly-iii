<?php

declare(strict_types=1);

namespace FireflyIII\Services\Hermes;

use Carbon\Carbon;
use DB;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\Account\AccountTaskerInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinanceReportService
{
    private const BALANCE_ACCOUNT_TYPES = [
        AccountType::ASSET,
        AccountType::CASH,
        AccountType::CREDITCARD,
        AccountType::DEFAULT,
    ];

    /** @var FinanceResolver */
    private $resolver;

    /** @var BudgetRepositoryInterface */
    private $budgets;

    public function __construct(FinanceResolver $resolver, BudgetRepositoryInterface $budgets)
    {
        $this->resolver = $resolver;
        $this->budgets  = $budgets;
    }

    public function report(User $user, array $input): array
    {
        $report   = (string)($input['report'] ?? '');
        $range    = $this->range($input);
        $resolved = $this->resolver->resolve($user, $input)->toArray();
        $errors   = $this->rangeErrors($range);

        if ([] !== $errors) {
            return $this->response($report, $range, $resolved, [], $errors);
        }

        if ('budget_remaining' === $report) {
            return $this->budgetRemaining($user, $input, $range, $resolved);
        }
        if ('period_summary' === $report) {
            return $this->periodSummary($user, $range, $resolved);
        }
        if ('account_balances' === $report) {
            return $this->accountBalances($user, $input, $range, $resolved);
        }
        if ('hotel' === $report) {
            return $this->scopedActivity($user, $input, $range, $resolved, 'hotel');
        }
        if ('actor' === $report) {
            return $this->scopedActivity($user, $input, $range, $resolved, 'actor');
        }
        if ('transactions' === $report) {
            return $this->transactions($user, $input, $range, $resolved);
        }

        return $this->response($report, $range, $resolved, [], ['Unsupported report.']);
    }

    private function budgetRemaining(User $user, array $input, array $range, array $resolved): array
    {
        $budgetCandidate = $this->selected($resolved, 'budget');
        if (null === $budgetCandidate) {
            return $this->response('budget_remaining', $range, $resolved, [], ['Missing or ambiguous budget.']);
        }

        $date = isset($input['date']) && '' !== (string)$input['date'] ? Carbon::parse((string)$input['date']) : $range['end'];
        /** @var Budget|null $budget */
        $budget = Budget::query()->where('user_id', $user->id)->where('id', (int)$budgetCandidate['id'])->first();
        if (null === $budget) {
            return $this->response('budget_remaining', $range, $resolved, [], ['Budget no longer exists.']);
        }

        $query = BudgetLimit::query()
                            ->with('transactionCurrency')
                            ->where('budget_id', $budget->id)
                            ->where('start_date', '<=', $date->format('Y-m-d'))
                            ->where('end_date', '>=', $date->format('Y-m-d'));

        $currencyCandidate = $this->selected($resolved, 'currency');
        if (null !== $currencyCandidate) {
            $query->where('transaction_currency_id', (int)$currencyCandidate['id']);
        }

        $limits = $query->orderBy('start_date')->get();
        if (0 === $limits->count()) {
            return $this->response('budget_remaining', $range, $resolved, [], ['No budget limit covers the requested date.']);
        }
        if ($limits->count() > 1) {
            return $this->response('budget_remaining', $range, $resolved, [], ['Multiple budget limits cover the requested date; specify currency.']);
        }

        /** @var BudgetLimit $limit */
        $limit = $limits->first();
        $this->budgets->setUser($user);
        $spentInfo = $this->budgets->spentInPeriodMc(new Collection([$budget]), new Collection, $limit->start_date, $limit->end_date);
        $spent     = $this->spentForCurrency($spentInfo, (int)$limit->transaction_currency_id);
        $left      = bcadd((string)$limit->amount, $spent);
        $overspent = bccomp($left, '0') < 0 ? bcsub('0', $left) : '0';

        /** @var TransactionCurrency $currency */
        $currency = $limit->transactionCurrency;
        $payload  = [
            'budget'       => ['id' => $budget->id, 'name' => $budget->name],
            'budget_limit' => [
                'id'         => $limit->id,
                'start_date' => $limit->start_date->toDateString(),
                'end_date'   => $limit->end_date->toDateString(),
            ],
            'currency'     => $this->currencyArray($currency),
            'budgeted'     => (string)$limit->amount,
            'spent'        => $spent,
            'left'         => $left,
            'overspent'    => $overspent,
            'formatted'    => [
                'budgeted'  => app('amount')->formatAnything($currency, (string)$limit->amount, false),
                'spent'     => app('amount')->formatAnything($currency, $spent, false),
                'left'      => app('amount')->formatAnything($currency, $left, false),
                'overspent' => app('amount')->formatAnything($currency, $overspent, false),
            ],
        ];

        return $this->response('budget_remaining', $range, $resolved, $payload);
    }

    private function periodSummary(User $user, array $range, array $resolved): array
    {
        $rows = $this->assetTransactionQuery($user, $range)
                     ->whereIn('transaction_types.type', [TransactionType::WITHDRAWAL, TransactionType::DEPOSIT])
                     ->groupBy('transaction_types.type')
                     ->groupBy('transaction_currencies.id')
                     ->groupBy('transaction_currencies.code')
                     ->groupBy('transaction_currencies.symbol')
                     ->groupBy('transaction_currencies.decimal_places')
                     ->get(
                         [
                             'transaction_types.type',
                             'transaction_currencies.id as currency_id',
                             'transaction_currencies.code as currency_code',
                             'transaction_currencies.symbol as currency_symbol',
                             'transaction_currencies.decimal_places as currency_decimal_places',
                             DB::raw('SUM(transactions.amount) as amount'),
                         ]
                     );

        $summary = [];
        foreach ($rows as $row) {
            $currencyId = (int)$row->currency_id;
            if (!isset($summary[$currencyId])) {
                $summary[$currencyId] = $this->emptyCurrencySummary($row);
            }
            if (TransactionType::DEPOSIT === $row->type) {
                $summary[$currencyId]['income'] = bcadd($summary[$currencyId]['income'], (string)$row->amount);
            }
            if (TransactionType::WITHDRAWAL === $row->type) {
                $summary[$currencyId]['expenses'] = bcadd($summary[$currencyId]['expenses'], (string)$row->amount);
            }
            $summary[$currencyId]['net'] = bcadd($summary[$currencyId]['net'], (string)$row->amount);
        }

        $transfers = $this->assetTransactionQuery($user, $range)
                          ->where('transaction_types.type', TransactionType::TRANSFER)
                          ->where('transactions.amount', '<', 0)
                          ->groupBy('transaction_currencies.id')
                          ->get(
                              [
                                  'transaction_currencies.id as currency_id',
                                  DB::raw('SUM(transactions.amount) as amount'),
                              ]
                          );
        foreach ($transfers as $row) {
            $currencyId = (int)$row->currency_id;
            if (!isset($summary[$currencyId])) {
                $currency = TransactionCurrency::find($currencyId);
                $summary[$currencyId] = $this->emptyCurrencySummary((object)[
                    'currency_id'             => $currency->id,
                    'currency_code'           => $currency->code,
                    'currency_symbol'         => $currency->symbol,
                    'currency_decimal_places' => $currency->decimal_places,
                ]);
            }
            $summary[$currencyId]['transfers'] = bcadd($summary[$currencyId]['transfers'], bcsub('0', (string)$row->amount));
        }

        return $this->response('period_summary', $range, $resolved, ['currencies' => array_values($summary)]);
    }

    private function accountBalances(User $user, array $input, array $range, array $resolved): array
    {
        $accounts = $this->selectedAccounts($user, $resolved);
        if ($accounts->count() === 0) {
            $accounts = Account::query()
                               ->with('accountType')
                               ->where('user_id', $user->id)
                               ->where('active', true)
                               ->whereNull('deleted_at')
                               ->whereHas(
                                   'accountType',
                                   function (Builder $query) {
                                       $query->whereIn('type', self::BALANCE_ACCOUNT_TYPES);
                                   }
                               )
                               ->orderBy('name')
                               ->limit($this->limit($input))
                               ->get();
        }

        $date = isset($input['date']) && '' !== (string)$input['date'] ? Carbon::parse((string)$input['date']) : $range['end'];
        $currency = app('amount')->getDefaultCurrencyByUser($user);
        $balances = $accounts->map(
            function (Account $account) use ($date, $currency) {
                $balance = app('steam')->balance($account, $date);

                return [
                    'account'   => $this->accountArray($account),
                    'date'      => $date->toDateString(),
                    'currency'  => $this->currencyArray($currency),
                    'balance'   => $balance,
                    'formatted' => app('amount')->formatAnything($currency, $balance, false),
                ];
            }
        )->all();

        return $this->response('account_balances', $range, $resolved, ['accounts' => $balances]);
    }

    private function scopedActivity(User $user, array $input, array $range, array $resolved, string $key): array
    {
        $selected = $this->selected($resolved, $key);
        if (null === $selected) {
            return $this->response($key, $range, $resolved, [], [sprintf('Missing or ambiguous %s.', $key)]);
        }

        $account = Account::query()->with('accountType')->where('user_id', $user->id)->where('id', (int)$selected['id'])->first();
        if (null === $account) {
            return $this->response($key, $range, $resolved, [], ['Account no longer exists.']);
        }

        $type = strtolower((string)($input['transaction_type'] ?? ''));
        if ('hotel' === $key && \in_array($type, ['deposit', 'withdrawal'], true)) {
            return $this->hotelOpposingReport($user, $range, $resolved, $account, $type);
        }

        $rows = $this->accountActivityRows($user, $account, $range);

        return $this->response($key, $range, $resolved, ['account' => $this->accountArray($account), 'currencies' => $rows]);
    }

    private function hotelOpposingReport(User $user, array $range, array $resolved, Account $hotel, string $type): array
    {
        $accounts = $this->reportBalanceAccounts($user, $resolved);
        if (0 === $accounts->count()) {
            return $this->response('hotel', $range, $resolved, [], ['No balance accounts available for hotel report.']);
        }

        if (!auth()->check() || (int)auth()->id() !== $user->id) {
            auth()->setUser($user);
        }

        /** @var AccountTaskerInterface $tasker */
        $tasker = app(AccountTaskerInterface::class);
        $tasker->setUser($user);
        $rows = 'deposit' === $type
            ? $tasker->getIncomeReport($range['start'], $range['end'], $accounts)
            : $tasker->getExpenseReport($range['start'], $range['end'], $accounts);

        $entries = [];
        foreach ($rows as $row) {
            if ((int)$row['id'] === $hotel->id) {
                $entries[] = $this->reportEntryArray($row, $type);
            }
        }

        $payload = [
            'account'               => $this->accountArray($hotel),
            'direction'             => 'deposit' === $type ? 'income' : 'expenses',
            'report_accounts_count' => $accounts->count(),
            'entries'               => $entries,
            'daily'                 => $this->opposingDailyRows($user, $hotel, $accounts, $range, $type),
            'currencies'            => $this->currencySummariesFromReportEntries($entries, $type),
        ];

        return $this->response('hotel', $range, $resolved, $payload);
    }

    private function transactions(User $user, array $input, array $range, array $resolved): array
    {
        $query = TransactionJournal::query()
                                   ->with(['transactionType', 'transactions.account.accountType', 'transactions.transactionCurrency', 'transactions.budgets', 'transactions.categories', 'notes'])
                                   ->where('transaction_journals.user_id', $user->id)
                                   ->whereNull('transaction_journals.deleted_at')
                                   ->where('transaction_journals.date', '>=', $range['start']->format('Y-m-d 00:00:00'))
                                   ->where('transaction_journals.date', '<=', $range['end']->format('Y-m-d 23:59:59'));

        if (isset($input['description']) && '' !== trim((string)$input['description'])) {
            $query->where('transaction_journals.description', 'like', '%' . $this->like((string)$input['description']) . '%');
        }

        $type = (string)($input['transaction_type'] ?? '');
        if ('' !== $type) {
            $query->whereHas('transactionType', function (Builder $query) use ($type) {
                $query->where('type', $type);
            });
        }

        $accountIds = $this->selectedAccounts($user, $resolved)->pluck('id')->all();
        if ([] !== $accountIds) {
            $query->whereHas('transactions', function (Builder $query) use ($accountIds) {
                $query->whereIn('account_id', $accountIds);
            });
        }

        $journals = $query->orderBy('transaction_journals.date', 'desc')
                          ->orderBy('transaction_journals.id', 'desc')
                          ->limit($this->limit($input))
                          ->get()
                          ->map(
                              function (TransactionJournal $journal) {
                                  return $this->journalSummary($journal);
                              }
                          )->all();

        return $this->response('transactions', $range, $resolved, ['transactions' => $journals]);
    }

    private function range(array $input): array
    {
        if (isset($input['start']) || isset($input['end']) || isset($input['from']) || isset($input['to'])) {
            $start = Carbon::parse((string)($input['start'] ?? $input['from'] ?? Carbon::now()->startOfMonth()->toDateString()))->startOfDay();
            $end   = Carbon::parse((string)($input['end'] ?? $input['to'] ?? Carbon::now()->endOfMonth()->toDateString()))->endOfDay();

            return ['start' => $start, 'end' => $end];
        }

        $period = (string)($input['period'] ?? 'current_month');
        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

            return ['start' => $start, 'end' => $start->copy()->endOfMonth()];
        }
        if ('previous_month' === $period) {
            $start = Carbon::now()->startOfMonth()->subMonth();

            return ['start' => $start, 'end' => $start->copy()->endOfMonth()];
        }

        $start = Carbon::now()->startOfMonth();

        return ['start' => $start, 'end' => $start->copy()->endOfMonth()];
    }

    private function rangeErrors(array $range): array
    {
        if ($range['start']->greaterThan($range['end'])) {
            return ['Start date must be before end date.'];
        }
        if ($range['start']->diffInDays($range['end']) > 370) {
            return ['Date range is too broad; use 370 days or less.'];
        }

        return [];
    }

    private function assetTransactionQuery(User $user, array $range)
    {
        return DB::table('transactions')
                 ->join('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                 ->join('transaction_types', 'transaction_types.id', '=', 'transaction_journals.transaction_type_id')
                 ->join('transaction_currencies', 'transaction_currencies.id', '=', 'transactions.transaction_currency_id')
                 ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
                 ->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
                 ->where('transaction_journals.user_id', $user->id)
                 ->whereNull('transaction_journals.deleted_at')
                 ->whereNull('transactions.deleted_at')
                 ->where('transaction_journals.date', '>=', $range['start']->format('Y-m-d 00:00:00'))
                 ->where('transaction_journals.date', '<=', $range['end']->format('Y-m-d 23:59:59'))
                 ->whereIn('account_types.type', self::BALANCE_ACCOUNT_TYPES);
    }

    private function accountActivityRows(User $user, Account $account, array $range): array
    {
        $rows = DB::table('transactions')
                  ->join('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                  ->join('transaction_types', 'transaction_types.id', '=', 'transaction_journals.transaction_type_id')
                  ->join('transaction_currencies', 'transaction_currencies.id', '=', 'transactions.transaction_currency_id')
                  ->where('transaction_journals.user_id', $user->id)
                  ->where('transactions.account_id', $account->id)
                  ->whereNull('transaction_journals.deleted_at')
                  ->whereNull('transactions.deleted_at')
                  ->where('transaction_journals.date', '>=', $range['start']->format('Y-m-d 00:00:00'))
                  ->where('transaction_journals.date', '<=', $range['end']->format('Y-m-d 23:59:59'))
                  ->groupBy('transaction_types.type')
                  ->groupBy('transaction_currencies.id')
                  ->groupBy('transaction_currencies.code')
                  ->groupBy('transaction_currencies.symbol')
                  ->groupBy('transaction_currencies.decimal_places')
                  ->get(
                      [
                          'transaction_types.type',
                          'transaction_currencies.id as currency_id',
                          'transaction_currencies.code as currency_code',
                          'transaction_currencies.symbol as currency_symbol',
                          'transaction_currencies.decimal_places as currency_decimal_places',
                          DB::raw('SUM(transactions.amount) as amount'),
                      ]
                  );

        $summary = [];
        foreach ($rows as $row) {
            $currencyId = (int)$row->currency_id;
            if (!isset($summary[$currencyId])) {
                $summary[$currencyId] = $this->emptyCurrencySummary($row);
            }
            if (TransactionType::DEPOSIT === $row->type) {
                $summary[$currencyId]['income'] = bcadd($summary[$currencyId]['income'], $this->absolute((string)$row->amount));
            }
            if (TransactionType::WITHDRAWAL === $row->type) {
                $summary[$currencyId]['expenses'] = bcadd($summary[$currencyId]['expenses'], $this->absolute((string)$row->amount));
            }
            if (TransactionType::TRANSFER === $row->type) {
                $summary[$currencyId]['transfers'] = bcadd($summary[$currencyId]['transfers'], $this->absolute((string)$row->amount));
            }
            $summary[$currencyId]['net'] = bcadd($summary[$currencyId]['net'], (string)$row->amount);
        }

        return array_values($summary);
    }

    private function reportBalanceAccounts(User $user, array $resolved): Collection
    {
        $selected = $this->selectedAccounts($user, $resolved)->filter(
            function (Account $account) {
                return \in_array(optional($account->accountType)->type, self::BALANCE_ACCOUNT_TYPES, true);
            }
        )->values();

        if ($selected->count() > 0) {
            return $selected;
        }

        return Account::query()
                      ->with('accountType')
                      ->where('user_id', $user->id)
                      ->where('active', true)
                      ->whereNull('deleted_at')
                      ->whereHas(
                          'accountType',
                          function (Builder $query) {
                              $query->whereIn('type', self::BALANCE_ACCOUNT_TYPES);
                          }
                      )
                      ->get();
    }

    private function reportEntryArray(array $row, string $type): array
    {
        /** @var TransactionCurrency|null $currency */
        $currency = $row['single_currency'] ?? null;
        $sum      = $this->absolute((string)$row['sum']);
        $average  = $this->absolute((string)$row['average']);
        $formatted = [
            'sum'     => $sum,
            'average' => $average,
        ];
        if (null !== $currency) {
            $formatted = [
                'sum'     => app('amount')->formatAnything($currency, $sum, false),
                'average' => app('amount')->formatAnything($currency, $average, false),
            ];
        }

        return [
            'id'        => (int)$row['id'],
            'name'      => (string)$row['name'],
            'original'  => (string)$row['original'],
            'direction' => 'deposit' === $type ? 'income' : 'expenses',
            'sum'       => $sum,
            'average'   => $average,
            'count'     => (int)$row['count'],
            'currency'  => $this->currencyArray($currency),
            'formatted' => $formatted,
        ];
    }

    private function opposingDailyRows(User $user, Account $opposing, Collection $accounts, array $range, string $type): array
    {
        $accountIds = $accounts->pluck('id')->map(
            function ($id) {
                return (int)$id;
            }
        )->all();
        if ([] === $accountIds) {
            return [];
        }

        $transactionTypes = 'deposit' === $type
            ? [TransactionType::DEPOSIT, TransactionType::TRANSFER]
            : [TransactionType::WITHDRAWAL, TransactionType::TRANSFER];
        $amountOperator = 'deposit' === $type ? '>' : '<';

        $rows = DB::table('transactions')
                  ->join('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                  ->join('transaction_types', 'transaction_types.id', '=', 'transaction_journals.transaction_type_id')
                  ->join('transaction_currencies', 'transaction_currencies.id', '=', 'transactions.transaction_currency_id')
                  ->join(
                      'transactions as opposing_transactions',
                      function ($join) use ($opposing) {
                          $join->on('opposing_transactions.transaction_journal_id', '=', 'transactions.transaction_journal_id')
                               ->on('opposing_transactions.identifier', '=', 'transactions.identifier')
                               ->where('opposing_transactions.account_id', '=', $opposing->id)
                               ->whereNull('opposing_transactions.deleted_at');
                      }
                  )
                  ->where('transaction_journals.user_id', $user->id)
                  ->whereNull('transaction_journals.deleted_at')
                  ->whereNull('transactions.deleted_at')
                  ->whereIn('transactions.account_id', $accountIds)
                  ->whereIn('transaction_types.type', $transactionTypes)
                  ->where('transactions.amount', $amountOperator, 0)
                  ->where('transaction_journals.date', '>=', $range['start']->format('Y-m-d 00:00:00'))
                  ->where('transaction_journals.date', '<=', $range['end']->format('Y-m-d 23:59:59'))
                  ->groupBy(DB::raw('DATE(transaction_journals.date)'))
                  ->groupBy('transaction_currencies.id')
                  ->groupBy('transaction_currencies.code')
                  ->groupBy('transaction_currencies.symbol')
                  ->groupBy('transaction_currencies.decimal_places')
                  ->orderBy('date')
                  ->get(
                      [
                          DB::raw('DATE(transaction_journals.date) as date'),
                          'transaction_currencies.id as currency_id',
                          'transaction_currencies.code as currency_code',
                          'transaction_currencies.symbol as currency_symbol',
                          'transaction_currencies.decimal_places as currency_decimal_places',
                          DB::raw('SUM(transactions.amount) as amount'),
                          DB::raw('COUNT(transactions.id) as count'),
                      ]
                  );

        return $rows->map(
            function ($row) use ($type) {
                $amount = 'deposit' === $type ? (string)$row->amount : $this->absolute((string)$row->amount);

                return [
                    'date'      => (string)$row->date,
                    'amount'    => $amount,
                    'count'     => (int)$row->count,
                    'currency'  => [
                        'id'             => (int)$row->currency_id,
                        'code'           => (string)$row->currency_code,
                        'symbol'         => (string)$row->currency_symbol,
                        'decimal_places' => (int)$row->currency_decimal_places,
                    ],
                    'direction' => 'deposit' === $type ? 'income' : 'expenses',
                ];
            }
        )->all();
    }

    private function currencySummariesFromReportEntries(array $entries, string $type): array
    {
        $summary = [];
        foreach ($entries as $entry) {
            $currency = $entry['currency'] ?? [];
            $currencyId = (int)($currency['id'] ?? 0);
            if ($currencyId < 1) {
                continue;
            }
            if (!isset($summary[$currencyId])) {
                $summary[$currencyId] = $this->emptyCurrencySummary(
                    (object)[
                        'currency_id'             => $currencyId,
                        'currency_code'           => (string)($currency['code'] ?? ''),
                        'currency_symbol'         => (string)($currency['symbol'] ?? ''),
                        'currency_decimal_places' => (int)($currency['decimal_places'] ?? 2),
                    ]
                );
            }

            $amount = (string)$entry['sum'];
            if ('deposit' === $type) {
                $summary[$currencyId]['income'] = bcadd($summary[$currencyId]['income'], $amount);
                $summary[$currencyId]['net']    = bcadd($summary[$currencyId]['net'], $amount);
                continue;
            }
            $summary[$currencyId]['expenses'] = bcadd($summary[$currencyId]['expenses'], $amount);
            $summary[$currencyId]['net']      = bcsub($summary[$currencyId]['net'], $amount);
        }

        return array_values($summary);
    }

    private function selectedAccounts(User $user, array $resolved): Collection
    {
        $ids = [];
        foreach (['source_account', 'destination_account', 'actor', 'hotel'] as $key) {
            $selected = $this->selected($resolved, $key);
            if (null !== $selected) {
                $ids[] = (int)$selected['id'];
            }
        }
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return new Collection;
        }

        return Account::query()->with('accountType')->where('user_id', $user->id)->whereIn('id', $ids)->get();
    }

    private function journalSummary(TransactionJournal $journal): array
    {
        $journal->loadMissing(['transactionType', 'transactions.account.accountType', 'transactions.transactionCurrency', 'transactions.budgets', 'transactions.categories', 'notes']);

        return [
            'journal_id'       => $journal->id,
            'transaction_type' => optional($journal->transactionType)->type,
            'date'             => $journal->date->toDateString(),
            'description'      => $journal->description,
            'transactions'     => $journal->transactions->map(
                function ($transaction) {
                    return [
                        'transaction_id' => $transaction->id,
                        'account'        => $this->accountArray($transaction->account),
                        'amount'         => (string)$transaction->amount,
                        'currency'       => $this->currencyArray($transaction->transactionCurrency),
                    ];
                }
            )->all(),
        ];
    }

    private function selected(array $resolved, string $key): ?array
    {
        if (!isset($resolved['entities'][$key]['selected']) || null === $resolved['entities'][$key]['selected']) {
            return null;
        }

        return (array)$resolved['entities'][$key]['selected'];
    }

    private function spentForCurrency(array $spentInfo, int $currencyId): string
    {
        foreach ($spentInfo as $entry) {
            if ((int)$entry['currency_id'] === $currencyId) {
                return (string)$entry['amount'];
            }
        }

        return '0';
    }

    private function emptyCurrencySummary($row): array
    {
        return [
            'currency'  => [
                'id'             => (int)$row->currency_id,
                'code'           => (string)$row->currency_code,
                'symbol'         => (string)$row->currency_symbol,
                'decimal_places' => (int)$row->currency_decimal_places,
            ],
            'income'    => '0',
            'expenses'  => '0',
            'transfers' => '0',
            'net'       => '0',
        ];
    }

    private function accountArray(Account $account): array
    {
        return [
            'id'           => $account->id,
            'name'         => $account->name,
            'account_type' => optional($account->accountType)->type,
            'kassa_id'     => $account->kassa_id,
        ];
    }

    private function currencyArray(?TransactionCurrency $currency): array
    {
        if (null === $currency) {
            return [];
        }

        return [
            'id'             => $currency->id,
            'code'           => $currency->code,
            'symbol'         => $currency->symbol,
            'decimal_places' => $currency->decimal_places,
        ];
    }

    private function response(string $report, array $range, array $resolved, array $payload, array $errors = []): array
    {
        return [
            'report'   => $report,
            'range'    => [
                'start' => $range['start']->toDateString(),
                'end'   => $range['end']->toDateString(),
            ],
            'resolved' => $resolved,
            'payload'  => $payload,
            'errors'   => $errors,
        ];
    }

    private function absolute(string $amount): string
    {
        return bccomp($amount, '0') < 0 ? bcsub('0', $amount) : $amount;
    }

    private function limit(array $input): int
    {
        $limit = (int)($input['limit'] ?? 25);

        return max(1, min(50, $limit));
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
