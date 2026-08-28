<?php

namespace App\Services\Integrations;

use App\Models\McpTicketCreationRequest;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\EmployeeService;
use App\Support\HelpdeskIntegrationClient;
use App\Support\HttpsUrl;
use App\Support\PhoneNumber;
use App\Support\TalentaEmployee;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HelpdeskTicketCreationService
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly HelpdeskClassificationResolver $classificationResolver,
        private readonly HelpdeskFormOptionsService $formOptions,
    ) {}

    public function create(array $payload): array
    {
        $idempotencyKey = $this->text($payload['idempotency_key'] ?? '');
        if ($idempotencyKey === '') {
            return $this->error('validation_error', 'idempotency_key wajib untuk aksi yang mengubah data.', 'MISSING_IDEMPOTENCY_KEY');
        }

        $execute = fn (): array => $this->executeIdempotently($payload, $idempotencyKey);

        $actorPhones = $this->actorPhones($this->actor($payload));
        if (count($actorPhones) !== 1) {
            return $execute();
        }

        try {
            return Cache::lock(
                (string) PhoneNumber::reporterWriteLockKey($actorPhones[0]),
                30,
            )->block(5, $execute);
        } catch (LockTimeoutException) {
            return $this->error(
                'in_progress',
                'Laporan lain dari nomor ini sedang diproses. Coba kirim ulang sebentar lagi dengan idempotency_key yang sama.',
                'HELPDESK_REPORTER_BUSY',
            );
        }
    }

    private function execute(array $payload): array
    {
        $actor = $this->actor($payload);
        $verified = $this->bool($actor['is_verified'] ?? false);

        if (! $verified) {
            return $this->error('unauthorized', 'Identitas pelapor belum terverifikasi.', 'UNVERIFIED_HELPDESK_ACTOR');
        }

        $actorPhones = $this->actorPhones($actor);
        if ($actorPhones === []) {
            return $this->error(
                'unauthorized',
                'Nomor WhatsApp pelapor yang terverifikasi wajib tersedia.',
                'HELPDESK_ACTOR_PHONE_REQUIRED',
            );
        }
        if (count($actorPhones) > 1) {
            return $this->error(
                'identity_conflict',
                'Data identitas memuat lebih dari satu nomor WhatsApp. Kirim hanya nomor pengirim yang sudah diverifikasi.',
                'HELPDESK_ACTOR_PHONE_CONFLICT',
            );
        }

        $phone = $actorPhones[0];
        $actor['phone'] = $phone;
        $actor['identifier'] = $phone;

        $phoneUsers = $this->resolveActorUsersByPhone($phone);
        if (count($phoneUsers) > 1) {
            return $this->error(
                'identity_ambiguous',
                'Nomor WhatsApp cocok dengan lebih dari satu akun Helpdesk. Rapikan data akun sebelum membuat tiket.',
                'HELPDESK_PHONE_AMBIGUOUS',
            );
        }

        $matchedUser = $phoneUsers[0] ?? null;
        if ($matchedUser && ($matchedUser->trashed() || ! $matchedUser->is_active)) {
            return $this->error(
                'unauthorized',
                'Nomor WhatsApp terdaftar tetapi akun Helpdesk tidak aktif. Hubungi administrator Helpdesk.',
                'HELPDESK_ACTOR_INACTIVE',
            );
        }

        $expectedHelpdeskUserId = (int) data_get($actor, 'metadata.expected_helpdesk_user_id', 0);
        $expectedTalentaFingerprint = $this->text(data_get($actor, 'metadata.expected_talenta_fingerprint'));
        if ($expectedHelpdeskUserId > 0
            && (! $matchedUser || (int) $matchedUser->id !== $expectedHelpdeskUserId)) {
            return $this->identityChangedError();
        }
        if ($expectedTalentaFingerprint !== '' && $matchedUser) {
            return $this->identityChangedError();
        }

        $user = $matchedUser;
        if (! $user) {
            $employeeMatches = $this->employees->findAllByPhone($phone);
            if (count($employeeMatches) > 1) {
                return $this->error(
                    'identity_ambiguous',
                    'Nomor WhatsApp cocok dengan lebih dari satu karyawan Talenta. Rapikan data karyawan sebelum membuat tiket.',
                    'TALENTA_PHONE_AMBIGUOUS',
                );
            }

            $employee = $employeeMatches[0] ?? null;
            if (! is_array($employee)) {
                if ($expectedTalentaFingerprint !== '') {
                    return $this->identityChangedError();
                }

                return $this->error(
                    'account_required',
                    'Akun Helpdesk pelapor belum tersedia. Selesaikan pembuatan akun melalui intake MCP sebelum membuat tiket.',
                    'HELPDESK_REPORTER_ACCOUNT_REQUIRED',
                );
            }

            if ($expectedTalentaFingerprint !== '' && ! hash_equals(
                $expectedTalentaFingerprint,
                TalentaEmployee::identityFingerprint($employee),
            )) {
                return $this->identityChangedError();
            }

            $user = $this->createTalentaReporter($employee, $phone);
            if (! $user) {
                return $this->error(
                    'account_required',
                    'Data karyawan Talenta belum lengkap untuk membuat akun Helpdesk. Hubungi administrator Helpdesk.',
                    'HELPDESK_TALENTA_REPORTER_INVALID',
                );
            }
        }

        if (! $user) {
            return $this->error(
                'account_required',
                'Akun Helpdesk pelapor belum tersedia. Selesaikan pembuatan akun melalui intake MCP sebelum membuat tiket.',
                'HELPDESK_REPORTER_ACCOUNT_REQUIRED',
            );
        }

        return $this->createTicket($payload, $actor, $user);
    }

    private function executeIdempotently(array $payload, string $idempotencyKey): array
    {
        if (Str::length($idempotencyKey) > 255) {
            return $this->error('validation_error', 'idempotency_key terlalu panjang.', 'INVALID_IDEMPOTENCY_KEY');
        }

        $clientKey = $this->clientKey($payload);
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', $this->canonicalJson($this->idempotentPayload($payload)));

        return DB::transaction(function () use ($payload, $idempotencyKey, $clientKey, $keyHash, $requestHash): array {
            McpTicketCreationRequest::query()->insertOrIgnore([
                'client_key' => $clientKey,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $request = McpTicketCreationRequest::query()
                ->where('client_key', $clientKey)
                ->where('key_hash', $keyHash)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals((string) $request->request_hash, $requestHash)) {
                return $this->error(
                    'idempotency_conflict',
                    'idempotency_key sudah digunakan dengan payload berbeda.',
                    'IDEMPOTENCY_KEY_REUSED',
                );
            }

            if ($request->status === 'completed' && is_array($request->response)) {
                return $this->withIdempotency($request->response, $idempotencyKey, true);
            }

            $result = $this->execute($payload);
            $stored = $this->withIdempotency($result, $idempotencyKey, false);
            if ($this->isRetryableIdentityPrecondition($stored)) {
                // Identity precondition failures have no side effect. The
                // intake may reverify or create the required account, so its
                // idempotency key must remain available for the later retry.
                $request->delete();

                return $stored;
            }
            $request->update([
                'status' => 'completed',
                'response' => $stored,
            ]);

            return $stored;
        }, 3);
    }

    private function isRetryableIdentityPrecondition(array $result): bool
    {
        return in_array((string) data_get($result, 'error.code'), [
            'HELPDESK_ACTOR_CHANGED',
            'HELPDESK_REPORTER_ACCOUNT_REQUIRED',
        ], true);
    }

    private function createTicket(array $payload, array $actor, User $owner): array
    {
        $ticketData = $this->ticketData($payload);

        $title = $this->text($ticketData['title'] ?? $ticketData['issue_summary'] ?? '');
        if ($title === '') {
            return $this->validationError(
                'Judul tiket wajib diisi.',
                'MISSING_TICKET_FIELD',
                ['title'],
                $this->formOptions->formOptions(),
                $this->classificationResolver->nextQuestionForField('title'),
            );
        }

        $descriptionText = $this->text($ticketData['description'] ?? data_get($payload, 'message.text') ?? $ticketData['issue_summary'] ?? '');
        if ($descriptionText === '') {
            return $this->validationError(
                'Deskripsi tiket belum tersedia.',
                'MISSING_TICKET_DESCRIPTION',
                ['description'],
                $this->formOptions->formOptions(),
                $this->classificationResolver->nextQuestionForField('description'),
            );
        }

        $ticketData['title'] = $title;
        $ticketData['description'] = $descriptionText;

        if (! $this->bool($ticketData['consent_to_create'] ?? false)) {
            return $this->validationError(
                'Konfirmasi pembuatan tiket belum tersedia.',
                'MISSING_CREATE_CONSENT',
                ['consent_to_create'],
                $this->formOptions->formOptions(),
                $this->classificationResolver->nextQuestionForField('consent_to_create'),
            );
        }

        $classification = $this->classificationResolver->evaluate($ticketData, $owner);
        if ($classification['issues'] !== []) {
            $firstIssue = $classification['issues'][0];
            $formOptions = $this->formOptions->formOptions($classification['unit']?->id);
            $message = $this->classificationResolver->buildFieldValidationMessage($firstIssue, $formOptions);
            $code = $firstIssue['reason'] === 'not_found'
                ? 'HELPDESK_VALUE_NOT_FOUND'
                : 'HELPDESK_FORM_INCOMPLETE';

            return $this->validationError(
                $message,
                $code,
                array_column($classification['issues'], 'field'),
                $formOptions,
                $this->classificationResolver->nextQuestionForField($firstIssue['field']),
                $classification['issues'],
            );
        }

        $businessEntity = $classification['businessEntity'];
        $unit = $classification['unit'];
        $category = $classification['category'];
        $priority = $classification['priority'];

        $description = $this->buildTicketDescription($ticketData, $actor, $payload);

        $ticket = $this->runAs($owner, fn (): Ticket => Ticket::create([
            'priority_id' => $priority->id,
            'unit_id' => $category->unit_id ?: $unit->id,
            'owner_id' => $owner->id,
            'problem_category_id' => $category->id,
            'title' => Str::limit($title, 250, ''),
            'description' => $description,
            'supporting_attachments' => $this->supportingAttachments($payload['attachments'] ?? []),
            'ticket_statuses_id' => TicketStatus::OPEN,
            'business_entities_id' => $businessEntity->id,
        ]));

        $ticket = $this->loadTicket($ticket);

        return $this->success('ticket_created', "Tiket {$this->ticketNumber($ticket)} berhasil dibuat.", [
            'ticket' => $this->ticketResource($ticket),
        ]);
    }

    /**
     * @return list<string>
     */
    private function actorPhones(array $actor): array
    {
        return array_values(array_unique(array_filter([
            $this->reporterPhone($actor['phone'] ?? ''),
            $this->reporterPhone($actor['identifier'] ?? ''),
            $this->reporterPhone(data_get($actor, 'metadata.phone')),
            $this->reporterPhone(data_get($actor, 'metadata.sender_phone')),
        ])));
    }

    /**
     * @return list<User>
     */
    private function resolveActorUsersByPhone(string $phone): array
    {
        return User::query()
            ->withTrashed()
            ->where('phone_normalized', $phone)
            ->get()
            ->all();
    }

    private function createTalentaReporter(array $employee, string $phone): ?User
    {
        $name = TalentaEmployee::name($employee);
        if ($name === null) {
            return null;
        }

        $email = TalentaEmployee::email($employee);
        if ($email !== '' && User::query()->withTrashed()->where('email', $email)->exists()) {
            $email = '';
        }

        return User::query()->create([
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone,
            'password' => null,
            'identity' => TalentaEmployee::identity($employee),
            'is_active' => true,
        ]);
    }

    private function identityChangedError(): array
    {
        return $this->error(
            'identity_changed',
            'Identitas untuk nomor WhatsApp ini berubah selama laporan diisi. Verifikasi ulang sebelum membuat tiket.',
            'HELPDESK_ACTOR_CHANGED',
        );
    }

    private function validationError(
        string $message,
        string $code,
        array $missingFields,
        array $formOptions,
        ?string $nextQuestion = null,
        array $fieldErrors = [],
    ): array {
        $result = $this->error('validation_error', $message, $code, [
            'missing_fields' => array_values(array_unique($missingFields)),
            'form_options' => $formOptions,
            'field_errors' => array_values($fieldErrors),
        ]);
        $result['next_question'] = $nextQuestion;

        return $result;
    }

    private function ticketData(array $payload): array
    {
        return is_array($payload['ticket_data'] ?? null) ? $payload['ticket_data'] : [];
    }

    private function actor(array $payload): array
    {
        $actor = is_array($payload['actor'] ?? null) ? $payload['actor'] : [];

        if (! isset($actor['identifier'])) {
            $actor['identifier'] = $this->reporterPhone($actor['phone'] ?? '')
                ?? $this->reporterPhone(data_get($actor, 'metadata.phone'))
                ?? $this->reporterPhone(data_get($actor, 'metadata.sender_phone'))
                ?? '';
        }

        return $actor;
    }

    private function buildTicketDescription(array $ticketData, array $actor, array $payload): string
    {
        $items = [
            'Kanal' => $this->reportChannelLabel($actor),
            'Pelapor' => $this->text($actor['name'] ?? '') ?: '-',
            'Nomor kontak' => $this->reporterPhone($actor['phone'] ?? $actor['identifier'] ?? '') ?: '-',
            'Sistem/Perangkat' => $this->text($ticketData['affected_system'] ?? '-'),
            'Dampak' => $this->text($ticketData['impact'] ?? '-'),
            'Urgensi' => $this->text($ticketData['urgency'] ?? 'normal'),
        ];

        foreach ([
            'Lokasi' => 'location',
            'Mulai terjadi' => 'started_at',
            'Pesan error' => 'error_message',
            'Harapan penyelesaian' => 'requested_outcome',
        ] as $label => $field) {
            $value = $this->text($ticketData[$field] ?? '');
            if ($value !== '') {
                $items[$label] = $value;
            }
        }

        $source = $this->text($actor['integration_source'] ?? data_get($actor, 'metadata.integration_source')) ?: 'Helpdesk MCP';
        $html = '<p><strong>Dilaporkan via '.e($source).'</strong></p><ul>';
        foreach ($items as $label => $value) {
            $html .= '<li><strong>'.e($label).':</strong> '.e($value).'</li>';
        }
        $html .= '</ul>';

        $description = $this->text($ticketData['description'] ?? data_get($payload, 'message.text'));
        if ($description !== '') {
            $html .= '<p>'.nl2br(e($description)).'</p>';
        }

        $steps = $ticketData['attempted_steps'] ?? [];
        if (is_array($steps) && $steps !== []) {
            $html .= '<p><strong>Langkah yang sudah dicoba:</strong></p><ul>';
            foreach ($steps as $step) {
                $html .= '<li>'.e($this->text($step)).'</li>';
            }
            $html .= '</ul>';
        }

        $attachmentLinks = $this->attachmentLinks($payload['attachments'] ?? []);
        if ($attachmentLinks !== []) {
            $html .= '<p><strong>Lampiran:</strong></p><ul>';
            foreach ($attachmentLinks as $attachment) {
                $html .= '<li>'.$attachment.'</li>';
            }
            $html .= '</ul>';
        }

        $mediaErrors = $payload['media_errors'] ?? [];
        if (is_array($mediaErrors) && $mediaErrors !== []) {
            $html .= '<p><strong>Catatan media:</strong> '.e(json_encode($mediaErrors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</p>';
        }

        return $html;
    }

    private function supportingAttachments(array $attachments): ?array
    {
        $paths = [];

        foreach (array_slice($attachments, 0, 5) as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $url = $this->text($attachment['url'] ?? $attachment['source_url'] ?? $attachment['public_url'] ?? $attachment['download_url'] ?? '');
            if (HttpsUrl::isValid($url)) {
                $paths[] = $url;
            }
        }

        $paths = array_values(array_unique($paths));

        return $paths === [] ? null : $paths;
    }

    private function attachmentLinks(array $attachments): array
    {
        $links = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $label = $this->text($attachment['filename'] ?? $attachment['id'] ?? $attachment['attachment_id'] ?? 'Lampiran');
            $url = $this->text($attachment['url'] ?? $attachment['source_url'] ?? $attachment['public_url'] ?? $attachment['download_url'] ?? '');
            $mime = $this->text($attachment['mime_type'] ?? $attachment['type'] ?? '');
            $label = trim($label.($mime ? " ({$mime})" : ''));

            if (HttpsUrl::isValid($url)) {
                $links[] = '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a>';
            } elseif ($label !== '') {
                $links[] = e($label);
            }
        }

        return $links;
    }

    private function ticketResource(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_id' => $ticket->id,
            'ticket_number' => $this->ticketNumber($ticket),
            'title' => $ticket->title,
            'status' => $ticket->ticketStatus?->name,
            'status_id' => $ticket->ticket_statuses_id,
            'priority' => $ticket->priority?->name,
            'unit' => $ticket->unit?->name,
            'problem_category' => $ticket->problemCategory?->name,
            'business_entity' => $ticket->businessEntity?->name,
            'supporting_attachments' => $ticket->supporting_attachments ?? [],
            'owner' => $ticket->owner ? [
                'id' => $ticket->owner->id,
                'name' => $ticket->owner->name,
                'needs_profile_completion' => $this->needsProfileCompletion($ticket->owner),
            ] : null,
            'assigned_to' => $ticket->responsible?->name,
            'created_at' => optional($ticket->created_at)->toISOString(),
            'updated_at' => optional($ticket->updated_at)->toISOString(),
            'approved_at' => optional($ticket->approved_at)->toISOString(),
            'solved_at' => optional($ticket->solved_at)->toISOString(),
        ];
    }

    private function ticketNumber(Ticket $ticket): string
    {
        $year = optional($ticket->created_at)->format('Y') ?: now()->format('Y');

        return 'HD-'.$year.'-'.str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT);
    }

    private function loadTicket(?Ticket $ticket): ?Ticket
    {
        return $ticket?->loadMissing([
            'priority',
            'unit',
            'owner',
            'responsible',
            'problemCategory',
            'ticketStatus',
            'businessEntity',
        ]);
    }

    private function needsProfileCompletion(User $user): bool
    {
        return $this->text($user->email) === '' || $this->text($user->password) === '';
    }

    private function reportChannelLabel(array $actor): string
    {
        $channel = Str::lower($this->text($actor['channel'] ?? data_get($actor, 'metadata.channel')));

        return match ($channel) {
            'telegram' => 'Telegram',
            'discord' => 'Discord',
            'web' => 'Web',
            'whatsapp' => 'WhatsApp',
            'mcp' => 'MCP',
            default => Str::headline($channel ?: 'MCP'),
        };
    }

    private function reporterPhone(mixed $value): ?string
    {
        $raw = strtolower($this->text($value));
        if ($raw === '' || str_contains($raw, '@lid')) {
            return null;
        }

        return PhoneNumber::canonical($value);
    }

    private function clientKey(array $payload): string
    {
        return HelpdeskIntegrationClient::currentRequestFingerprint()
            ?? HelpdeskIntegrationClient::persistentKey(
                $this->text($payload['client_id'] ?? ''),
            );
    }

    /**
     * Model observers need the reporter as the temporary actor, but MCP stdio
     * and workers can reuse one application process for multiple reporters.
     */
    private function runAs(User $user, callable $callback): mixed
    {
        $guard = Auth::guard();
        $previousUser = $guard->user();
        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            if ($previousUser) {
                $guard->setUser($previousUser);
            } else {
                $guard->forgetUser();
            }
        }
    }

    private function idempotentPayload(array $payload): array
    {
        unset($payload['idempotency_key']);

        return $payload;
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
            } else {
                ksort($value);
                $value = array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
    }

    private function withIdempotency(array $result, string $key, bool $replayed): array
    {
        if (! is_array($result['data'] ?? null)) {
            $result['data'] = [];
        }

        $result['data']['idempotency'] = [
            'key' => $key,
            'replayed' => $replayed,
        ];

        return $result;
    }

    private function success(string $status, string $message, array $data): array
    {
        return [
            'ok' => true,
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'error' => null,
        ];
    }

    private function error(string $status, string $message, string $code, array $data = []): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(Str::lower($this->text($value)), ['true', 'ya', 'iya', 'yes', 'setuju', 'ok', 'oke'], true);
    }

    private function text(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) ($value ?? ''));
    }
}
