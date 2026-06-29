<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Hermes;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Services\Hermes\FinanceResolver;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceResolverController extends Controller
{
    /** @var FinanceResolver */
    private $resolver;

    public function __construct(FinanceResolver $resolver)
    {
        parent::__construct();
        $this->resolver = $resolver;
    }

    public function resolve(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('hermes_finance_user');
        $data = $this->resolver->resolve($user, $request->all())->toArray();

        return response()->json(['data' => $data])->header('Content-Type', 'application/vnd.api+json');
    }
}
