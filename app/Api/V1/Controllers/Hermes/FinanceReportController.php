<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Hermes;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Hermes\FinanceReportRequest;
use FireflyIII\Services\Hermes\FinanceReportService;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;

class FinanceReportController extends Controller
{
    /** @var FinanceReportService */
    private $reports;

    public function __construct(FinanceReportService $reports)
    {
        parent::__construct();
        $this->reports = $reports;
    }

    public function report(FinanceReportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user   = $request->attributes->get('hermes_finance_user');
        $result = $this->reports->report($user, $request->all());
        $status = [] === ($result['errors'] ?? []) ? 200 : 422;

        return response()->json(['data' => $result], $status)->header('Content-Type', 'application/vnd.api+json');
    }
}
