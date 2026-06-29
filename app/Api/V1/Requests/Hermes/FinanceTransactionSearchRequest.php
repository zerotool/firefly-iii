<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Hermes;

use FireflyIII\Api\V1\Requests\Request;

class FinanceTransactionSearchRequest extends Request
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_type'    => 'nullable|in:withdrawal,deposit,transfer',
            'description'         => 'nullable|string|max:255',
            'source_account'      => 'nullable|string|max:255',
            'destination_account' => 'nullable|string|max:255',
            'actor'               => 'nullable|string|max:255',
            'category'            => 'nullable|string|max:255',
            'budget'              => 'nullable|string|max:255',
            'currency'            => 'nullable|string|max:32',
            'amount'              => 'nullable|numeric|min:0.01',
            'from'                => 'nullable|date',
            'to'                  => 'nullable|date',
            'limit'               => 'nullable|integer|min:1|max:25',
        ];
    }
}
