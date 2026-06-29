<?php

declare(strict_types=1);

namespace FireflyIII\Services\Hermes;

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinanceResolver
{
    private const SOURCE_ACCOUNT_TYPES = [
        AccountType::ASSET,
        AccountType::CASH,
        AccountType::CREDITCARD,
    ];

    public function resolve(User $user, array $input): ResolvedFinanceIntent
    {
        $intent = new ResolvedFinanceIntent($input);
        $date   = $this->resolveDate($input['date'] ?? null);

        $this->resolveAccountEntity($intent, $user, $input, 'source_account', 'asset_accounts', self::SOURCE_ACCOUNT_TYPES);
        $this->resolveAccountEntity($intent, $user, $input, 'actor', 'actors', $this->actorTypes($input));
        $this->resolveAccountEntity($intent, $user, $input, 'destination_account', $this->destinationAliasGroup($input), $this->destinationTypes($input));
        $this->resolveAccountEntity($intent, $user, $input, 'hotel', 'hotels', [AccountType::ASSET, AccountType::CASH, AccountType::EXPENSE, AccountType::REVENUE]);

        $this->resolveCategory($intent, $user, $input);
        $budgetCandidates = $this->resolveBudget($intent, $user, $input);
        $currencyCandidates = $this->resolveCurrency($intent, $input);
        $this->resolveBudgetLimit($intent, $budgetCandidates, $currencyCandidates, $date);

        return $intent;
    }

    private function actorTypes(array $input): array
    {
        $type = strtolower((string)($input['transaction_type'] ?? 'withdrawal'));
        if ('deposit' === $type) {
            return [AccountType::REVENUE];
        }

        if ('transfer' === $type) {
            return self::SOURCE_ACCOUNT_TYPES;
        }

        return [AccountType::EXPENSE];
    }

    private function destinationTypes(array $input): array
    {
        $type = strtolower((string)($input['transaction_type'] ?? 'withdrawal'));
        if ('deposit' === $type || 'transfer' === $type) {
            return self::SOURCE_ACCOUNT_TYPES;
        }

        return [AccountType::EXPENSE];
    }

    private function destinationAliasGroup(array $input): string
    {
        $type = strtolower((string)($input['transaction_type'] ?? 'withdrawal'));
        if ('deposit' === $type || 'transfer' === $type) {
            return 'asset_accounts';
        }

        return 'actors';
    }

    private function resolveAccountEntity(ResolvedFinanceIntent $intent, User $user, array $input, string $key, string $aliasGroup, array $types): void
    {
        $phrase = $this->phrase($input, $key);
        if ('' === $phrase) {
            return;
        }

        $query = Account::query()
                        ->with('accountType')
                        ->where('user_id', $user->id)
                        ->whereHas(
                            'accountType',
                            function (Builder $query) use ($types) {
                                $query->whereIn('type', $types);
                            }
                        );

        $accounts = $this->accountCandidates($query, $phrase, $aliasGroup);
        $intent->addEntity($key, $accounts);
    }

    private function resolveCategory(ResolvedFinanceIntent $intent, User $user, array $input): void
    {
        $phrase = $this->phrase($input, 'category');
        if ('' === $phrase) {
            return;
        }

        $query = Category::query()->where('user_id', $user->id);
        $intent->addEntity('category', $this->modelCandidates($query, $phrase, 'categories', 'category'));
    }

    private function resolveBudget(ResolvedFinanceIntent $intent, User $user, array $input): array
    {
        $phrase = $this->phrase($input, 'budget');
        if ('' === $phrase) {
            return [];
        }

        $query = Budget::query()->where('user_id', $user->id)->where('active', true);
        $candidates = $this->modelCandidates($query, $phrase, 'budgets', 'budget');
        $intent->addEntity('budget', $candidates);

        return $candidates;
    }

    private function resolveCurrency(ResolvedFinanceIntent $intent, array $input): array
    {
        $phrase = $this->phrase($input, 'currency');
        if ('' === $phrase) {
            return [];
        }

        $query = TransactionCurrency::query()->where('enabled', true);
        $candidates = $this->currencyCandidates($query, $phrase);
        $intent->addEntity('currency', $candidates);

        return $candidates;
    }

    private function resolveBudgetLimit(ResolvedFinanceIntent $intent, array $budgetCandidates, array $currencyCandidates, Carbon $date): void
    {
        if (1 !== \count($budgetCandidates)) {
            return;
        }

        $query = BudgetLimit::query()
                            ->with('transactionCurrency')
                            ->where('budget_id', $budgetCandidates[0]['id'])
                            ->where('start_date', '<=', $date->format('Y-m-d'))
                            ->where('end_date', '>=', $date->format('Y-m-d'));

        if (1 === \count($currencyCandidates)) {
            $query->where('transaction_currency_id', $currencyCandidates[0]['id']);
        }

        $limits = $query->orderBy('start_date')->get()->map(
            function (BudgetLimit $limit) {
                return [
                    'type'        => 'budget_limit',
                    'id'          => $limit->id,
                    'budget_id'   => $limit->budget_id,
                    'amount'      => $limit->amount,
                    'start_date'  => $limit->start_date->format('Y-m-d'),
                    'end_date'    => $limit->end_date->format('Y-m-d'),
                    'currency_id' => $limit->transaction_currency_id,
                    'currency'    => optional($limit->transactionCurrency)->code,
                    'confidence'  => 100,
                    'source'      => 'date_range',
                ];
            }
        )->all();

        $intent->addEntity('budget_limit', $limits);
    }

    private function accountCandidates(Builder $query, string $phrase, string $aliasGroup): array
    {
        return $this->rankedCandidates(
            $query,
            $phrase,
            $aliasGroup,
            function (Account $account, int $confidence, string $source) {
                return [
                    'type'         => 'account',
                    'id'           => $account->id,
                    'name'         => $account->name,
                    'account_type' => optional($account->accountType)->type,
                    'kassa_id'     => $account->kassa_id,
                    'confidence'   => $confidence,
                    'source'       => $source,
                ];
            }
        );
    }

    private function modelCandidates(Builder $query, string $phrase, string $aliasGroup, string $type): array
    {
        return $this->rankedCandidates(
            $query,
            $phrase,
            $aliasGroup,
            function ($model, int $confidence, string $source) use ($type) {
                return [
                    'type'       => $type,
                    'id'         => $model->id,
                    'name'       => $model->name,
                    'confidence' => $confidence,
                    'source'     => $source,
                ];
            }
        );
    }

    private function currencyCandidates(Builder $query, string $phrase): array
    {
        $normalized = $this->normalize($phrase);
        $aliases    = $this->aliasesFor('currencies', $normalized);
        $values     = array_unique(array_merge([$phrase], $aliases));
        $found      = new Collection;

        foreach ($values as $value) {
            $found = $found->merge(
                (clone $query)->where(
                    function (Builder $query) use ($value) {
                        $query->where('code', strtoupper($value))
                              ->orWhere('name', $value)
                              ->orWhere('symbol', $value);
                    }
                )->get()->map(
                    function (TransactionCurrency $currency) {
                        return [
                            'type'       => 'currency',
                            'id'         => $currency->id,
                            'name'       => $currency->name,
                            'code'       => $currency->code,
                            'symbol'     => $currency->symbol,
                            'confidence' => 100,
                            'source'     => 'alias_or_exact',
                        ];
                    }
                )
            );
        }

        return $this->uniqueCandidates($found->all());
    }

    private function rankedCandidates(Builder $baseQuery, string $phrase, string $aliasGroup, callable $serialize): array
    {
        $normalized = $this->normalize($phrase);
        $values     = array_unique(array_merge([$phrase], $this->aliasesFor($aliasGroup, $normalized)));
        $found      = new Collection;

        foreach ($values as $value) {
            $found = $found->merge(
                (clone $baseQuery)->where('name', $value)->get()->map(
                    function ($model) use ($serialize) {
                        return $serialize($model, 100, 'alias_or_exact');
                    }
                )
            );
        }

        $found = $found->merge(
            (clone $baseQuery)->get()->filter(
                function ($model) use ($normalized) {
                    return $this->normalize($model->name) === $normalized
                        || (isset($model->kassa_id) && '' !== (string)$model->kassa_id && $this->normalize((string)$model->kassa_id) === $normalized);
                }
            )->map(
                function ($model) use ($serialize) {
                    return $serialize($model, 95, 'exact');
                }
            )
        );

        if ($found->count() > 0) {
            return $this->uniqueCandidates($found->sortByDesc('confidence')->all());
        }

        if (mb_strlen($phrase) >= 3) {
            $found = $found->merge(
                (clone $baseQuery)->where('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $phrase) . '%')->limit(10)->get()->map(
                    function ($model) use ($serialize) {
                        return $serialize($model, 75, 'contains');
                    }
                )
            );
        }

        return $this->uniqueCandidates($found->sortByDesc('confidence')->all());
    }

    private function aliasesFor(string $group, string $normalizedPhrase): array
    {
        $aliases = [];
        foreach ((array)config('hermes_finance_aliases.' . $group, []) as $entry) {
            $entryAliases = array_merge([$entry['name'] ?? ''], (array)($entry['aliases'] ?? []), (array)($entry['kassa_ids'] ?? []));
            foreach ($entryAliases as $alias) {
                if ($this->normalize((string)$alias) === $normalizedPhrase) {
                    $aliases[] = (string)($entry['name'] ?? $alias);
                    foreach ((array)($entry['kassa_ids'] ?? []) as $kassaId) {
                        $aliases[] = (string)$kassaId;
                    }
                }
            }
        }

        return $aliases;
    }

    private function uniqueCandidates(array $candidates): array
    {
        $seen   = [];
        $result = [];
        foreach ($candidates as $candidate) {
            $key = $candidate['type'] . ':' . $candidate['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[]   = $candidate;
        }

        return array_slice($result, 0, 10);
    }

    private function phrase(array $input, string $key): string
    {
        if (isset($input[$key])) {
            return trim((string)$input[$key]);
        }

        if (isset($input['phrases'][$key])) {
            return trim((string)$input['phrases'][$key]);
        }

        return '';
    }

    private function resolveDate($date): Carbon
    {
        if (null === $date || '' === (string)$date) {
            return Carbon::today();
        }

        return Carbon::parse((string)$date);
    }

    private function normalize(string $value): string
    {
        $value = str_replace('ё', 'е', mb_strtolower($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string)preg_replace('/\s+/u', ' ', (string)$value));
    }
}
