<?php

namespace App\Http\Middleware;

use App\Support\HelpdeskIntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyHelpdeskMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) $request->bearerToken();
        $valid = $provided !== '' && collect(config('services.helpdesk_mcp.tokens', []))
            ->contains(static fn (string $expected): bool => hash_equals($expected, $provided));

        if (! $valid) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $request->input('id'),
                'error' => [
                    'code' => -32001,
                    'message' => 'Token MCP Helpdesk tidak valid.',
                ],
            ], 401);
        }

        $request->attributes->set(
            HelpdeskIntegrationClient::REQUEST_ATTRIBUTE,
            hash('sha256', $provided),
        );

        return $next($request);
    }
}
