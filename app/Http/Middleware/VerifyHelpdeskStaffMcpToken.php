<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Integrations\HelpdeskMcpConfiguration;
use App\Support\HelpdeskIntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyHelpdeskStaffMcpToken
{
    public function __construct(
        private HelpdeskMcpConfiguration $configuration,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $provided = (string) $request->bearerToken();
        $record = $this->configuration->workflowTokenRecord($provided);
        $actor = $this->eligibleActor($record['workflow_user_id'] ?? null);

        if (! $record || ! $actor) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $request->input('id'),
                'error' => [
                    'code' => -32001,
                    'message' => 'Kredensial MCP Staff Helpdesk tidak valid.',
                ],
            ], 401);
        }

        $request->attributes->set(
            HelpdeskIntegrationClient::REQUEST_ATTRIBUTE,
            hash('sha256', $provided),
        );
        $request->attributes->set(
            HelpdeskIntegrationClient::WORKFLOW_ACTOR_ATTRIBUTE,
            $actor->getKey(),
        );

        return $next($request);
    }

    private function eligibleActor(mixed $actorId): ?User
    {
        try {
            $actor = User::query()->find($actorId);

            return $actor
                && $actor->is_active
                && $actor->canProcessTickets()
                && $actor->can('Update:Ticket')
                    ? $actor
                    : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
