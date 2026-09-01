<?php

namespace App\Support;

use Illuminate\Http\Request;

final class HelpdeskIntegrationClient
{
    public const REQUEST_ATTRIBUTE = 'helpdesk_mcp_client_id';

    public const WORKFLOW_ACTOR_ATTRIBUTE = 'helpdesk_mcp_workflow_actor_id';

    public static function currentRequestFingerprint(): ?string
    {
        $request = app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request) {
            return null;
        }

        $fingerprint = (string) $request->attributes->get(self::REQUEST_ATTRIBUTE, '');

        return $fingerprint !== '' ? substr($fingerprint, 0, 64) : null;
    }

    public static function currentWorkflowActorId(): ?int
    {
        $request = app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request) {
            return null;
        }

        $actorId = filter_var(
            $request->attributes->get(self::WORKFLOW_ACTOR_ATTRIBUTE),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        return is_int($actorId) ? $actorId : null;
    }

    public static function internalClientKey(): string
    {
        return hash('sha256', 'internal-helpdesk');
    }

    public static function persistentKey(string $clientId = ''): string
    {
        $clientId = trim($clientId);
        if (str_starts_with($clientId, 'token:')) {
            $fingerprint = substr($clientId, strlen('token:'));
            if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
                return $fingerprint;
            }
        }

        return $clientId !== ''
            ? hash('sha256', $clientId)
            : self::internalClientKey();
    }
}
