<?php

namespace App\Http\Middleware;

use App\Services\Integrations\HelpdeskMcpConfiguration;
use App\Support\HelpdeskIntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyHelpdeskMcpToken
{
    public function __construct(
        private HelpdeskMcpConfiguration $configuration,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) $request->bearerToken();
        $valid = $provided !== '' && collect($this->configuration->tokens())
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
