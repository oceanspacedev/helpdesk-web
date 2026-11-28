<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token statis untuk dashboard read-only. Diterima lewat header
 * Authorization: Bearer, atau ?token= karena EventSource di browser tidak
 * bisa mengirim header sendiri.
 */
class VerifyHelpdeskDashboardToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) ($request->bearerToken() ?: $request->query('token', ''));
        $tokens = (array) config('helpdesk_dashboard.tokens', []);

        $valid = $provided !== '' && collect($tokens)
            ->contains(static fn ($expected): bool => is_string($expected)
                && $expected !== ''
                && hash_equals($expected, $provided));

        if (! $valid) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Token dashboard tidak valid.',
            ], 401);
        }

        return $next($request);
    }
}
