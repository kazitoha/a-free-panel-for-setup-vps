<?php

namespace App\Http\Middleware;

use App\Services\SetupState;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a setup step is outstanding there is nothing to sign in with — the
 * first-run page collects those credentials, so every other page (including
 * /login) hands the visitor over to it.
 */
class EnsureSetupIsComplete
{
    public function __construct(private readonly SetupState $setup) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if ($this->setup->isPending()) {
            return redirect()->route('firstrun.index');
        }

        return $next($request);
    }
}
