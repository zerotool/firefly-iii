<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Hermes;

use FireflyIII\Api\V1\Requests\Request;

class FinanceTransactionPreviewRequest extends Request
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $evidenceTextLimit = (int)config('hermes_finance.evidence_text_limit', 5000);

        return [
            'action'                 => 'required|in:create,update,delete',
            'transaction_type'       => 'nullable|in:withdrawal,deposit,transfer',
            'amount'                 => 'nullable|numeric|min:0.01',
            'description'            => 'nullable|string|min:1|max:255',
            'date'                   => 'nullable|date',
            'source_account'         => 'nullable|string|max:255',
            'destination_account'    => 'nullable|string|max:255',
            'actor'                  => 'nullable|string|max:255',
            'category'               => 'nullable|string|max:255',
            'budget'                 => 'nullable|string|max:255',
            'currency'               => 'nullable|string|max:32',
            'journal_id'             => 'nullable|integer|min:1',
            'transactions'           => 'nullable|array',
            'transactions.*.amount'  => 'nullable|numeric|min:0.01',
            'transactions.*.identifier' => 'nullable|integer|min:0',
            'request_text'           => 'nullable|string|max:50000',
            'notes'                  => 'nullable|string|max:50000',
            'source'                 => 'nullable|string|max:64',
            'source_type'            => 'nullable|string|max:32',
            'source_id'              => 'nullable|string|max:191',
            'source_hash'            => 'nullable|string|max:128',
            'evidence'               => 'nullable|array',
            'evidence.*'             => 'nullable|string|max:' . $evidenceTextLimit,
            'evidence.vendor'        => 'nullable|string|max:255',
            'evidence.text'          => 'nullable|string|max:' . $evidenceTextLimit,
            'evidence.extracted_text' => 'nullable|string|max:' . $evidenceTextLimit,
            'evidence.email_subject' => 'nullable|string|max:255',
            'evidence.email_from'    => 'nullable|string|max:255',
            'evidence.received_at'   => 'nullable|date',
            'evidence.attachment_name' => 'nullable|string|max:255',
            'evidence.file_name'     => 'nullable|string|max:255',
            'evidence.url'           => 'nullable|string|max:2048',
            'from'                   => 'nullable|date',
            'to'                     => 'nullable|date',
            'limit'                  => 'nullable|integer|min:1|max:25',
        ];
    }
}
