<?php

namespace App\Http\Middleware;

use App\Services\SetupState;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first-run page only exists while something is still missing; once the
 * credentials are collected it stops serving and sends visitors to /login.
 */
class EnsureSetupIsPending
{
    public function __construct(private readonly SetupState $setup) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (! $this->setup->isPending()) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
