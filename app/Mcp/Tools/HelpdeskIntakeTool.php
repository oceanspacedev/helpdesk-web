<?php

namespace App\Mcp\Tools;

use App\Services\Integrations\HelpdeskIntakeSession;
use App\Services\Integrations\HelpdeskMcpConfiguration;
use App\Support\HelpdeskIntakeContract;
use App\Support\HelpdeskIntegrationClient;
use App\Support\HelpdeskReporterName;
use App\Support\HttpsUrl;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('helpdesk_intake')]
#[Description('Deterministic multi-turn Helpdesk intake with only two business paths: use an existing eligible reporter account to create its ticket, or create the required reporter account first and then create its ticket in the same intake. Account creation is not standalone, and this tool never looks up, comments on, updates, or administers tickets. Reporter identity is a verified WhatsApp number resolved against Helpdesk/Talenta. External IDs identify routing context and can retrieve a previously verified binding, but never prove identity by themselves. Call once for each new user reply only while status is in_progress, pass message unchanged, and return the previous intake_id. Every call with channel other than mcp requires the exact same gateway-injected external_user_id, including the first call. A direct channel=mcp client may omit it, but then it must verify by OTP on every new intake. Stop on terminal or error instead of looping. When a verified number is absent from Helpdesk and Talenta, forward registration_consent; after explicit yes, forward registration_name and use only the full name typed in that step. The server atomically creates a phone-only account and resumes the same intake. reporter_name is never registration consent or the account name. A declined registration returns terminal cancelled without creating an account or ticket. The server asks every question, performs OTP when needed, validates choices, and creates only after confirmation. Forward content[0].text, or structuredContent.user_reply as fallback. Never invent identities, ticket numbers, or classifications.')]
class HelpdeskIntakeTool extends Tool
{
    private const STRING_FIELDS = [
        'intake_id',
        'conversation_id',
        'external_conversation_id',
        'external_user_id',
        'external_message_id',
        'message_id',
        'phone',
        'identity_assertion',
        'channel',
        'reporter_name',
    ];

    private const ERROR_STATUSES = [
        'invalid_input',
        'invalid_session',
        'message_conflict',
    ];

