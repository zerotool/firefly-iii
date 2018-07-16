<?php

namespace FireflyIII\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class Kassa
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        Auth::loginUsingId(1);
        $result = $next($request);
        Auth::logout();
        return $result;
    }
}
