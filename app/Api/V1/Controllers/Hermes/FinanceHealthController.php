<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Hermes;

use FireflyIII\Api\V1\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceHealthController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        return response()->json(
            [
                'data' => [
                    'ok'             => true,
                    'ledger_user_id' => (int)optional($request->attributes->get('hermes_finance_user'))->id,
                    'version'        => config('firefly.version'),
                ],
            ]
        )->header('Content-Type', 'application/vnd.api+json');
    }
}