    public function __construct(
        private HelpdeskIntakeSession $session,
        private HelpdeskMcpConfiguration $configuration,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        // The local stdio server is a long-lived console process. Refresh its
        // scoped snapshot once per tool call so UI changes apply immediately.
        if (app()->runningInConsole()) {
            $this->configuration->forgetResolvedSettings();
        }

        if ($invalid = $this->validateInput($request)) {
            return $invalid;
        }

        $attachments = $this->attachments($request);
        if (trim((string) $request->get('message')) === '' && $attachments === []) {
            return $this->invalidInput('message boleh kosong hanya untuk pesan yang memiliki attachment_urls.', 'INVALID_MESSAGE');
        }

        $result = $this->session->handle(
            (string) ($request->get('phone') ?? ''),
            (string) ($request->get('message') ?? ''),
            $attachments,
            (string) ($request->get('channel') ?? 'mcp'),
            (string) ($request->get('reporter_name') ?? ''),
            [
                'client_id' => $this->clientId(),
                'intake_id' => (string) ($request->get('intake_id') ?? ''),
                'conversation_id' => (string) ($request->get('conversation_id') ?? ''),
                'external_conversation_id' => (string) ($request->get('external_conversation_id') ?? ''),
                'external_user_id' => (string) ($request->get('external_user_id') ?? ''),
                'external_message_id' => (string) ($request->get('external_message_id') ?? $request->get('message_id') ?? ''),
                'identity_assertion' => (string) ($request->get('identity_assertion') ?? ''),
                'transport_session_id' => (string) ($request->sessionId() ?? ''),
                'start' => (bool) ($request->get('start') ?? false),
            ],
        );

        $response = in_array($result['status'] ?? '', self::ERROR_STATUSES, true)
            ? Response::error($result['user_reply'])
            : Response::text($result['user_reply']);

        return (new ResponseFactory($response))
            ->withStructuredContent($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intake_id' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_INTAKE_ID_LENGTH)
                ->description('Opaque handle returned by the previous call. Omit on the first call; never invent or modify it.'),
            'conversation_id' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_ID_LENGTH)
                ->description('Optional stable conversation/thread ID supplied by a client. Provide it when one direct MCP transport carries parallel chats. It is an alias only; keep using intake_id after the first result.'),
            'external_conversation_id' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_ID_LENGTH)
                ->description('Optional source conversation/thread ID injected by a gateway.'),
            'external_user_id' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_ID_LENGTH)
                ->description('Stable source sender ID injected by a gateway. Required on the first and every later call whenever channel is not mcp. A direct channel=mcp client may omit it, but then no durable reporter binding is reused and every new intake requires OTP. Do not infer it from message text.'),
            'external_message_id' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_ID_LENGTH)
                ->description('Optional stable inbound message ID. Reuse it on retries to avoid advancing the form twice.'),
            'message_id' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_ID_LENGTH)
                ->description('Alias of external_message_id for generic clients.'),
            'phone' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_PHONE_LENGTH)
                ->description('Reporter WhatsApp number used for Helpdesk/Talenta identity. Untrusted clients may provide it as a hint; the server verifies ownership by WhatsApp OTP.'),
            'identity_assertion' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_IDENTITY_ASSERTION_LENGTH)
                ->description('Optional one-event HMAC assertion generated and injected by a trusted WhatsApp adapter. It requires external_message_id. Never generate, infer, or ask the user for this value.'),
            'message' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_MESSAGE_LENGTH)
                ->description('Exact user message. It may be empty only for an attachment-only turn. Do not summarize, translate, or classify it.')
                ->required(),
            'channel' => $schema->string()
                ->max(HelpdeskIntakeContract::MAX_CHANNEL_LENGTH)
                ->pattern(HelpdeskIntakeContract::CHANNEL_PATTERN)
                ->description('Lowercase source label, for example mcp, web, whatsapp, telegram, discord, slack, or teams. Defaults to mcp for a direct client. Every external-channel call requires this same value plus external_user_id.'),
            'reporter_name' => $schema->string()
                ->max(HelpdeskReporterName::MAX_LENGTH)
                ->description('Optional untrusted display-name hint only. It never replaces WhatsApp verification or Talenta lookup and is never accepted as the name for inline registration; the user must type the full name when the server asks at registration_name.'),
            'attachment_urls' => $schema->array()
                ->max(HelpdeskIntakeContract::MAX_ATTACHMENTS)
                ->unique()
                ->items($schema->string()->format('uri')->max(HttpsUrl::MAX_LENGTH))
                ->description('Up to five HTTPS attachment URLs. Private handles must be uploaded by the gateway first.'),
            'start' => $schema->boolean()
                ->default(false)
                ->description('Set true only on the first call when intent is already known, so the whole message starts intake even without a trigger phrase.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'ok' => $schema->boolean()->required(),
            'status' => $schema->string()->required(),
            'user_reply' => $schema->string()->required(),
            'intake_id' => $schema->string()->required(),
            'step' => $schema->string()->required(),
            'requires_input' => $schema->boolean()->required(),
            'terminal' => $schema->boolean()->required(),
            'expires_at' => $schema->string()->format('date-time')->required(),
            'replayed' => $schema->boolean()->required(),
            'ticket' => $schema->object([
                'id' => $schema->integer(),
                'ticket_id' => $schema->integer(),
                'ticket_number' => $schema->string(),
                'status' => $schema->string(),
            ])->nullable(),
            'data' => $schema->object(),
        ];
    }

    private function validateInput(Request $request): ?ResponseFactory
    {
        if (! is_string($request->get('message'))) {
            return $this->invalidInput('message wajib berupa string (boleh kosong hanya untuk pesan berisi lampiran).', 'INVALID_MESSAGE');
        }

        foreach (self::STRING_FIELDS as $field) {
            if ($request->get($field) !== null && ! is_string($request->get($field))) {
                return $this->invalidInput("{$field} wajib berupa string.", 'INVALID_ARGUMENT_TYPE');
            }
        }

        if ($request->get('start') !== null && ! is_bool($request->get('start'))) {
            return $this->invalidInput('start wajib berupa boolean.', 'INVALID_ARGUMENT_TYPE');
        }

        $attachments = $request->get('attachment_urls');
        if ($attachments !== null
            && (! is_array($attachments) || count($attachments) > HelpdeskIntakeContract::MAX_ATTACHMENTS)) {
            return $this->invalidInput('attachment_urls wajib berupa array dengan maksimum lima URL.', 'INVALID_ATTACHMENTS');
        }
        if (is_array($attachments)
            && array_filter($attachments, fn (mixed $url): bool => ! is_string($url)) !== []) {
            return $this->invalidInput('Setiap attachment_urls wajib berupa string URL HTTPS.', 'INVALID_ATTACHMENTS');
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function attachments(Request $request): array
    {
        $attachments = $request->get('attachment_urls');

        return is_array($attachments)
            ? array_values(array_filter(array_map('strval', $attachments)))
            : [];
    }

    private function clientId(): string
    {
        if ($fingerprint = HelpdeskIntegrationClient::currentRequestFingerprint()) {
            return 'token:'.$fingerprint;
        }

        $configured = trim((string) config('services.helpdesk_mcp.local_client_id', ''));
        if ($configured !== '') {
            return 'local-configured:'.hash('sha256', $configured);
        }

        static $ephemeralId;
        $ephemeralId ??= Str::random(40);

        return 'local-ephemeral:'.$ephemeralId;
    }

    private function invalidInput(string $message, string $code): ResponseFactory
    {
        $result = [
            'ok' => false,
            'status' => 'invalid_input',
            'user_reply' => $message,
            'intake_id' => '',
            'step' => 'idle',
            'requires_input' => false,
            'terminal' => false,
            'expires_at' => now()->toIso8601String(),
            'replayed' => false,
            'ticket' => null,
            'data' => [
                'step' => 'idle',
                'error_code' => $code,
            ],
        ];

        return (new ResponseFactory(Response::error($message)))
            ->withStructuredContent($result);
    }
}
