<?php

declare(strict_types=1);

namespace FireflyIII\Http\Middleware;

use Closure;
use FireflyIII\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HermesFinance
{
    /**
     * Handle an incoming Hermes finance request.
     *
     * @param Request $request
     * @param Closure $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!config('hermes_finance.enabled')) {
            return response()->json(['message' => 'Hermes finance API is disabled.'], 503);
        }

        if (!$this->sourceAllowed((string)$request->ip())) {
            return response()->json(['message' => 'Hermes finance source is not allowed.'], 403);
        }

        $token = (string)$request->bearerToken();
        if ('' === $token || !$this->tokenMatches($token)) {
            return response()->json(['message' => 'Hermes finance authentication failed.'], 401);
        }

        $userId = (int)config('hermes_finance.ledger_user_id');
        if ($userId < 1 || !Auth::onceUsingId($userId)) {
            return response()->json(['message' => 'Hermes finance ledger user is invalid.'], 503);
        }

        /** @var User $user */
        $user = Auth::user();
        $request->attributes->set('hermes_finance_user', $user);

        return $next($request);
    }

    private function sourceAllowed(string $ip): bool
    {
        $cidrs = (array)config('hermes_finance.allowed_cidrs', []);
        $cidrs = array_values(array_filter($cidrs));
        if ([] === $cidrs) {
            return true;
        }

        foreach ($cidrs as $cidr) {
            if ($this->ipMatchesCidr($ip, (string)$cidr)) {
                return true;
            }
        }

        return false;
    }

    private function tokenMatches(string $token): bool
    {
        $expectedHash = (string)config('hermes_finance.token_hash', '');
        if ('' === $expectedHash) {
            return false;
        }

        return hash_equals($expectedHash, hash('sha256', $token));
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if ($ip === $cidr) {
            return true;
        }

        if (false === strpos($cidr, '/')) {
            return false;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask       = (int)$mask;

        if (false === $ipLong || false === $subnetLong || $mask < 0 || $mask > 32) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
