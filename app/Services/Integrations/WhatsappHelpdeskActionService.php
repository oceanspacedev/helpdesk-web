<?php

namespace App\Services\Integrations;

use App\Models\Comment;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WhatsappHelpdeskActionService
{
    private const SUPPORTED_ACTIONS = [
        'helpdesk.create_ticket',
        'helpdesk.get_ticket',
        'helpdesk.add_comment',
    ];

    public function handle(array $payload): array
    {
        $action = $this->text($payload['action'] ?? $payload['route'] ?? data_get($payload, 'system_request.route'));

        if (! in_array($action, self::SUPPORTED_ACTIONS, true)) {
            return $this->error('validation_error', 'Aksi Helpdesk tidak didukung.', 422, 'UNSUPPORTED_HELPDESK_ACTION');
        }

        $idempotencyKey = $this->text($payload['idempotency_key'] ?? '');
        $cacheKey = $idempotencyKey ? 'ita-helpdesk-action:'.sha1($idempotencyKey) : '';

        if ($cacheKey && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            $cached['body']['data']['idempotency'] = [
                'key' => $idempotencyKey,
                'replayed' => true,
            ];

            return $cached;
        }

        $actor = $this->actor($payload);

        if (! $this->bool($actor['is_verified'] ?? false)) {
            return $this->error('unauthorized', 'Identitas WhatsApp belum terverifikasi oleh ITA.', 403, 'UNVERIFIED_ITA_ACTOR');
        }

        $user = $this->resolveActorUser($actor);
        if (! $user && $action === 'helpdesk.create_ticket') {
            $user = $this->createAutoRegisteredReporter($actor);
        }

        if (! $user) {
            return $this->error('unauthorized', 'Nomor WhatsApp belum pernah membuat tiket melalui ITA.', 403, 'HELPDESK_ACTOR_NOT_REGISTERED');
        }

        $result = match ($action) {
            'helpdesk.create_ticket' => $this->createTicket($payload, $actor, $user),
            'helpdesk.get_ticket' => $this->getTicket($payload, $user),
            'helpdesk.add_comment' => $this->addComment($payload, $actor, $user),
        };

        if ($idempotencyKey) {
            $result['body']['data']['idempotency'] = [
                'key' => $idempotencyKey,
                'replayed' => false,
            ];

            if ($result['http_status'] >= 200 && $result['http_status'] < 300 && ($result['body']['ok'] ?? false)) {
                Cache::put($cacheKey, $result, now()->addMinutes((int) config('services.ita_helpdesk.idempotency_ttl_minutes', 15)));
            }
        }

        return $result;
    }

    private function createTicket(array $payload, array $actor, User $owner): array
    {
        $ticketData = $this->ticketData($payload);
        $resolver = app(WhatsappHelpdeskClassificationResolver::class);
        $formOptionsService = app(WhatsappHelpdeskFormOptionsService::class);

        foreach (['issue_summary', 'affected_system', 'impact'] as $field) {
            if ($this->text($ticketData[$field] ?? '') === '') {
                return $this->validationError(
                    "Field {$field} wajib diisi.",
                    'MISSING_TICKET_FIELD',
                    [$field],
                    $formOptionsService->formOptions(),
                    $resolver->nextQuestionForField($field),
                );
            }
        }

        if (! $this->bool($ticketData['consent_to_create'] ?? false)) {
            return $this->validationError(
                'Konfirmasi pembuatan tiket belum tersedia.',
                'MISSING_CREATE_CONSENT',
                ['consent_to_create'],
                $formOptionsService->formOptions(),
                'Buat tiket sekarang?',
            );
        }

        $classification = $resolver->evaluate($ticketData, $owner);
        if ($classification['issues'] !== []) {
            $firstIssue = $classification['issues'][0];
            $formOptions = $formOptionsService->formOptions($classification['unit']?->id);
            $message = $resolver->buildFieldValidationMessage($firstIssue, $formOptions);
            $code = $firstIssue['reason'] === 'not_found'
                ? 'HELPDESK_VALUE_NOT_FOUND'
                : 'HELPDESK_FORM_INCOMPLETE';

            return $this->validationError(
                $message,
                $code,
                array_column($classification['issues'], 'field'),
                $formOptions,
                $resolver->nextQuestionForField($firstIssue['field']),
                $classification['issues'],
            );
        }

        $businessEntity = $classification['businessEntity'];
        $unit = $classification['unit'];
        $category = $classification['category'];
        $priority = $classification['priority'];

        $description = $this->buildTicketDescription($ticketData, $actor, $payload);
        if ($this->text(strip_tags($description)) === '') {
            return $this->validationError(
                'Deskripsi tiket belum tersedia.',
                'MISSING_TICKET_DESCRIPTION',
                ['description'],
                $formOptionsService->formOptions($unit->id),
                'Jelaskan detail kendala atau langkah yang sudah dicoba.',
            );
        }

        Auth::setUser($owner);

        $ticket = Ticket::create([
            'priority_id' => $priority->id,
            'unit_id' => $category->unit_id ?: $unit->id,
            'owner_id' => $owner->id,
            'problem_category_id' => $category->id,
            'title' => Str::limit($this->text($ticketData['title'] ?? $ticketData['issue_summary']), 250, ''),
            'description' => $description,
            'ticket_statuses_id' => TicketStatus::OPEN,
            'business_entities_id' => $businessEntity->id,
        ]);

        $ticket = $this->loadTicket($ticket);

        return $this->success('ticket_created', "Tiket {$this->ticketNumber($ticket)} berhasil dibuat.", [
            'ticket' => $this->ticketResource($ticket),
        ]);
    }

    private function getTicket(array $payload, User $actor): array
    {
        $ticket = $this->findTicket($payload);

        if (! $ticket) {
            return $this->error('not_found', 'Tiket tidak ditemukan.', 404, 'HELPDESK_TICKET_NOT_FOUND');
        }

        if (! $this->canAccessTicket($actor, $ticket)) {
            return $this->error('unauthorized', 'Anda tidak memiliki akses ke tiket ini.', 403, 'HELPDESK_TICKET_FORBIDDEN');
        }

        return $this->success('found', "Tiket {$this->ticketNumber($ticket)} ditemukan.", [
            'ticket' => $this->ticketResource($ticket),
        ]);
    }

    private function addComment(array $payload, array $actor, User $user): array
    {
        $ticket = $this->findTicket($payload);

        if (! $ticket) {
            return $this->error('not_found', 'Tiket tidak ditemukan.', 404, 'HELPDESK_TICKET_NOT_FOUND');
        }

        if (! $this->canAccessTicket($user, $ticket)) {
            return $this->error('unauthorized', 'Anda tidak memiliki akses ke tiket ini.', 403, 'HELPDESK_TICKET_FORBIDDEN');
        }

        $ticketData = $this->ticketData($payload);
        $commentText = $this->text($ticketData['description'] ?? data_get($payload, 'message.text'));

        if ($commentText === '') {
            return $this->error('validation_error', 'Komentar tiket wajib diisi.', 422, 'MISSING_COMMENT');
        }

        $commentText = $this->appendAttachmentText($commentText, $payload['attachments'] ?? []);

        Auth::setUser($user);

        $comment = Comment::create([
            'tiket_id' => $ticket->id,
            'user_id' => $user->id,
            'comment' => $commentText,
        ]);

        return $this->success('comment_added', "Komentar berhasil ditambahkan ke tiket {$this->ticketNumber($ticket)}.", [
            'ticket' => $this->ticketResource($this->loadTicket($ticket)),
            'comment' => [
                'id' => $comment->id,
                'user_id' => $comment->user_id,
                'created_at' => optional($comment->created_at)->toISOString(),
            ],
            'actor' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $this->reporterPhone($actor['phone'] ?? $actor['identifier'] ?? '') ?: '',
            ],
        ]);
    }

    private function resolveActorUser(array $actor): ?User
    {
        $phoneUser = $this->resolveActorUserByPhone($actor);
        if ($phoneUser) {
            return $phoneUser;
        }

        $id = (int) ($actor['helpdesk_user_id'] ?? data_get($actor, 'metadata.helpdesk_user_id') ?? data_get($actor, 'metadata.user_id') ?? 0);
        if ($id > 0) {
            $user = User::query()->whereKey($id)->where('is_active', true)->first();
            if ($user) {
                return $user;
            }
        }

        $email = $this->text($actor['email'] ?? data_get($actor, 'metadata.email'));
        if ($email !== '') {
            $user = User::query()->where('email', $email)->where('is_active', true)->first();
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    private function resolveActorUserByPhone(array $actor): ?User
    {
        $phoneCandidates = array_values(array_unique(array_filter([
            $this->reporterPhone($actor['phone'] ?? ''),
            $this->reporterPhone($actor['identifier'] ?? ''),
            $this->reporterPhone(data_get($actor, 'metadata.phone')),
            $this->reporterPhone(data_get($actor, 'metadata.sender_phone')),
        ])));

        if ($phoneCandidates === []) {
            return null;
        }

        $expanded = [];
        foreach ($phoneCandidates as $phone) {
            $expanded[] = $phone;
            $expanded[] = '+'.$phone;
            if (str_starts_with($phone, '62')) {
                $expanded[] = '0'.substr($phone, 2);
                $expanded[] = substr($phone, 2);
            }
        }

        return User::query()
            ->where('is_active', true)
            ->whereIn('phone', array_values(array_unique(array_filter($expanded))))
            ->first();
    }

    private function createAutoRegisteredReporter(array $actor): ?User
    {
        $phone = $this->reporterPhone($actor['phone'] ?? '')
            ?? $this->reporterPhone($actor['identifier'] ?? '')
            ?? $this->reporterPhone(data_get($actor, 'metadata.phone'))
            ?? $this->reporterPhone(data_get($actor, 'metadata.sender_phone'));
        if (! $phone) {
            return null;
        }

        $existing = User::query()->where('phone', $phone)->first();
        if ($existing) {
            return $existing->is_active ? $existing : null;
        }

        $name = $this->text($actor['name'] ?? data_get($actor, 'metadata.name'));
        if ($name === '') {
            $name = 'Pelapor ITA '.substr($phone, -4);
        }

        return User::query()->create([
            'name' => $name,
            'email' => null,
            'phone' => $phone,
            'password' => null,
            'identity' => null,
            'is_active' => true,
        ]);
    }

    private function validationError(
        string $message,
        string $code,
        array $missingFields,
        array $formOptions,
        ?string $nextQuestion = null,
        array $fieldErrors = [],
    ): array {
        return [
            'http_status' => 422,
            'body' => [
                'ok' => false,
                'status' => 'validation_error',
                'result_status' => 'validation_error',
                'message' => $message,
                'next_question' => $nextQuestion,
                'data' => [
                    'missing_fields' => array_values(array_unique($missingFields)),
                    'form_options' => $formOptions,
                    'field_errors' => array_values($fieldErrors),
                ],
                'count' => 0,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ],
        ];
    }

    private function findTicket(array $payload): ?Ticket
    {
        $ticketRef = is_array($payload['ticket_ref'] ?? null) ? $payload['ticket_ref'] : [];
        $raw = $this->text($ticketRef['ticket_id'] ?? $ticketRef['id'] ?? $payload['ticket_id'] ?? '');

        if ($raw === '') {
            $raw = $this->text($ticketRef['ticket_number'] ?? $ticketRef['number'] ?? $payload['ticket_number'] ?? '');
        }

        $ticketId = $this->extractTicketId($raw);

        return $ticketId ? $this->loadTicket(Ticket::find($ticketId)) : null;
    }

    private function extractTicketId(string $value): ?int
    {
        $value = $this->text($value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/(\d+)\D*$/', $value, $matches)) {
            return (int) ltrim($matches[1], '0') ?: null;
        }

        return null;
    }

    private function canAccessTicket(User $user, Ticket $ticket): bool
    {
        if ((int) $ticket->owner_id === (int) $user->id || (int) $ticket->responsible_id === (int) $user->id) {
            return true;
        }

        return method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['Super Admin', 'Admin Unit', 'Staff Unit', 'Staf Unit']);
    }

    private function ticketData(array $payload): array
    {
        $ticketData = is_array($payload['ticket_data'] ?? null) ? $payload['ticket_data'] : [];
        $usicPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        if ($ticketData === [] && $usicPayload !== []) {
            $ticketData = [
                'title' => $usicPayload['title'] ?? '',
                'issue_summary' => $usicPayload['issue_summary'] ?? $usicPayload['title'] ?? '',
                'description' => $usicPayload['description'] ?? $usicPayload['details'] ?? '',
                'problem_category' => $usicPayload['problem_category'] ?? $usicPayload['category'] ?? '',
                'affected_system' => $usicPayload['affected_system'] ?? '',
                'impact' => $usicPayload['impact'] ?? '',
                'urgency' => $usicPayload['urgency'] ?? 'normal',
                'consent_to_create' => $usicPayload['consent_to_create'] ?? true,
            ];
        }

        return $ticketData;
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
            'Pelapor' => $this->text($actor['name'] ?? '') ?: '-',
            'Nomor WhatsApp' => $this->reporterPhone($actor['phone'] ?? $actor['identifier'] ?? '') ?: '-',
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

        $html = '<p><strong>Dilaporkan via ITA</strong></p><ul>';
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
            $html .= '<p><strong>Lampiran dari ITA:</strong></p><ul>';
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

    private function appendAttachmentText(string $comment, array $attachments): string
    {
        $links = $this->attachmentLinks($attachments, false);
        if ($links === []) {
            return $comment;
        }

        return $comment."\n\nLampiran ITA:\n- ".implode("\n- ", array_map(fn (string $link): string => trim(strip_tags($link)), $links));
    }

    private function attachmentLinks(array $attachments, bool $html = true): array
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

            if ($url !== '') {
                $links[] = $html
                    ? '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a>'
                    : "{$label}: {$url}";
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

    private function reporterPhone(mixed $value): ?string
    {
        $raw = strtolower($this->text($value));
        if ($raw === '' || str_contains($raw, '@lid')) {
            return null;
        }

        return $this->normalizePhone($value);
    }

    private function normalizePhone(mixed $value): ?string
    {
        $phone = $this->text($value);
        if ($phone === '') {
            return null;
        }

        if (str_contains(strtolower($phone), '@lid')) {
            return null;
        }

        $phone = preg_replace('/@.+$/', '', $phone) ?: $phone;
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return strlen($digits) >= 10 ? $digits : null;
    }

    private function success(string $status, string $message, array $data): array
    {
        return [
            'http_status' => 200,
            'body' => [
                'ok' => true,
                'status' => $status,
                'result_status' => $status,
                'message' => $message,
                'data' => $data,
                'count' => isset($data['ticket']) ? 1 : 0,
                'error' => null,
            ],
        ];
    }

    private function error(string $status, string $message, int $httpStatus, string $code): array
    {
        return [
            'http_status' => $httpStatus,
            'body' => [
                'ok' => false,
                'status' => $status,
                'result_status' => $status,
                'message' => $message,
                'data' => null,
                'count' => 0,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
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
