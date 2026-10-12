<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every privileged panel route is admin-only. Regular accounts are rejected
 * before they can reach any controller that drives the root runner.
 */
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_admin) {
            abort(403, 'This panel is restricted to administrators.');
        }

        return $next($request);
    }
}
