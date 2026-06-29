<?php

declare(strict_types=1);

namespace FireflyIII\Services\Hermes;

use Carbon\Carbon;
use Exception;
use FireflyIII\Events\StoredTransactionJournal;
use FireflyIII\Events\UpdatedTransactionJournal;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\HermesFinanceAudit;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FinanceActionService
{
    /** @var FinanceResolver */
    private $resolver;

    /** @var JournalRepositoryInterface */
    private $journals;

    public function __construct(FinanceResolver $resolver, JournalRepositoryInterface $journals)
    {
        $this->resolver = $resolver;
        $this->journals = $journals;
    }

    public function preview(User $user, array $input): FinanceTransactionPreview
    {
        $action         = (string)($input['action'] ?? 'create');
        $source         = $this->source($input);
        $sourceMetadata = $this->sourceMetadata($input, $source);

        try {
            if ('create' === $action) {
                $preview = $this->previewCreate($user, $input);
            } elseif ('update' === $action) {
                $preview = $this->previewUpdate($user, $input);
            } elseif ('delete' === $action) {
                $preview = $this->previewDelete($user, $input);
            } else {
                $preview = new FinanceTransactionPreview($action, false, [], [], ['Unsupported action.']);
            }
        } catch (Exception $e) {
            $preview = new FinanceTransactionPreview($action, false, [], [], [$e->getMessage()]);
        }

        $preview = $preview->withSourceMetadata($sourceMetadata, $this->requiresConfirmation($input));
        $audit = $this->auditPreview($user, $input, $preview, $source);

        if (!$preview->canApply()) {
            return $preview;
        }

        $token     = $this->previewToken();
        $expiresAt = $this->previewExpiresAt();
        $audit->preview_token_hash = $this->tokenHash($token);
        $audit->status             = 'previewed';
        $audit->save();

        $data = $preview->toArray();

        return new FinanceTransactionPreview(
            $data['action'],
            $data['can_apply'],
            $data['payload'],
            $data['resolved'],
            $data['errors'],
            $data['candidates'],
            $token,
            $expiresAt,
            $data['source_metadata'],
            $data['requires_confirmation']
        );
    }

    public function apply(User $user, array $input): array
    {
        $source = $this->source($input);
        $inputMetadata = $this->sourceMetadata($input, $source);

        $existing = HermesFinanceAudit::query()
                                      ->where('source', $source)
                                      ->where('idempotency_key', (string)$input['idempotency_key'])
                                      ->where('mode', 'apply')
                                      ->where('status', 'applied')
                                      ->latest('id')
                                      ->first();
        if (null !== $existing) {
            $result = (array)$existing->result_payload;
            $result['idempotent_replay'] = true;

            return $result;
        }

        /** @var HermesFinanceAudit|null $preview */
        $preview = HermesFinanceAudit::query()
                                     ->where('user_id', $user->id)
                                     ->where('preview_token_hash', $this->tokenHash((string)$input['preview_token']))
                                     ->where('mode', 'preview')
                                     ->where('status', 'previewed')
                                     ->where('created_at', '>=', $this->previewValidAfter()->format('Y-m-d H:i:s'))
                                     ->latest('id')
                                     ->first();
        if (null === $preview) {
            return [
                'applied' => false,
                'errors'  => ['Preview token is invalid, expired, or already used.'],
            ];
        }

        try {
            return DB::transaction(
                function () use ($user, $input, $preview, $source) {
                    $payload = (array)$preview->resolved_payload;
                    $action  = (string)$preview->action;
                    $sourceMetadata = $this->previewSourceMetadata($preview, $input, $source);

                    $this->assertPreviewStillValid($user, $payload);

                    if ('create' === $action) {
                        $result = $this->applyCreate($user, $payload);
                    } elseif ('update' === $action) {
                        $result = $this->applyUpdate($user, $payload);
                    } elseif ('delete' === $action) {
                        $result = $this->applyDelete($user, $payload);
                    } else {
                        throw new FireflyException('Unsupported preview action.');
                    }

                    HermesFinanceAudit::create(
                        [
                            'user_id'          => $user->id,
                            'source'           => $source,
                            'source_type'      => $sourceMetadata['source_type'] ?? '',
                            'source_id'        => $sourceMetadata['source_id'] ?? '',
                            'source_hash'      => $sourceMetadata['source_hash'] ?? '',
                            'idempotency_key'  => (string)$input['idempotency_key'],
                            'action'           => $action,
                            'mode'             => 'apply',
                            'status'           => 'applied',
                            'request_payload'  => $input,
                            'resolved_payload' => $payload,
                            'result_payload'   => $result,
                            'journal_ids'      => $result['journal_ids'] ?? [],
                        ]
                    );

                    $preview->status = 'applied';
                    $preview->save();

                    return $result;
                }
            );
        } catch (Exception $e) {
            $sourceMetadata = $this->previewSourceMetadata($preview, $input, $source);
            HermesFinanceAudit::create(
                [
                    'user_id'          => $user->id,
                    'source'           => $source,
                    'source_type'      => $sourceMetadata['source_type'] ?? $inputMetadata['source_type'] ?? '',
                    'source_id'        => $sourceMetadata['source_id'] ?? $inputMetadata['source_id'] ?? '',
                    'source_hash'      => $sourceMetadata['source_hash'] ?? $inputMetadata['source_hash'] ?? '',
                    'action'           => (string)$preview->action,
                    'mode'             => 'apply',
                    'status'           => 'failed',
                    'request_payload'  => $input,
                    'resolved_payload' => (array)$preview->resolved_payload,
                    'error_message'    => $e->getMessage(),
                ]
            );

            return [
                'applied' => false,
                'errors'  => [$e->getMessage()],
            ];
        }
    }

    public function search(User $user, array $input): array
    {
        $resolved   = $this->resolver->resolve($user, $input)->toArray();
        $candidates = $this->searchJournals($user, $input, $resolved);

        return [
            'data' => [
                'candidates' => $candidates,
                'resolved'   => $resolved,
                'limit'      => $this->limit($input),
            ],
        ];
    }

    private function previewCreate(User $user, array $input): FinanceTransactionPreview
    {
        $resolved = $this->resolver->resolve($user, $input)->toArray();
        $errors   = $this->resolutionErrors($resolved);
        $errors   = array_merge($errors, $this->sourceSpecificErrors($input, 'create'));
        $type     = $this->transactionType($input);

        foreach (['amount', 'description'] as $field) {
            if (!isset($input[$field]) || '' === trim((string)$input[$field])) {
                $errors[] = sprintf('Missing required field: %s.', $field);
            }
        }

        $source = $this->sourceAccountForType($resolved, $type);
        $dest   = $this->destinationAccountForType($resolved, $type);

        if (null === $source) {
            $errors[] = 'Missing or ambiguous source account.';
        }
        if (null === $dest) {
            $errors[] = 'Missing or ambiguous counterparty account.';
        }

        $currency = $this->currency($user, $resolved);
        if (null === $currency) {
            $errors[] = 'Missing or ambiguous currency.';
        }

        $payload = [];
        if ([] === $errors) {
            $payload = $this->transactionPayload(
                $user,
                $type,
                (string)$input['description'],
                $this->date($input),
                [
                    $this->splitPayload(
                        (string)$input['amount'],
                        $currency,
                        $source,
                        $dest,
                        $this->selected($resolved, 'category'),
                        $this->selected($resolved, 'budget'),
                        (string)($input['description'] ?? ''),
                        0
                    ),
                ],
                (string)($input['notes'] ?? ''),
                (string)($input['source_id'] ?? '')
            );
        }

        return new FinanceTransactionPreview('create', [] === $errors, $payload, $resolved, $errors);
    }

    private function previewUpdate(User $user, array $input): FinanceTransactionPreview
    {
        $journal = $this->journal($user, (int)($input['journal_id'] ?? 0));
        if (null === $journal) {
            return new FinanceTransactionPreview('update', false, [], [], ['Missing or invalid journal_id.']);
        }

        $explicitTransactions = isset($input['transactions']) && \is_array($input['transactions']);
        if ($explicitTransactions && !$this->explicitTransactionsCoverExistingSplits($journal, (array)$input['transactions'])) {
            return new FinanceTransactionPreview('update', false, [], [], ['Update payload must include every existing split identifier.']);
        }

        $type     = $journal->transactionType->type;
        $resolved = $this->resolver->resolve($user, array_merge(['transaction_type' => $type], $input))->toArray();
        $errors   = $this->resolutionErrors($resolved);
        $payload  = $this->payloadFromJournal($journal);

        if (isset($input['description']) && '' !== trim((string)$input['description'])) {
            $payload['description'] = (string)$input['description'];
            foreach ($payload['transactions'] as $index => $transaction) {
                $payload['transactions'][$index]['description'] = (string)$input['description'];
            }
        }
        if (isset($input['date']) && '' !== (string)$input['date']) {
            $payload['date'] = $this->date($input);
        }

        foreach ($payload['transactions'] as $index => $transaction) {
            if (isset($input['amount'])) {
                $payload['transactions'][$index]['amount'] = (string)$input['amount'];
            }
            if (null !== $this->selected($resolved, 'category')) {
                $payload['transactions'][$index]['category_id'] = (int)$this->selected($resolved, 'category')['id'];
            }
            if (null !== $this->selected($resolved, 'budget')) {
                $payload['transactions'][$index]['budget_id'] = (int)$this->selected($resolved, 'budget')['id'];
            }
            if (null !== $this->currency($user, $resolved, false)) {
                $currency = $this->currency($user, $resolved, false);
                $payload['transactions'][$index]['currency_id']   = $currency->id;
                $payload['transactions'][$index]['currency_code'] = $currency->code;
            }
        }

        $payload['_preview_state'] = $this->journalState($journal);

        return new FinanceTransactionPreview('update', [] === $errors, $payload, $resolved, $errors);
    }

    private function previewDelete(User $user, array $input): FinanceTransactionPreview
    {
        $resolved = $this->resolver->resolve($user, $input)->toArray();
        $errors   = $this->resolutionErrors($resolved);

        if (isset($input['journal_id'])) {
            $journal = $this->journal($user, (int)$input['journal_id']);
            if (null === $journal) {
                return new FinanceTransactionPreview('delete', false, [], $resolved, ['Missing or invalid journal_id.']);
            }
            $payload = [
                'journal_id'      => $journal->id,
                '_preview_state'  => $this->journalState($journal),
            ];

            return new FinanceTransactionPreview('delete', [] === $errors, $payload, $resolved, $errors, [$this->journalSummary($journal)]);
        }

        $candidates = $this->searchJournals($user, $input, $resolved);
        if ([] === $candidates) {
            $errors[] = 'No matching transactions found.';
        }
        if (\count($candidates) > 1) {
            $errors[] = 'Multiple matching transactions found; choose a journal_id explicitly.';
        }

        $payload = [];
        if (1 === \count($candidates)) {
            $journal = $this->journal($user, (int)$candidates[0]['journal_id']);
            $payload = [
                'journal_id'     => $journal->id,
                '_preview_state' => $this->journalState($journal),
            ];
        }

        return new FinanceTransactionPreview('delete', [] === $errors, $payload, $resolved, $errors, $candidates);
    }

    private function applyCreate(User $user, array $payload): array
    {
        $this->journals->setUser($user);
        $journal = $this->journals->store($this->normalizePayloadDates($payload));
        event(new StoredTransactionJournal($journal));

        return [
            'applied'     => true,
            'action'      => 'create',
            'journal_ids' => [$journal->id],
            'journal'     => $this->journalSummary($journal->fresh()),
        ];
    }

    private function applyUpdate(User $user, array $payload): array
    {
        $journal = $this->journal($user, (int)($payload['_preview_state']['journal_id'] ?? 0));
        if (null === $journal) {
            throw new FireflyException('Journal no longer exists.');
        }

        unset($payload['_preview_state']);
        $this->journals->setUser($user);
        $journal = $this->journals->update($journal, $this->normalizePayloadDates($payload));
        event(new UpdatedTransactionJournal($journal));

        return [
            'applied'     => true,
            'action'      => 'update',
            'journal_ids' => [$journal->id],
            'journal'     => $this->journalSummary($journal->fresh()),
        ];
    }

    private function applyDelete(User $user, array $payload): array
    {
        $journal = $this->journal($user, (int)($payload['journal_id'] ?? 0));
        if (null === $journal) {
            throw new FireflyException('Journal no longer exists.');
        }

        $summary = $this->journalSummary($journal);
        $this->journals->setUser($user);
        $this->journals->destroy($journal);

        return [
            'applied'         => true,
            'action'          => 'delete',
            'journal_ids'     => [$summary['journal_id']],
            'deleted_journal' => $summary,
        ];
    }

    private function transactionPayload(User $user, string $type, string $description, Carbon $date, array $transactions, string $notes, string $sourceId): array
    {
        return [
            'type'               => $type,
            'date'               => $date,
            'description'        => $description,
            'piggy_bank_id'      => 0,
            'piggy_bank_name'    => '',
            'bill_id'            => 0,
            'bill_name'          => '',
            'tags'               => ['hermes-finance'],
            'notes'              => $notes,
            'sepa-cc'            => '',
            'sepa-ct-op'         => '',
            'sepa-ct-id'         => '',
            'sepa-db'            => '',
            'sepa-country'       => '',
            'sepa-ep'            => '',
            'sepa-ci'            => '',
            'sepa-batch-id'      => '',
            'interest_date'      => null,
            'book_date'          => null,
            'process_date'       => null,
            'due_date'           => null,
            'payment_date'       => null,
            'invoice_date'       => null,
            'internal_reference' => '',
            'bunq_payment_id'    => '',
            'external_id'        => '',
            'original-source'    => sprintf('hermes-finance|user:%d|source:%s', $user->id, $sourceId),
            'transactions'       => $transactions,
            'user'               => $user->id,
        ];
    }

    private function splitPayload(string $amount, TransactionCurrency $currency, array $source, array $destination, ?array $category, ?array $budget, string $description, int $identifier): array
    {
        return [
            'amount'                => $amount,
            'description'           => '' === $description ? null : $description,
            'currency_id'           => $currency->id,
            'currency_code'         => $currency->code,
            'foreign_amount'        => null,
            'foreign_currency_id'   => null,
            'foreign_currency_code' => null,
            'budget_id'             => null === $budget ? null : (int)$budget['id'],
            'budget_name'           => null,
            'category_id'           => null === $category ? null : (int)$category['id'],
            'category_name'         => null,
            'source_id'             => (int)$source['id'],
            'source_name'           => null,
            'destination_id'        => (int)$destination['id'],
            'destination_name'      => null,
            'reconciled'            => false,
            'identifier'            => $identifier,
        ];
    }

    private function payloadFromJournal(TransactionJournal $journal): array
    {
        $transactions = [];
        $groups       = $journal->transactions()->with(['account.accountType', 'transactionCurrency', 'budgets', 'categories'])->get()->groupBy('identifier');

        foreach ($groups as $identifier => $items) {
            /** @var Transaction|null $source */
            $source = $items->first(
                function (Transaction $transaction) {
                    return bccomp((string)$transaction->amount, '0', 12) < 0;
                }
            );
            /** @var Transaction|null $destination */
            $destination = $items->first(
                function (Transaction $transaction) {
                    return bccomp((string)$transaction->amount, '0', 12) > 0;
                }
            );
            if (null === $source || null === $destination) {
                continue;
            }

            $category = $destination->categories->first() ?: $source->categories->first();
            $budget   = $destination->budgets->first() ?: $source->budgets->first();
            $currency = $destination->transactionCurrency ?: $source->transactionCurrency;

            $transactions[(int)$identifier] = [
                'amount'                => ltrim((string)$destination->amount, '-'),
                'description'           => $destination->description,
                'currency_id'           => optional($currency)->id,
                'currency_code'         => optional($currency)->code,
                'foreign_amount'        => null === $destination->foreign_amount ? null : ltrim((string)$destination->foreign_amount, '-'),
                'foreign_currency_id'   => $destination->foreign_currency_id,
                'foreign_currency_code' => null,
                'budget_id'             => null === $budget ? null : $budget->id,
                'budget_name'           => null,
                'category_id'           => null === $category ? null : $category->id,
                'category_name'         => null,
                'source_id'             => $source->account_id,
                'source_name'           => null,
                'destination_id'        => $destination->account_id,
                'destination_name'      => null,
                'reconciled'            => (bool)$destination->reconciled,
                'identifier'            => (int)$identifier,
            ];
        }

        ksort($transactions);

        return $this->transactionPayload(
            $journal->user,
            $journal->transactionType->type,
            $journal->description,
            $journal->date->copy(),
            $transactions,
            (string)optional($journal->notes()->first())->text,
            ''
        );
    }

    private function searchJournals(User $user, array $input, array $resolved): array
    {
        $limit = $this->limit($input);
        $query = TransactionJournal::query()
                                   ->with(['transactionType', 'transactions.account.accountType', 'transactions.transactionCurrency', 'transactions.budgets', 'transactions.categories', 'notes'])
                                   ->where('transaction_journals.user_id', $user->id)
                                   ->whereNull('transaction_journals.deleted_at');

        $type = (string)($input['transaction_type'] ?? '');
        if ('' !== $type) {
            $query->whereHas(
                'transactionType',
                function (Builder $query) use ($type) {
                    $query->where('type', $type);
                }
            );
        }

        if (isset($input['description']) && '' !== trim((string)$input['description'])) {
            $query->where('transaction_journals.description', 'like', '%' . $this->like((string)$input['description']) . '%');
        }

        if (isset($input['from']) && '' !== (string)$input['from']) {
            $query->where('transaction_journals.date', '>=', Carbon::parse((string)$input['from'])->format('Y-m-d 00:00:00'));
        }
        if (isset($input['to']) && '' !== (string)$input['to']) {
            $query->where('transaction_journals.date', '<=', Carbon::parse((string)$input['to'])->format('Y-m-d 23:59:59'));
        }
        if (!isset($input['from']) && !isset($input['to'])) {
            $query->where('transaction_journals.date', '>=', Carbon::now()->subMonths(3)->format('Y-m-d 00:00:00'));
        }

        $accountIds = array_filter(
            [
                $this->selectedId($resolved, 'source_account'),
                $this->selectedId($resolved, 'destination_account'),
                $this->selectedId($resolved, 'actor'),
            ]
        );
        if ([] !== $accountIds) {
            $query->whereHas(
                'transactions',
                function (Builder $query) use ($accountIds) {
                    $query->whereIn('account_id', $accountIds);
                }
            );
        }

        $category = $this->selected($resolved, 'category');
        if (null !== $category) {
            $query->whereHas(
                'transactions.categories',
                function (Builder $query) use ($category) {
                    $query->where('categories.id', (int)$category['id']);
                }
            );
        }

        $budget = $this->selected($resolved, 'budget');
        if (null !== $budget) {
            $query->whereHas(
                'transactions.budgets',
                function (Builder $query) use ($budget) {
                    $query->where('budgets.id', (int)$budget['id']);
                }
            );
        }

        if (isset($input['amount']) && '' !== (string)$input['amount']) {
            $amount = (string)$input['amount'];
            $query->whereHas(
                'transactions',
                function (Builder $query) use ($amount) {
                    $query->where('amount', app('steam')->positive($amount))
                          ->orWhere('amount', app('steam')->negative($amount));
                }
            );
        }

        return $query->orderBy('transaction_journals.date', 'desc')
                     ->orderBy('transaction_journals.id', 'desc')
                     ->limit($limit)
                     ->get()
                     ->map(
                         function (TransactionJournal $journal) {
                             return $this->journalSummary($journal);
                         }
                     )->all();
    }

    private function journalSummary(TransactionJournal $journal): array
    {
        $journal->loadMissing(['transactionType', 'transactions.account.accountType', 'transactions.transactionCurrency', 'transactions.budgets', 'transactions.categories', 'notes']);
        $payload = $this->payloadFromJournal($journal);

        return [
            'journal_id'       => $journal->id,
            'transaction_type' => optional($journal->transactionType)->type,
            'date'             => $journal->date->toDateString(),
            'description'      => $journal->description,
            'updated_at'       => null === $journal->updated_at ? null : $journal->updated_at->toIso8601String(),
            'notes'            => (string)optional($journal->notes->first())->text,
            'transactions'     => $payload['transactions'],
        ];
    }

    private function resolutionErrors(array $resolved): array
    {
        $errors = [];
        foreach ((array)($resolved['missing'] ?? []) as $field) {
            if ('budget_limit' === $field) {
                continue;
            }
            $errors[] = sprintf('Could not resolve %s.', $field);
        }
        foreach ((array)($resolved['ambiguous'] ?? []) as $field) {
            if ('budget_limit' === $field) {
                continue;
            }
            $errors[] = sprintf('Ambiguous %s.', $field);
        }

        return $errors;
    }

    private function selected(array $resolved, string $key): ?array
    {
        if (!isset($resolved['entities'][$key]['selected']) || null === $resolved['entities'][$key]['selected']) {
            return null;
        }

        return (array)$resolved['entities'][$key]['selected'];
    }

    private function selectedId(array $resolved, string $key): ?int
    {
        $selected = $this->selected($resolved, $key);

        return null === $selected ? null : (int)$selected['id'];
    }

    private function counterparty(array $resolved, string $type): ?array
    {
        if (TransactionType::WITHDRAWAL === $type) {
            return $this->selected($resolved, 'actor') ?: $this->selected($resolved, 'destination_account');
        }

        return $this->selected($resolved, 'destination_account') ?: $this->selected($resolved, 'actor');
    }

    private function sourceAccountForType(array $resolved, string $type): ?array
    {
        if (TransactionType::DEPOSIT === $type) {
            return $this->selected($resolved, 'actor');
        }

        return $this->selected($resolved, 'source_account') ?: $this->selected($resolved, 'actor');
    }

    private function destinationAccountForType(array $resolved, string $type): ?array
    {
        if (TransactionType::DEPOSIT === $type) {
            return $this->selected($resolved, 'destination_account') ?: $this->selected($resolved, 'source_account');
        }

        if (TransactionType::TRANSFER === $type) {
            return $this->selected($resolved, 'destination_account');
        }

        return $this->counterparty($resolved, $type);
    }

    private function currency(User $user, array $resolved, bool $useDefault = true): ?TransactionCurrency
    {
        $selected = $this->selected($resolved, 'currency');
        if (null !== $selected) {
            return TransactionCurrency::find((int)$selected['id']);
        }
        if (!$useDefault) {
            return null;
        }

        $currency = app('amount')->getDefaultCurrencyByUser($user);
        if (null !== $currency) {
            return $currency;
        }

        return TransactionCurrency::where('enabled', true)->orderBy('id')->first();
    }

    private function transactionType(array $input): string
    {
        $type = (string)($input['transaction_type'] ?? TransactionType::WITHDRAWAL);

        return '' === $type ? TransactionType::WITHDRAWAL : $type;
    }

    private function date(array $input): Carbon
    {
        if (!isset($input['date']) || '' === (string)$input['date']) {
            return Carbon::now();
        }

        return Carbon::parse((string)$input['date']);
    }

    private function journal(User $user, int $journalId): ?TransactionJournal
    {
        if ($journalId < 1) {
            return null;
        }

        return TransactionJournal::query()
                                 ->with(['transactionType', 'transactions', 'notes'])
                                 ->where('user_id', $user->id)
                                 ->whereNull('deleted_at')
                                 ->where('id', $journalId)
                                 ->first();
    }

    private function journalState(TransactionJournal $journal): array
    {
        return [
            'journal_id'  => $journal->id,
            'updated_at'  => null === $journal->updated_at ? null : $journal->updated_at->toIso8601String(),
            'deleted_at'  => null === $journal->deleted_at ? null : $journal->deleted_at->toIso8601String(),
        ];
    }

    private function assertPreviewStillValid(User $user, array $payload): void
    {
        $state = (array)($payload['_preview_state'] ?? []);
        if ([] === $state) {
            return;
        }

        $journal = $this->journal($user, (int)($state['journal_id'] ?? 0));
        if (null === $journal) {
            throw new FireflyException('Journal changed after preview.');
        }

        $currentUpdatedAt = null === $journal->updated_at ? null : $journal->updated_at->toIso8601String();
        if ($currentUpdatedAt !== ($state['updated_at'] ?? null)) {
            throw new FireflyException('Journal changed after preview.');
        }
    }

    private function explicitTransactionsCoverExistingSplits(TransactionJournal $journal, array $transactions): bool
    {
        $existing = $journal->transactions()->where('amount', '>', 0)->pluck('identifier')->map(
            function ($value) {
                return (int)$value;
            }
        )->sort()->values()->all();

        $submitted = [];
        foreach ($transactions as $index => $transaction) {
            $submitted[] = (int)($transaction['identifier'] ?? $index);
        }
        sort($submitted);

        return $existing === array_values(array_unique($submitted));
    }

    private function normalizePayloadDates(array $payload): array
    {
        if (isset($payload['date']) && \is_string($payload['date'])) {
            $payload['date'] = Carbon::parse($payload['date']);
        }

        return $payload;
    }

    private function auditPreview(User $user, array $input, FinanceTransactionPreview $preview, string $source): HermesFinanceAudit
    {
        $data = $preview->toArray();

        return HermesFinanceAudit::create(
            [
                'user_id'          => $user->id,
                'source'           => $source,
                'source_type'      => $data['source_metadata']['source_type'] ?? '',
                'source_id'        => (string)($input['source_id'] ?? ''),
                'source_hash'      => $data['source_metadata']['source_hash'] ?? '',
                'action'           => $data['action'],
                'mode'             => 'preview',
                'status'           => $data['can_apply'] ? 'pending_token' : 'blocked',
                'request_text'     => (string)($input['request_text'] ?? ''),
                'request_payload'  => $input,
                'resolved_payload' => $data['payload'],
                'result_payload'   => [
                    'resolved'   => $data['resolved'],
                    'candidates' => $data['candidates'],
                    'errors'     => $data['errors'],
                    'source_metadata' => $data['source_metadata'],
                    'requires_confirmation' => $data['requires_confirmation'],
                ],
                'journal_ids'      => $this->payloadJournalIds($data['payload']),
            ]
        );
    }

    private function payloadJournalIds(array $payload): array
    {
        if (isset($payload['journal_id'])) {
            return [(int)$payload['journal_id']];
        }
        if (isset($payload['_preview_state']['journal_id'])) {
            return [(int)$payload['_preview_state']['journal_id']];
        }

        return [];
    }

    private function previewToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function previewExpiresAt(): Carbon
    {
        return Carbon::now()->addMinutes((int)config('hermes_finance.preview_ttl_minutes', 15));
    }

    private function previewValidAfter(): Carbon
    {
        return Carbon::now()->subMinutes((int)config('hermes_finance.preview_ttl_minutes', 15));
    }

    private function source(array $input): string
    {
        $source = (string)($input['source'] ?? 'hermes');

        return '' === $source ? 'hermes' : $source;
    }

    private function sourceMetadata(array $input, string $source): array
    {
        $sourceType = strtolower(trim((string)($input['source_type'] ?? '')));
        if ('' === $sourceType) {
            $sourceType = 'hermes' === $source ? 'telegram' : $source;
        }

        $metadata = [
            'source'      => $source,
            'source_type' => $sourceType,
            'source_id'   => (string)($input['source_id'] ?? ''),
            'source_hash' => (string)($input['source_hash'] ?? ''),
        ];

        $evidence = (array)($input['evidence'] ?? []);
        if ([] !== $evidence) {
            $text = (string)($evidence['text'] ?? $evidence['extracted_text'] ?? '');
            $metadata['evidence'] = [
                'keys'        => array_values(array_keys($evidence)),
                'text_length' => mb_strlen($text),
            ];
        }

        return $metadata;
    }

    private function requiresConfirmation(array $input): bool
    {
        $action = (string)($input['action'] ?? 'create');

        return \in_array($action, ['create', 'update', 'delete'], true);
    }

    private function sourceSpecificErrors(array $input, string $action): array
    {
        $sourceType = strtolower((string)($input['source_type'] ?? ''));
        if ('create' !== $action || !\in_array($sourceType, ['receipt', 'email'], true)) {
            return [];
        }

        if (!isset($input['category']) || '' === trim((string)$input['category'])) {
            return ['Missing required field: category for receipt/email source.'];
        }

        return [];
    }

    private function previewSourceMetadata(HermesFinanceAudit $preview, array $input, string $source): array
    {
        $resultPayload = (array)$preview->result_payload;
        $metadata      = (array)($resultPayload['source_metadata'] ?? []);

        return [] === $metadata ? $this->sourceMetadata($input, $source) : $metadata;
    }

    private function limit(array $input): int
    {
        $limit = (int)($input['limit'] ?? 10);

        return max(1, min(25, $limit));
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
