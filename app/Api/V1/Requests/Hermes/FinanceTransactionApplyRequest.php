<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Hermes;

use FireflyIII\Api\V1\Requests\Request;

class FinanceTransactionApplyRequest extends Request
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preview_token'   => 'required|string|min:32|max:255',
            'idempotency_key' => 'required|string|min:8|max:191',
            'source'          => 'nullable|string|max:64',
            'source_id'       => 'nullable|string|max:191',
        ];
    }
}
