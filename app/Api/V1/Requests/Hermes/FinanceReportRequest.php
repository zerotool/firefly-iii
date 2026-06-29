<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Hermes;

use FireflyIII\Api\V1\Requests\Request;

class FinanceReportRequest extends Request
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report'              => 'required|in:budget_remaining,period_summary,account_balances,hotel,actor,transactions',
            'period'              => 'nullable|string|max:32',
            'start'               => 'nullable|date',
            'end'                 => 'nullable|date',
            'from'                => 'nullable|date',
            'to'                  => 'nullable|date',
            'date'                => 'nullable|date',
            'budget'              => 'nullable|string|max:255',
            'currency'            => 'nullable|string|max:32',
            'source_account'      => 'nullable|string|max:255',
            'destination_account' => 'nullable|string|max:255',
            'actor'               => 'nullable|string|max:255',
            'hotel'               => 'nullable|string|max:255',
            'category'            => 'nullable|string|max:255',
            'transaction_type'    => 'nullable|in:withdrawal,deposit,transfer',
            'description'         => 'nullable|string|max:255',
            'limit'               => 'nullable|integer|min:1|max:50',
        ];
    }
}
