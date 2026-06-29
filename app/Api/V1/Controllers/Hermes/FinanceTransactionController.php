<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Hermes;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Hermes\FinanceTransactionApplyRequest;
use FireflyIII\Api\V1\Requests\Hermes\FinanceTransactionPreviewRequest;
use FireflyIII\Api\V1\Requests\Hermes\FinanceTransactionSearchRequest;
use FireflyIII\Services\Hermes\FinanceActionService;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;

class FinanceTransactionController extends Controller
{
    /** @var FinanceActionService */
    private $actions;

    public function __construct(FinanceActionService $actions)
    {
        parent::__construct();
        $this->actions = $actions;
    }

    public function search(FinanceTransactionSearchRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('hermes_finance_user');

        return $this->json($this->actions->search($user, $request->all()));
    }

    public function preview(FinanceTransactionPreviewRequest $request): JsonResponse
    {
        /** @var User $user */
        $user    = $request->attributes->get('hermes_finance_user');
        $preview = $this->actions->preview($user, $request->all());

        return $this->json(['data' => $preview->toArray()]);
    }

    public function apply(FinanceTransactionApplyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user   = $request->attributes->get('hermes_finance_user');
        $result = $this->actions->apply($user, $request->all());
        $status = false === ($result['applied'] ?? false) ? 409 : 200;

        return $this->json(['data' => $result], $status);
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Content-Type', 'application/vnd.api+json');
    }
}
