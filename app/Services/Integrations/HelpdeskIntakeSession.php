<?php

namespace App\Services\Integrations;

use App\Models\McpTicketCreationRequest;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\WhatsAppOtpService;
use App\Support\HelpdeskIntakeContract;
use App\Support\HelpdeskIntegrationClient;
use App\Support\HelpdeskReporterName;
use App\Support\HelpdeskWhatsAppMessage;
use App\Support\HttpsUrl;
use App\Support\PhoneNumber;
use App\Support\TalentaEmployee;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class HelpdeskIntakeSession
{
    private const TERMINAL_STATUSES = [
        'ticket_created',
        'cancelled',
    ];

    public const STEP_IDLE = 'idle';

    public const STEP_PHONE = 'phone';

    public const STEP_PHONE_OTP = 'phone_otp';

    public const STEP_REGISTRATION_CONSENT = 'registration_consent';

    public const STEP_REGISTRATION_NAME = 'registration_name';

    public const STEP_ISSUE = 'issue';

    public const STEP_ENTITY = 'entity';

    public const STEP_UNIT = 'unit';

    public const STEP_CATEGORY = 'category';

    public const STEP_PRIORITY = 'priority';

    public const STEP_ATTACHMENTS = 'attachments';

    public const STEP_CONFIRM = 'confirm';

    public function __construct(
        private HelpdeskIntakeGate $gate,
        private HelpdeskClassificationResolver $resolver,
        private HelpdeskOperationalClassifier $operationalClassifier,
        private HelpdeskFormOptionsService $formOptions,
        private HelpdeskTicketCreationService $ticketCreator,
        private HelpdeskMcpConfiguration $configuration,
        private EmployeeService $employees,
        private WhatsAppOtpService $otp,
        private HelpdeskReporterIdentityService $reporterIdentities,
        private HelpdeskReporterRegistrationService $reporterRegistrations,
    ) {}

    /**
     * @param  list<string>  $attachmentUrls
     * @return array{
     *     ok: bool,
     *     status: string,
     *     user_reply: string,
     *     data: array<string, mixed>
     * }
     */
    public function handle(
        string $phone,
        string $message,
        array $attachmentUrls = [],
        string $channel = 'mcp',
        string $reporterName = '',
        array $context = [],
    ): array {
        $normalizedPhone = $this->normalizePhone($phone);
        $channel = $this->normalizeChannel($channel) ?: 'mcp';
        $prepared = $this->prepareContext($context, $channel, $normalizedPhone, $reporterName);
        if (isset($prepared['result'])) {
            return $prepared['result'];
        }

        $context = $prepared['context'];
        $actorKey = (string) $context['actor_key'];

        if (is_array($context['durable_result'] ?? null)) {
            return $context['durable_result'];
        }

        if ($completed = $this->completedResult($actorKey, $channel, $context)) {
            return $completed;
        }

        if ($invalid = $this->validatePayload($message, $attachmentUrls, $context)) {
            return $invalid;
        }

        $lock = Cache::lock('helpdesk-intake-lock:'.$actorKey, 30);
        if (! $lock->get()) {
            $busy = $this->reply('in_progress', false, 'Masih memproses pesan sebelumnya. Kirim ulang sebentar lagi.', [
                'step' => self::STEP_IDLE,
            ]);

            return $this->mcpReply($busy, (string) ($context['intake_id'] ?? ''));
        }

        try {
            return $this->handleLocked(
                $normalizedPhone,
                $message,
                $attachmentUrls,
                $channel,
                $reporterName,
                $actorKey,
                $context,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{context: array<string, mixed>}|array{result: array<string, mixed>}
     */
    private function prepareContext(
        array $context,
        string $channel,
        ?string $phone,
        string $reporterName,
    ): array {
        $externalUserId = trim((string) ($context['external_user_id'] ?? ''));
        if ($channel !== 'mcp' && $externalUserId === ''
            && trim((string) ($context['intake_id'] ?? '')) === '') {
            return ['result' => $this->mcpReply(
                $this->reply('invalid_input', false, 'Gateway kanal eksternal wajib mengirim external_user_id yang stabil.', [
                    'step' => self::STEP_IDLE,
                    'error_code' => 'EXTERNAL_USER_REQUIRED',
                ]),
                '',
            )];
        }

        $context['channel'] = $channel;
        $context['phone'] = $phone ?? '';
        $context['reporter_name'] = $reporterName;
        $session = $this->resolveSession($context, $channel);
        if (isset($session['error'])) {
            return ['result' => $this->mcpReply(
                $this->reply('invalid_session', false, (string) $session['error'], [
                    'step' => self::STEP_IDLE,
                ]),
                (string) ($session['intake_id'] ?? ''),
            )];
        }

        return ['context' => array_merge($context, $session)];
    }

    private function completedResult(string $actorKey, string $channel, array $context): ?array
    {
        $completed = Cache::get($this->completedCacheKey(
            $actorKey,
            $this->contextFingerprint($channel, $context),
        ));
        if (! is_array($completed)) {
            return null;
        }

        $completed['replayed'] = true;

        return $completed;
    }

    /**
     * @param  list<string>  $attachmentUrls
     */
    private function validatePayload(string $message, array $attachmentUrls, array $context): ?array
    {
        if (Str::length($message) > HelpdeskIntakeContract::MAX_MESSAGE_LENGTH) {
            return $this->mcpReply(
                $this->reply('invalid_input', false, 'Pesan terlalu panjang. Maksimum 4000 karakter.', [
                    'step' => self::STEP_IDLE,
                    'error_code' => 'MESSAGE_TOO_LONG',
                ]),
                (string) $context['intake_id'],
            );
        }

        foreach ($attachmentUrls as $url) {
            if (! HttpsUrl::isValid(trim((string) $url))) {
                return $this->mcpReply(
                    $this->reply('invalid_input', false, 'Lampiran harus berupa URL HTTPS yang valid (maksimum 2048 karakter).', [
                        'step' => self::STEP_IDLE,
                        'error_code' => 'INVALID_ATTACHMENT_URL',
                    ]),
                    (string) $context['intake_id'],
                );
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $attachmentUrls
     * @return array<string, mixed>
     */
    private function handleLocked(
        ?string $normalizedPhone,
        string $message,
        array $attachmentUrls,
        string $channel,
        string $reporterName,
        string $actorKey,
        array $context,
    ): array {
        $messageCacheKey = null;
        if (($messageId = $this->messageId($context)) !== '') {
            $messageCacheKey = $this->messageCacheKey(
                $actorKey,
                $messageId,
                $this->contextFingerprint($channel, $context),
            );
            $replayed = Cache::get($messageCacheKey);
            if (is_array($replayed) && is_array($replayed['result'] ?? null)) {
                if (! hash_equals(
                    (string) ($replayed['fingerprint'] ?? ''),
                    $this->messageFingerprint($message, $attachmentUrls, $context),
                )) {
                    return $this->mcpReply(
                        $this->reply('message_conflict', false, 'external_message_id sudah dipakai untuk payload berbeda.', [
                            'step' => self::STEP_IDLE,
                            'error_code' => 'SOURCE_MESSAGE_ID_REUSED',
                        ]),
                        (string) ($context['intake_id'] ?? ''),
                    );
                }

                $replayed = $replayed['result'];
                $replayed['replayed'] = true;

                return $replayed;
            }
        }

        $result = $this->process(
            $normalizedPhone,
            $message,
            $attachmentUrls,
            $channel,
            $reporterName,
            $actorKey,
            $context,
        );

        $result = $this->mcpReply($result, (string) $context['intake_id']);
        $ttl = now()->addMinutes($this->intakeTtlMinutes());
        if (($context['alias_key'] ?? '') !== '' && ($result['status'] ?? '') !== 'invalid_session') {
            Cache::put((string) $context['alias_key'], (string) $context['intake_id'], $ttl);
        }
        if ($messageCacheKey !== null && ($result['status'] ?? '') !== 'invalid_session') {
            Cache::put($messageCacheKey, [
                'fingerprint' => $this->messageFingerprint($message, $attachmentUrls, $context),
                'result' => $result,
            ], $ttl);
        }
        if (in_array($result['status'], self::TERMINAL_STATUSES, true)) {
            Cache::put($this->completedCacheKey(
                $actorKey,
                $this->contextFingerprint($channel, $context),
            ), $result, $ttl);
            if (($context['alias_key'] ?? '') !== '') {
                Cache::forget((string) $context['alias_key']);
            }
        }
        Cache::forget($this->reservationCacheKey($actorKey));

        return $result;
    }

    /**
     * @param  list<string>  $attachmentUrls
     * @return array{ok: bool, status: string, user_reply: string, data: array<string, mixed>}
     */
    private function process(
        ?string $normalizedPhone,
        string $message,
        array $attachmentUrls,
        string $channel,
        string $reporterName,
        string $actorKey,
        array $context = [],
    ): array {
        $message = trim($message);
        $trustedPhone = $normalizedPhone !== null
            && $this->reporterIdentities->assertionIsValid(
                (string) ($context['identity_assertion'] ?? ''),
                (string) ($context['client_id'] ?? ''),
                $channel,
                (string) ($context['external_user_id'] ?? ''),
                $normalizedPhone,
                (string) ($context['external_message_id'] ?? ''),
                (string) ($context['intake_id'] ?? ''),
                $message,
            );
        $incomingVerified = $trustedPhone;
        $state = $this->loadActor($actorKey)
            ?? $this->freshState(
                $normalizedPhone,
                $channel,
                $reporterName,
                $actorKey,
                $incomingVerified,
                $context,
            );
        $storedChannel = trim((string) ($state['channel'] ?? ''));
        if ($storedChannel !== '' && ! hash_equals($storedChannel, $channel)) {
            return $this->reply('invalid_session', false, 'intake_id ini terikat ke kanal yang berbeda. Gunakan intake_id dari kanal yang benar atau mulai intake baru.', [
                'step' => (string) ($state['step'] ?? self::STEP_IDLE),
                'error_code' => 'CHANNEL_CONTEXT_MISMATCH',
            ]);
        }
        $state['channel'] = $channel;
        $state['actor_key'] = $actorKey;
        $storedExternalUser = trim((string) ($state['external_user_id'] ?? ''));
        $incomingExternalUser = trim((string) ($context['external_user_id'] ?? ''));
        if ($storedExternalUser !== '' && $incomingExternalUser === '') {
            return $this->reply('invalid_session', false, 'Gateway wajib mengirim external_user_id yang sama pada setiap lanjutan intake.', [
                'step' => (string) ($state['step'] ?? self::STEP_IDLE),
                'error_code' => 'EXTERNAL_USER_CONTEXT_REQUIRED',
            ]);
        }
        if ($storedExternalUser !== '' && ! hash_equals(
            hash('sha256', $storedExternalUser),
            hash('sha256', $incomingExternalUser),
        )) {
            return $this->reply('invalid_session', false, 'intake_id ini terikat ke user kanal yang berbeda. Gunakan intake_id milik user yang benar atau mulai intake baru.', [
                'step' => (string) ($state['step'] ?? self::STEP_IDLE),
                'error_code' => 'EXTERNAL_USER_CONTEXT_MISMATCH',
            ]);
        }

        $state['intake_id'] = (string) ($context['intake_id'] ?? $state['intake_id'] ?? '');
        $state['client_id'] = (string) ($context['client_id'] ?? $state['client_id'] ?? 'local');
        if ($this->blank($state['external_user_id'] ?? null)) {
            $state['external_user_id'] = (string) ($context['external_user_id'] ?? '');
        }

        if (! ($state['phone_verified'] ?? false) && $this->mayUseReporterBinding($state)) {
            $linked = $this->reporterIdentities->linkedUser(
                (string) $state['client_id'],
                $channel,
                (string) ($state['external_user_id'] ?? ''),
            );
            if ($linked) {
                $state['phone'] = (string) $this->normalizePhone((string) $linked->phone);
                $state['phone_verified'] = true;
                $state['name'] = (string) $linked->name;
                $state['email'] = (string) ($linked->email ?? '');
                $state['identity'] = (string) ($linked->identity ?? '');
                $state['identity_source'] = 'verified_binding';
                $state['verification_method'] = 'existing_binding';
                $state['helpdesk_user_id'] = $linked->id;
            }
        }
        $storedPhone = $this->normalizePhone((string) ($state['phone'] ?? ''));
        if ($incomingVerified && $normalizedPhone !== null && $storedPhone !== null
            && ! hash_equals($storedPhone, $normalizedPhone)) {
            $state = $this->freshState(
                $normalizedPhone,
                $channel,
                $reporterName,
                $actorKey,
                true,
                $context,
            );
        }
        if ($normalizedPhone) {
            if ($incomingVerified) {
                $state['phone'] = $normalizedPhone;
                $state['phone_verified'] = true;
                $state['verification_method'] = 'trusted_whatsapp_sender';
            } elseif (($state['phone'] ?? '') === '') {
                $state['phone'] = $normalizedPhone;
            }
        }
        if ($reporterName !== '') {
            $state['display_name'] = $reporterName;
        }
        $state['attachments'] = $this->mergeAttachments($state['attachments'] ?? [], $attachmentUrls);

        if ($this->isCancel($message) && ($state['step'] ?? self::STEP_IDLE) !== self::STEP_IDLE) {
            $this->clearState($state);

            return $this->reply('cancelled', true, "Laporan dibatalkan, belum ada tiket.\n\nKetik *lapor* untuk mulai lagi.", [
                'step' => self::STEP_IDLE,
            ]);
        }

        $result = match ($state['step'] ?? self::STEP_IDLE) {
            self::STEP_IDLE => $this->fromIdle($state, $message, (bool) ($context['start'] ?? false)),
            self::STEP_PHONE => $this->fromPhone($state, $message),
            self::STEP_PHONE_OTP => $this->fromPhoneOtp($state, $message),
            self::STEP_REGISTRATION_CONSENT => $this->fromRegistrationConsent($state, $message),
            self::STEP_REGISTRATION_NAME => $this->fromRegistrationName($state, $message),
            self::STEP_ISSUE => $this->fromIssue($state, $message),
            self::STEP_ENTITY => $this->fromClassification($state, $message, 'business_entities_id', self::STEP_ENTITY),
            self::STEP_UNIT => $this->fromClassification($state, $message, 'unit_id', self::STEP_UNIT),
            self::STEP_CATEGORY => $this->fromClassification($state, $message, 'problem_category_id', self::STEP_CATEGORY),
            self::STEP_PRIORITY => $this->fromClassification($state, $message, 'priority_id', self::STEP_PRIORITY),
            self::STEP_ATTACHMENTS => $this->fromAttachments($state, $message),
            self::STEP_CONFIRM => $this->fromConfirm($state, $message),
            default => $this->fromIdle(
                $this->freshState($normalizedPhone, $channel, $reporterName, $actorKey, $incomingVerified, $context),
                $message,
                (bool) ($context['start'] ?? false),
            ),
        };

        $next = $result['data']['state'] ?? $state;
        if (in_array($result['status'], self::TERMINAL_STATUSES, true)) {
            $this->clearState($next);
        } else {
            $this->storeState($next);
        }

        unset($result['data']['state']);

        return $result;
    }

    private function fromIdle(array $state, string $message, bool $forceStart = false): array
    {
        $detected = $this->gate->detectAtStart($message);
        if ($forceStart && $detected === null) {
            $detected = [
                'trigger' => 'tool_intent',
                'remainder' => $message,
            ];
        }
        if ($detected === null) {
            return $this->reply('idle', true, $this->startPrompt(), [
                'step' => self::STEP_IDLE,
                'state' => $state,
            ]);
        }

        $state['started_at'] = now()->toIso8601String();
        $state['pending_issue'] = $detected['remainder'];

        if ($state['phone_verified'] ?? false) {
            return $this->afterPhoneKnown($state);
        }

        if ($this->blank($state['phone'] ?? null)) {
            $state['step'] = self::STEP_PHONE;

            return $this->reply('in_progress', true, $this->withGuide(self::STEP_PHONE, $this->phonePrompt($state['channel'] ?? '')), [
                'step' => self::STEP_PHONE,
                'state' => $state,
            ]);
        }

        return $this->issuePhoneOtp($state);
    }

    private function fromPhone(array $state, string $message): array
    {
        $phone = $this->normalizePhone($message);
        if (! $phone) {
            return $this->reply('in_progress', true, $this->withGuide(self::STEP_PHONE, "Nomor belum bisa dipakai.\n\n".$this->phonePrompt($state['channel'] ?? '')), [
                'step' => self::STEP_PHONE,
                'state' => $state,
            ]);
        }

        $state['phone'] = $phone;
        $state['phone_verified'] = false;
        $state = $this->clearResolvedIdentity($state);

        return $this->issuePhoneOtp($state);
    }

    private function issuePhoneOtp(array $state, bool $rotate = false): array
    {
        $tenant = $this->otpTenant($state);
        $issued = $this->otp->issue(
            'helpdesk-intake',
            $this->otpSubject($state),
            $this->otpPrincipal($state),
            (string) ($state['phone'] ?? ''),
            $rotate,
            $tenant,
        );

        if (! ($issued['ok'] ?? false)) {
            $status = (string) ($issued['status'] ?? 'delivery_failed');
            $retryAfter = (int) ($issued['retry_after'] ?? 0);
            $message = match ($status) {
                'rate_limited' => 'Terlalu banyak permintaan OTP. Coba lagi dalam '.max(1, $retryAfter).' detik.',
                'busy' => 'Permintaan OTP sebelumnya masih diproses. Coba lagi sebentar.',
                'invalid_phone' => 'Nomor WhatsApp tidak valid. Ketik ulang nomor 08... atau 62....',
                default => 'Kode belum bisa dikirim ke WhatsApp. Periksa nomornya atau coba lagi sebentar.',
            };
            $state['step'] = self::STEP_PHONE;

            return $this->reply('in_progress', false, $this->withGuide(self::STEP_PHONE, $message), [
                'step' => self::STEP_PHONE,
                'state' => $state,
                'error_code' => match ($status) {
                    'rate_limited' => 'OTP_RATE_LIMITED',
                    'invalid_phone' => 'INVALID_PHONE',
                    'busy' => 'OTP_BUSY',
                    default => 'OTP_DELIVERY_FAILED',
                },
            ]);
        }

        $state['otp_challenge_id'] = (string) $issued['challenge_id'];
        $state['otp_expires_at'] = (string) $issued['expires_at'];
        $state['step'] = self::STEP_PHONE_OTP;
        $masked = $this->maskedPhone((string) $state['phone']);

        return $this->reply('in_progress', true, $this->withGuide(
            self::STEP_PHONE_OTP,
            "Kode 6 digit sudah dikirim ke WhatsApp *{$masked}*. Ketik kode itu di sini.\n"
                .'Jika kedaluwarsa, ketik *kirim ulang*.',
        ), [
            'step' => self::STEP_PHONE_OTP,
            'state' => $state,
        ]);
    }

    private function fromPhoneOtp(array $state, string $message): array
    {
        $normalized = Str::lower(trim($message));
        if (in_array($normalized, ['kirim ulang', 'resend', 'ulang otp', 'kirim otp'], true)) {
            return $this->issuePhoneOtp($state, true);
        }

        $verified = $this->otp->verify(
            'helpdesk-intake',
            $this->otpSubject($state),
            $this->otpPrincipal($state),
            (string) ($state['otp_challenge_id'] ?? ''),
            $message,
        );
        if (! ($verified['ok'] ?? false)) {
            $status = (string) ($verified['status'] ?? 'invalid');
            $retryAfter = (int) ($verified['retry_after'] ?? 0);
            $message = match ($status) {
                'rate_limited' => 'Terlalu banyak kode yang salah. Coba lagi dalam '.max(1, $retryAfter).' detik.',
                'expired' => 'Kode tidak tersedia atau sudah kedaluwarsa. Ketik *kirim ulang* untuk kode baru.',
                'busy' => 'Kode sedang diverifikasi. Coba lagi sebentar.',
                default => 'Kode OTP salah. Periksa 6 digit yang dikirim ke WhatsApp.',
            };

            return $this->reply('in_progress', false, $this->withGuide(self::STEP_PHONE_OTP, $message), [
                'step' => self::STEP_PHONE_OTP,
                'state' => $state,
                'error_code' => match ($status) {
                    'rate_limited' => 'OTP_RATE_LIMITED',
                    'expired' => 'OTP_EXPIRED',
                    'busy' => 'OTP_BUSY',
                    default => 'OTP_INVALID',
                },
            ]);
        }

        $phone = $this->normalizePhone((string) ($verified['phone'] ?? ''));
        if ($phone === null || ! hash_equals((string) ($state['phone'] ?? ''), $phone)) {
            $state['step'] = self::STEP_PHONE;
            $state['phone'] = '';
            $state['phone_verified'] = false;

            return $this->reply('in_progress', false, $this->withGuide(self::STEP_PHONE, $this->phonePrompt((string) ($state['channel'] ?? ''))), [
                'step' => self::STEP_PHONE,
                'state' => $state,
                'error_code' => 'OTP_PHONE_MISMATCH',
            ]);
        }

        $state['phone_verified'] = true;
        $state['verification_method'] = 'whatsapp_otp';
        unset($state['otp_challenge_id'], $state['otp_expires_at']);

        return $this->afterPhoneKnown($state);
    }

    private function otpPrincipal(array $state): string
    {
        $clientId = $this->otpTenant($state);
        $externalUserId = trim((string) ($state['external_user_id'] ?? ''));
        $subject = $externalUserId !== ''
            ? (string) ($state['channel'] ?? 'mcp')."\0".$externalUserId
            : 'intake'."\0".$this->otpSubject($state);

        return 'reporter:'.hash('sha256', $clientId."\0".$subject);
    }

    private function otpSubject(array $state): string
    {
        return trim((string) ($state['intake_id'] ?? ''));
    }

    private function otpTenant(array $state): string
    {
        $clientId = trim((string) ($state['client_id'] ?? ''));

        return $clientId !== '' ? $clientId : 'local';
    }

    private function afterPhoneKnown(array $state): array
    {
        if (! ($state['phone_verified'] ?? false)) {
            return $this->issuePhoneOtp($state);
        }

        $identity = $this->identifyReporter((string) $state['phone']);
        if ($identity['blocked']) {
            $blockMessage = ($identity['block_code'] ?? '') === 'TALENTA_IDENTITY_INVALID'
                ? 'Nomor itu ditemukan di Talenta, tetapi nama karyawannya belum lengkap sehingga owner tiket tidak dapat ditentukan. Hubungi administrator atau ketik nomor WhatsApp lain.'
                : 'Nomor itu terdaftar, tetapi akun Helpdesk-nya tidak aktif. Hubungi administrator Helpdesk atau ketik nomor WhatsApp lain yang terdaftar atas nama Anda.';
            $state['phone'] = '';
            $state['phone_verified'] = false;
            $state['verification_method'] = '';
            $state = $this->clearResolvedIdentity($state);
            $state['step'] = self::STEP_PHONE;

            return $this->reply('in_progress', false, $this->withGuide(self::STEP_PHONE, $blockMessage), [
                'step' => self::STEP_PHONE,
                'state' => $state,
                'error_code' => $identity['block_code'],
            ]);
        }
        if ($identity['ambiguous']) {
            $source = ($identity['ambiguity_source'] ?? '') === 'helpdesk'
                ? 'akun Helpdesk'
                : 'data karyawan Talenta';
            $errorCode = ($identity['ambiguity_source'] ?? '') === 'helpdesk'
                ? 'HELPDESK_PHONE_AMBIGUOUS'
                : 'TALENTA_PHONE_AMBIGUOUS';
            $state['phone'] = '';
            $state['phone_verified'] = false;
            $state['verification_method'] = '';
            $state = $this->clearResolvedIdentity($state);
            $state['step'] = self::STEP_PHONE;

            return $this->reply('in_progress', false, $this->withGuide(self::STEP_PHONE, "Nomor itu cocok dengan lebih dari satu {$source}, sehingga owner tiket tidak dapat ditentukan. Hubungi administrator untuk memperbaiki data atau ketik nomor WhatsApp lain."), [
                'step' => self::STEP_PHONE,
                'state' => $state,
                'error_code' => $errorCode,
            ]);
        }
        if ($identity['known']) {
            $state['name'] = $identity['name'];
            $state['email'] = $identity['email'];
            $state['identity_source'] = $identity['source'];
            $state['identity'] = $identity['identity'];
            $state['helpdesk_user_id'] = $identity['helpdesk_user_id'];
            $state['identity_fingerprint'] = $identity['identity_fingerprint'];

            return $this->continueAfterIdentity(
                $state,
                'Halo *'.$identity['name'].'*. Tiket akan tercatat atas nama Anda.',
            );
        }

        return $this->offerInlineRegistration($state);
    }

    private function offerInlineRegistration(array $state, bool $resumeTicketDraft = false): array
    {
        if (! ($state['phone_verified'] ?? false)) {
            return $this->issuePhoneOtp($state);
        }

        // Names supplied by an AI client are display hints only. Registration
        // always requires an explicit consent followed by a newly typed name.
        $state = $this->clearResolvedIdentity($state);
        if ($resumeTicketDraft) {
            $state['resume_ticket_draft'] = true;
        }
        $state['registration_consented'] = false;
        $state['step'] = self::STEP_REGISTRATION_CONSENT;

        return $this->reply('in_progress', true, $this->withGuide(
            self::STEP_REGISTRATION_CONSENT,
            "Nomor WhatsApp *{$this->maskedPhone((string) ($state['phone'] ?? ''))}* sudah terverifikasi, tetapi belum ada di Helpdesk atau Talenta.\n\nBoleh kami buatkan akun Helpdesk khusus pelapor dengan nomor ini? Akun dibuat tanpa email dan password agar laporan dapat dilanjutkan.\n\nKetik *ya* untuk lanjut, atau *tidak* untuk membatalkan laporan.",
        ), [
            'step' => self::STEP_REGISTRATION_CONSENT,
            'state' => $state,
        ]);
    }

    private function fromRegistrationConsent(array $state, string $message): array
    {
        if (! ($state['phone_verified'] ?? false)) {
            return $this->resumePhoneVerification($state);
        }

        if ($this->isNo($message)) {
            return $this->reply(
                'cancelled',
                true,
                "Pembuatan akun dan laporan dibatalkan. Tidak ada akun atau tiket yang dibuat.\n\nKetik *lapor* jika ingin mulai lagi.",
                ['step' => self::STEP_IDLE],
            );
        }

        if (! $this->isYes($message)) {
            return $this->reply('in_progress', true, $this->withGuide(
                self::STEP_REGISTRATION_CONSENT,
                'Ketik *ya* jika Anda setuju dibuatkan akun Helpdesk phone-only agar laporan dapat dilanjutkan, atau *tidak* untuk membatalkan laporan.',
            ), [
                'step' => self::STEP_REGISTRATION_CONSENT,
                'state' => $state,
            ]);
        }

        $state['registration_consented'] = true;
        $state['name'] = '';
        $state['step'] = self::STEP_REGISTRATION_NAME;

        return $this->reply('in_progress', true, $this->withGuide(
            self::STEP_REGISTRATION_NAME,
            'Ketik nama lengkap Anda. Nama harus Anda isi sendiri dan akan disimpan sebagai nama pelapor Helpdesk.',
        ), [
            'step' => self::STEP_REGISTRATION_NAME,
            'state' => $state,
        ]);
    }

    private function fromRegistrationName(array $state, string $message): array
    {
        if (! ($state['phone_verified'] ?? false)) {
            return $this->resumePhoneVerification($state);
        }
        if (! ($state['registration_consented'] ?? false)) {
            return $this->offerInlineRegistration($state);
        }

        $name = $this->cleanName($message);
        if ($name === null) {
            return $this->reply('in_progress', true, $this->withGuide(
                self::STEP_REGISTRATION_NAME,
                'Nama belum bisa dipakai. Ketik nama lengkap (huruf, minimal 3 karakter), bukan nomor atau kata "ya".',
            ), [
                'step' => self::STEP_REGISTRATION_NAME,
                'state' => $state,
            ]);
        }

        $registered = $this->reporterRegistrations->createPhoneOnlyForVerifiedPhone(
            (string) ($state['phone'] ?? ''),
            $name,
        );
        $user = $registered['user'] ?? null;
        if (($registered['ok'] ?? false) && $user instanceof User) {
            $registeredPhone = $this->normalizePhone((string) $user->phone);
            if ($registeredPhone === null
                || ! hash_equals((string) ($state['phone'] ?? ''), $registeredPhone)) {
                return $this->restartPhoneVerification(
                    $state,
                    'Data nomor akun berubah selama pendaftaran. Verifikasi ulang nomor WhatsApp Anda.',
                    'REGISTRATION_PHONE_MISMATCH',
                );
            }

            $state['name'] = (string) $user->name;
            $state['email'] = (string) ($user->email ?? '');
            $state['identity'] = (string) ($user->identity ?? '');
            $state['identity_source'] = ($registered['created'] ?? false)
                ? 'mcp_inline_registration'
                : 'registered';
            $state['helpdesk_user_id'] = $user->id;
            $state['identity_fingerprint'] = '';
            unset($state['registration_consented']);

            if ($this->mayUseReporterBinding($state)) {
                $this->reporterIdentities->bind(
                    (string) ($state['client_id'] ?? 'local'),
                    (string) ($state['channel'] ?? 'mcp'),
                    (string) ($state['external_user_id'] ?? ''),
                    $user,
                    (string) ($state['verification_method'] ?? 'whatsapp_otp'),
                );
            }

            $preamble = ($registered['created'] ?? false)
                ? 'Akun Helpdesk phone-only untuk *'.$user->name.'* sudah dibuat. Laporan ini dilanjutkan.'
                : 'Akun Helpdesk untuk nomor ini sudah tersedia atas nama *'.$user->name.'*. Laporan ini dilanjutkan.';

            return $this->continueAfterIdentity($state, $preamble);
        }

        $status = (string) ($registered['status'] ?? 'conflict');
        if ($status === 'invalid_name' || $status === 'busy') {
            $state['step'] = self::STEP_REGISTRATION_NAME;

            return $this->reply('in_progress', false, $this->withGuide(
                self::STEP_REGISTRATION_NAME,
                (string) ($registered['message'] ?? 'Pendaftaran belum dapat diproses. Ketik nama lengkap Anda lagi.'),
            ), [
                'step' => self::STEP_REGISTRATION_NAME,
                'state' => $state,
                'error_code' => strtoupper($status),
            ]);
        }

        if (in_array($status, [
            'directory_changed',
            'helpdesk_inactive',
            'helpdesk_ambiguous',
            'talenta_ambiguous',
        ], true)) {
            unset($state['registration_consented']);

            return $this->afterPhoneKnown($state);
        }

        return $this->restartPhoneVerification(
            $state,
            (string) ($registered['message'] ?? 'Data nomor berubah selama pendaftaran. Verifikasi ulang nomor WhatsApp Anda.'),
            'REGISTRATION_CONFLICT',
        );
    }

    private function resumePhoneVerification(array $state): array
    {
        if ($this->blank($state['phone'] ?? null)) {
            $state['step'] = self::STEP_PHONE;

            return $this->reply('in_progress', false, $this->withGuide(
                self::STEP_PHONE,
                $this->phonePrompt((string) ($state['channel'] ?? '')),
            ), [
                'step' => self::STEP_PHONE,
                'state' => $state,
            ]);
        }

        return $this->issuePhoneOtp($state);
    }

    private function clearResolvedIdentity(array $state): array
    {
        $state['name'] = '';
        $state['email'] = '';
        $state['identity'] = '';
        $state['identity_source'] = 'unknown';
        $state['helpdesk_user_id'] = null;
        $state['identity_fingerprint'] = '';
        unset($state['registration_consented']);

        return $state;
    }

    private function restartPhoneVerification(array $state, string $message, string $errorCode): array
    {
        $state['phone'] = '';
        $state['phone_verified'] = false;
        $state['verification_method'] = '';
        $state = $this->clearResolvedIdentity($state);
        $state['step'] = self::STEP_PHONE;

        return $this->reply('in_progress', false, $this->withGuide(
            self::STEP_PHONE,
            $message."\n\n".$this->phonePrompt((string) ($state['channel'] ?? '')),
        ), [
            'step' => self::STEP_PHONE,
            'state' => $state,
            'error_code' => $errorCode,
        ]);
    }

    private function continueAfterIdentity(array $state, string $preamble): array
    {
        if ($state['resume_ticket_draft'] ?? false) {
            unset($state['resume_ticket_draft']);
            $result = $this->goConfirm($state);
            $result['user_reply'] = $preamble."\n\n".$result['user_reply'];

            return $result;
        }

        $pending = trim((string) ($state['pending_issue'] ?? ''));
        $state['pending_issue'] = '';
        $state['step'] = self::STEP_ISSUE;

        if ($pending !== '') {
            $result = $this->captureIssue($state, $pending);
            $result['user_reply'] = $preamble."\n\n".$result['user_reply'];

            return $result;
        }

        return $this->reply('in_progress', true, $preamble."\n\n".$this->withGuide(self::STEP_ISSUE, "Tulis kendalanya seperti tiket Helpdesk.\nContoh: printer kasir tidak bisa mencetak sejak pagi."), [
            'step' => self::STEP_ISSUE,
            'state' => $state,
        ]);
    }

    private function fromIssue(array $state, string $message): array
    {
        if ($this->usableIssueText($message) === null) {
            return $this->askForIssue($state);
        }

        return $this->captureIssue($state, $message);
    }

    private function captureIssue(array $state, string $message): array
    {
        if ($this->usableIssueText($message) === null) {
            return $this->askForIssue($state);
        }

        $state = array_merge($state, $this->operationalClassifier->classify($message, $state));

        return $this->advance($state);
    }

    private function askForIssue(array $state): array
    {
        $state['step'] = self::STEP_ISSUE;

        return $this->reply('in_progress', true, $this->withGuide(
            self::STEP_ISSUE,
            "Deskripsi masih belum bisa dipakai. Kirim teks kendala apa adanya, bukan ringkasan atau nomor pilihan.\nContoh: printer kasir tidak bisa mencetak sejak pagi.",
        ), [
            'step' => self::STEP_ISSUE,
            'state' => $state,
        ]);
    }

    private function fromClassification(array $state, string $message, string $field, string $step): array
    {
        $tokens = preg_split('/[\s,;]+/u', trim($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return $this->askClassification($state, $field, $step);
        }

        $sequence = array_column($this->missingClassificationFields($state), 'field');
        if ($sequence === [] || $sequence[0] !== $field) {
            $sequence = [$field, ...array_values(array_filter($sequence, fn (string $item): bool => $item !== $field))];
        }

        $noted = [];
        foreach (array_values($tokens) as $index => $token) {
            if (! isset($sequence[$index])) {
                break;
            }

            $target = $sequence[$index];
            $targetStep = $this->stepForClassificationField($target);
            $result = $this->resolver->validateField($target, $token, $this->ticketContext($state));
            if (! $result['ok']) {
                $state['step'] = $targetStep;

                return $this->reply('in_progress', true, $this->withGuide($targetStep, $result['message']), [
                    'step' => $targetStep,
                    'state' => $state,
                    'field' => $target,
                ]);
            }

            $state = array_merge($state, $result['resolved']['ticket_data_patch'] ?? []);
            $picked = trim((string) ($result['resolved']['name'] ?? ''));
            if ($picked !== '') {
                $noted[] = $this->shortFieldLabel($target).' *'.$picked.'*';
            }
        }

        $next = $this->advance($state);
        if ($noted !== []) {
            $next['user_reply'] = 'Tercatat: '.implode(', ', $noted).".\n\n".$next['user_reply'];
        }

        return $next;
    }

    private function fromAttachments(array $state, string $message): array
    {
        if ($this->isSkip($message) || ($state['attachments'] ?? []) !== []) {
            return $this->goConfirm($state);
        }

        return $this->reply('in_progress', true, $this->withGuide(self::STEP_ATTACHMENTS, 'Kirim foto atau file, atau ketik *lewati* kalau tidak ada lampiran.'), [
            'step' => self::STEP_ATTACHMENTS,
            'state' => $state,
        ]);
    }

    private function fromConfirm(array $state, string $message): array
    {
        if ($this->isNo($message)) {
            $state['step'] = self::STEP_ISSUE;
            $state['title'] = '';
            $state['description'] = '';
            $state['location'] = '';
            $state['affected_system'] = '';

            return $this->reply('in_progress', true, $this->withGuide(self::STEP_ISSUE, "Oke, ganti ceritanya saja.\nKetik ulang kendalanya."), [
                'step' => self::STEP_ISSUE,
                'state' => $state,
            ]);
        }

        if ($this->isYes($message)) {
            if ($identityError = $this->confirmationIdentityError($state)) {
                return $identityError;
            }

            return $this->submitTicket($state);
        }

        if ($this->looksLikeHostNoise($message) || $this->isLoneMenuChoice($message)) {
            return $this->reply('in_progress', true, $this->withGuide(self::STEP_CONFIRM, $this->confirmFollowUp($state)), [
                'step' => self::STEP_CONFIRM,
                'state' => $state,
            ]);
        }

        if ($this->looksLikeIssueUpdate($message)) {
            return $this->captureIssue($state, $message);
        }

        return $this->reply('in_progress', true, $this->withGuide(self::STEP_CONFIRM, $this->confirmFollowUp($state)), [
            'step' => self::STEP_CONFIRM,
            'state' => $state,
        ]);
    }

    private function confirmationIdentityError(array $state): ?array
    {
        if (($state['verification_method'] ?? '') === 'existing_binding') {
            $linked = $this->reporterIdentities->linkedUser(
                (string) ($state['client_id'] ?? 'local'),
                (string) ($state['channel'] ?? 'mcp'),
                (string) ($state['external_user_id'] ?? ''),
            );
            $linkedPhone = $this->normalizePhone((string) ($linked?->phone ?? ''));
            if (! $linked || $linkedPhone === null
                || ! hash_equals((string) ($state['phone'] ?? ''), $linkedPhone)) {
                $state['phone'] = '';
                $state['phone_verified'] = false;
                $state['verification_method'] = '';
                $state['step'] = self::STEP_PHONE;

                return $this->reply('in_progress', false, $this->withGuide(self::STEP_PHONE, 'Tautan identitas kanal ini sudah berubah atau tidak aktif. Verifikasi ulang nomor WhatsApp Anda.'), [
                    'step' => self::STEP_PHONE,
                    'state' => $state,
                    'error_code' => 'IDENTITY_BINDING_STALE',
                ]);
            }
        }

        if ($this->blank($state['phone'] ?? null) || ! ($state['phone_verified'] ?? false)) {
            $state['step'] = self::STEP_PHONE;

            return $this->reply('in_progress', false, $this->withGuide(self::STEP_PHONE, 'Nomor WhatsApp pelapor belum terverifikasi. '.$this->phonePrompt((string) ($state['channel'] ?? ''))), [
                'step' => self::STEP_PHONE,
                'state' => $state,
            ]);
        }

        if ($this->blank($state['name'] ?? null) || $this->blank($state['phone'] ?? null)) {
            return $this->offerInlineRegistration($state, true);
        }

        return null;
    }

    private function submitTicket(array $state): array
    {
        $result = $this->ticketCreator->create($this->ticketCreationPayload($state));
        if (! ($result['ok'] ?? false)) {
            $errorCode = (string) data_get($result, 'error.code');
            if ($errorCode === 'HELPDESK_ACTOR_CHANGED') {
                return $this->restartPhoneVerification(
                    $state,
                    (string) ($result['message'] ?? 'Identitas nomor berubah selama laporan diisi. Verifikasi ulang.'),
                    'HELPDESK_ACTOR_CHANGED',
                );
            }
            if ($errorCode === 'HELPDESK_REPORTER_ACCOUNT_REQUIRED') {
                return $this->offerInlineRegistration($state, true);
            }

            return $this->reply('in_progress', false, $this->withGuide(self::STEP_CONFIRM, (string) ($result['message'] ?? 'Gagal menyimpan. Ketik *ya* untuk coba lagi.')), [
                'step' => self::STEP_CONFIRM,
                'state' => $state,
            ]);
        }

        $ticket = $result['data']['ticket'] ?? [];
        $this->bindReporterAfterTicketCreation($state, $ticket);
        $number = $ticket['ticket_number'] ?? ('#'.($ticket['id'] ?? ''));

        return $this->reply('ticket_created', true, HelpdeskWhatsAppMessage::compose(
            'Laporan diterima',
            (string) $number,
            (string) ($state['title'] ?? ''),
            footer: 'Tim akan menindaklanjuti. Ketik *lapor* untuk laporan baru.',
        ), [
            'step' => self::STEP_IDLE,
            'ticket' => $ticket,
        ]);
    }

    private function ticketCreationPayload(array $state): array
    {
        return [
            'client_id' => (string) ($state['client_id'] ?? ''),
            'idempotency_key' => $this->idempotencyKey($state),
            'actor' => [
                'name' => $state['name'] ?? '',
                'phone' => $state['phone'] ?? '',
                'email' => $state['email'] ?? '',
                'identity' => $state['identity'] ?? '',
                'is_verified' => (bool) ($state['phone_verified'] ?? false),
                'channel' => $state['channel'] ?? 'mcp',
                'integration_source' => 'Helpdesk MCP',
                'metadata' => [
                    'identity_source' => $state['identity_source'] ?? 'declared',
                    'identity_confirmed' => (bool) ($state['phone_verified'] ?? false),
                    'expected_helpdesk_user_id' => (int) ($state['helpdesk_user_id'] ?? 0),
                    'expected_talenta_fingerprint' => (string) (
                        ($state['identity_source'] ?? '') === 'employee'
                            ? ($state['identity_fingerprint'] ?? '')
                            : ''
                    ),
                    'channel' => $state['channel'] ?? '',
                ],
            ],
            'ticket_data' => [
                'title' => $state['title'],
                'issue_summary' => $state['title'],
                'description' => $state['description'],
                'consent_to_create' => true,
                'business_entities_id' => $state['business_entities_id'] ?? '',
                'business_entity' => $state['business_entity'] ?? '',
                'unit_id' => $state['unit_id'] ?? '',
                'unit' => $state['unit'] ?? '',
                'problem_category_id' => $state['problem_category_id'] ?? '',
                'problem_category' => $state['problem_category'] ?? '',
                'priority_id' => $state['priority_id'] ?? '',
                'priority' => $state['priority'] ?? '',
                'location' => $state['location'] ?? '',
                'affected_system' => $state['affected_system'] ?? '',
            ],
            'attachments' => array_map(fn (string $url): array => ['url' => $url], $state['attachments'] ?? []),
        ];
    }

    private function bindReporterAfterTicketCreation(array $state, array $ticket): void
    {
        if (($state['verification_method'] ?? '') !== 'existing_binding'
            && $this->mayUseReporterBinding($state)
            && ($ownerId = (int) data_get($ticket, 'owner.id')) > 0) {
            $owner = User::query()->whereKey($ownerId)->where('is_active', true)->first();
            if ($owner) {
                $this->reporterIdentities->bind(
                    (string) ($state['client_id'] ?? 'local'),
                    (string) ($state['channel'] ?? 'mcp'),
                    (string) ($state['external_user_id'] ?? ''),
                    $owner,
                    (string) ($state['verification_method'] ?? 'whatsapp_otp'),
                );
            }
        }
    }

    private function advance(array $state): array
    {
        return $this->goConfirm($state);
    }

    private function askClassification(array $state, string $field, string $step): array
    {
        $missing = $this->missingClassificationFields($state);
        $prompt = $this->classificationPrompt($state, $missing !== [] ? $missing : [['field' => $field, 'step' => $step]]);

        return $this->reply('in_progress', true, $this->withGuide($step, $prompt), [
            'step' => $step,
            'state' => $state,
            'field' => $field,
        ]);
    }

    /**
     * @return list<array{field: string, step: string}>
     */
    private function missingClassificationFields(array $state): array
    {
        $fields = [
            ['field' => 'unit_id', 'name' => 'unit', 'step' => self::STEP_UNIT],
            ['field' => 'problem_category_id', 'name' => 'problem_category', 'step' => self::STEP_CATEGORY],
            ['field' => 'priority_id', 'name' => 'priority', 'step' => self::STEP_PRIORITY],
            ['field' => 'business_entities_id', 'name' => 'business_entity', 'step' => self::STEP_ENTITY],
        ];

        $missing = [];
        foreach ($fields as $meta) {
            if ($this->blank($state[$meta['field']] ?? null) && $this->blank($state[$meta['name']] ?? null)) {
                $missing[] = ['field' => $meta['field'], 'step' => $meta['step']];
            }
        }

        return $missing;
    }

    /**
     * @param  list<array{field: string, step: string}>  $missing
     */
    private function classificationPrompt(array $state, array $missing): string
    {
        $unitId = (int) ($state['unit_id'] ?? 0);
        $options = $this->formOptions->formOptions($unitId > 0 ? $unitId : null);
        $ask = $missing;
        if (($missing[0]['field'] ?? '') === 'unit_id') {
            $ask = [$missing[0]];
        }

        $blocks = [];
        foreach ($ask as $item) {
            $blocks[] = $this->resolver->buildFieldValidationMessage(
                ['field' => $item['field'], 'reason' => 'missing', 'provided_value' => null],
                $options,
            );
        }

        $body = implode("\n\n", $blocks);
        if (count($ask) > 1) {
            $example = implode(' ', array_fill(0, count($ask), '1'));

            return "Jawab semua sisa pilihan dalam SATU pesan. Kirim nomor urut dipisah spasi. Contoh: *{$example}*\n\n".$body;
        }

        return $body;
    }

    private function stepForClassificationField(string $field): string
    {
        return match ($field) {
            'unit_id' => self::STEP_UNIT,
            'problem_category_id' => self::STEP_CATEGORY,
            'priority_id' => self::STEP_PRIORITY,
            'business_entities_id' => self::STEP_ENTITY,
            default => self::STEP_UNIT,
        };
    }

    private function goConfirm(array $state): array
    {
        $state['step'] = self::STEP_CONFIRM;

        return $this->reply('in_progress', true, $this->withGuide(self::STEP_CONFIRM, $this->confirmFollowUp($state)), [
            'step' => self::STEP_CONFIRM,
            'state' => $state,
        ]);
    }

    private function confirmFollowUp(array $state): string
    {
        $unit = trim((string) ($state['unit'] ?? '')) ?: 'Helpdesk';
        $lines = [
            $this->confirmPrompt($state),
            '',
            "Ketik *ya* untuk kirim ke {$unit}.",
            'Teks di atas disimpan apa adanya. Jika tidak sama dengan yang Anda ketik, ketik *ulangi*.',
            'Staf bisa mengubah kategori, unit, atau cabang nanti.',
            'Ketik *batal* untuk berhenti.',
        ];

        $channel = Str::lower(trim((string) ($state['channel'] ?? '')));
        $attachments = $state['attachments'] ?? [];
        if ($channel === 'whatsapp' && (! is_array($attachments) || $attachments === [])) {
            $lines[] = 'Lampiran opsional: kirim foto sekarang, lalu ketik *ya*.';
        }

        return implode("\n", $lines);
    }

    private function confirmPrompt(array $state): string
    {
        $title = trim((string) ($state['title'] ?? ''));
        $description = trim((string) ($state['description'] ?? ''));
        $location = trim((string) ($state['location'] ?? ''));

        $lines = [
            'Rekap laporan (belum tersimpan):',
            '• Kendala: '.($title !== '' ? $title : '-'),
        ];
        if ($description !== '' && Str::lower($description) !== Str::lower($title)) {
            $lines[] = '• Uraian: '.$description;
        }
        if ($location !== '') {
            $lines[] = '• Lokasi: '.$location;
        }

        $uncertain = (bool) ($state['category_uncertain'] ?? false)
            || Str::lower((string) ($state['problem_category'] ?? '')) === Str::lower(HelpdeskOperationalClassifier::UNCLASSIFIED_CATEGORY);
        if ($uncertain) {
            $lines[] = '• Jenis: belum dipastikan — staf isi di Helpdesk';
        } else {
            $category = trim((string) ($state['problem_category'] ?? ''));
            $lines[] = '• Jenis: '.($category !== '' ? $category : '-');
        }

        $unit = trim((string) ($state['unit'] ?? ''));
        if ($unit !== '') {
            $lines[] = (bool) ($state['unit_assumed'] ?? false)
                ? '• Unit: '.$unit.' (asumsi antrian)'
                : '• Unit: '.$unit;
        }

        $entity = trim((string) ($state['business_entity'] ?? ''));
        if ((bool) ($state['entity_assumed'] ?? false)
            || Str::lower($entity) === Str::lower(HelpdeskOperationalClassifier::UNSTATED_ENTITY)) {
            $lines[] = '• Cabang: belum disebut — staf isi di Helpdesk';
        } elseif ($entity !== '') {
            $source = ($state['entity_source'] ?? '') === 'owner' ? ' (dari tiket sebelumnya)' : '';
            $lines[] = '• Cabang: '.$entity.$source;
        }

        $priority = trim((string) ($state['priority'] ?? ''));
        if ($priority !== '') {
            $lines[] = (bool) ($state['priority_assumed'] ?? false)
                ? '• Prioritas: '.$priority.' (antrian)'
                : '• Prioritas: '.$priority;
        }

        $attachments = $state['attachments'] ?? [];
        if (is_array($attachments) && $attachments !== []) {
            $lines[] = '• Lampiran: '.count($attachments).' file';
        }

        $lines[] = '• Atas nama: '.($state['name'] ?? '-').($this->blank($state['phone'] ?? null) ? '' : ' / '.$state['phone']);

        return implode("\n", $lines);
    }

    private function looksLikeIssueUpdate(string $message): bool
    {
        if ($this->usableIssueText($message) === null) {
            return false;
        }

        return Str::length(trim($message)) >= 8;
    }

    private function usableIssueText(string $message): ?string
    {
        $story = $this->operationalClassifier->stripIntakePrefix($message);
        if ($story === '' || $this->unusableIssueMessage($story)) {
            return null;
        }

        return $story;
    }

    private function unusableIssueMessage(string $message): bool
    {
        if (trim($message) === '') {
            return true;
        }

        return $this->isLoneMenuChoice($message)
            || $this->looksLikeHostNoise($message)
            || $this->isYes($message)
            || $this->isNo($message)
            || $this->isSkip($message)
            || $this->isCancel($message);
    }

    private function looksLikeHostNoise(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        if (preg_match('/rekap laporan|belum tersimpan|ketik\s+\*?ya\*?|ketik\s+\*?ulangi\*|staf bisa mengubah|untuk kirim ke it|disimpan apa adanya/iu', $text)) {
            return true;
        }

        if ($this->looksLikeModelSummary($text)) {
            return true;
        }

        if (preg_match('/pilih\s+(kategori|unit|prioritas|nomor|salah satu|jenis)/iu', $text)) {
            return true;
        }

        $numbered = preg_match_all('/^\s*\d+[\.\)]\s+\S/um', $text);
        if ($numbered >= 2) {
            return true;
        }

        if (preg_match('/^\s*\d+[\.\)]\s+/u', $text)
            && preg_match('/\b(cctv|odoo|csa|jaringan|printer|kategori|unit|prioritas)\b/iu', $text)) {
            return true;
        }

        return false;
    }

    private function looksLikeModelSummary(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(the user|pengguna)\b|^user (reported|wants to|asked to)\b|\bi have summarized\b|\bhere is a summary\b|\bberikut (adalah )?(ringkasan|rekap)\b|\bringkasan (kendala|laporan|tiket)\b|^saya akan (membuat|membuatkan) tiket\b/iu',
            $text,
        );
    }

    private function isLoneMenuChoice(string $message): bool
    {
        return (bool) preg_match('/^[1-9]$/u', trim($message));
    }

    private function startPrompt(): string
    {
        return "Helpdesk IT.\n\nKetik *lapor* atau *create ticket* untuk membuat laporan langsung melalui MCP.\nContoh: *lapor odoo tidak bisa login*\n\nPesan lain belum dicatat sebagai tiket.";
    }

    private function phonePrompt(string $channel): string
    {
        $from = match ($channel) {
            'telegram' => 'Dari Telegram, identitas tiket ditautkan ke nomor WhatsApp Anda.',
            'discord' => 'Dari Discord, identitas tiket ditautkan ke nomor WhatsApp Anda.',
            'web' => 'Dari chat web, identitas tiket ditautkan ke nomor WhatsApp Anda.',
            'whatsapp' => 'Nomor pengirim belum mendapat bukti dari gateway WhatsApp.',
            default => 'Identitas tiket ditautkan ke nomor WhatsApp Anda.',
        };

        return $from."\nKetik nomor WhatsApp Anda (08... atau 62...). Kami kirim OTP sekali untuk memastikan pemiliknya.";
    }

    private function withGuide(string $step, string $body): string
    {
        $guide = match ($step) {
            self::STEP_PHONE,
            self::STEP_PHONE_OTP,
            self::STEP_REGISTRATION_CONSENT,
            self::STEP_REGISTRATION_NAME => 'Langkah 1 — identitas',
            self::STEP_ISSUE => 'Kendala',
            self::STEP_UNIT => 'Unit kerja',
            self::STEP_CATEGORY => 'Jenis masalah',
            self::STEP_PRIORITY => 'Prioritas',
            self::STEP_ENTITY => 'Perusahaan/cabang',
            self::STEP_ATTACHMENTS => 'Lampiran',
            self::STEP_CONFIRM => 'Konfirmasi',
            default => 'Helpdesk IT',
        };

        return "*{$guide}*\n\n{$body}";
    }

    private function shortFieldLabel(string $field): string
    {
        return match ($field) {
            'business_entities_id' => 'Perusahaan/cabang',
            'unit_id' => 'Unit kerja',
            'problem_category_id' => 'Jenis masalah',
            'priority_id' => 'Prioritas',
            default => 'Data',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, status: string, user_reply: string, data: array<string, mixed>}
     */
    private function reply(string $status, bool $ok, string $userReply, array $data): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'user_reply' => $userReply,
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketContext(array $state): array
    {
        return array_filter([
            'business_entities_id' => $state['business_entities_id'] ?? null,
            'business_entity' => $state['business_entity'] ?? null,
            'unit_id' => $state['unit_id'] ?? null,
            'unit' => $state['unit'] ?? null,
            'problem_category_id' => $state['problem_category_id'] ?? null,
            'problem_category' => $state['problem_category'] ?? null,
            'priority_id' => $state['priority_id'] ?? null,
            'priority' => $state['priority'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  list<string>  $existing
     * @param  list<string>  $incoming
     * @return list<string>
     */
    private function mergeAttachments(array $existing, array $incoming): array
    {
        foreach ($incoming as $url) {
            $url = trim((string) $url);
            if (HttpsUrl::isValid($url) && ! in_array($url, $existing, true)) {
                $existing[] = $url;
            }
        }

        return array_slice($existing, 0, HelpdeskIntakeContract::MAX_ATTACHMENTS);
    }

    /**
     * @return array<string, mixed>
     */
    private function freshState(
        ?string $phone,
        string $channel,
        string $name,
        string $actorKey,
        bool $phoneVerified = false,
        array $context = [],
    ): array {
        return [
            'step' => self::STEP_IDLE,
            'phone' => $phone ?: '',
            'phone_verified' => $phoneVerified && (bool) $phone,
            'name' => '',
            'display_name' => $name,
            'channel' => $channel,
            'actor_key' => $actorKey,
            'pending_issue' => '',
            'title' => '',
            'description' => '',
            'attachments' => [],
            'intake_id' => (string) ($context['intake_id'] ?? ''),
            'client_id' => (string) ($context['client_id'] ?? 'local'),
            'external_user_id' => (string) ($context['external_user_id'] ?? ''),
        ];
    }

    /**
     * Resolve a server-owned state handle. Caller-provided conversation IDs are
     * only aliases and are always scoped to the authenticated integration.
     *
     * @return array<string, mixed>
     */
    private function resolveSession(array $context, string $channel): array
    {
        $clientId = trim((string) ($context['client_id'] ?? 'local'));
        $clientId = $clientId !== '' ? Str::limit($clientId, 128, '') : 'local';
        $requested = trim((string) ($context['intake_id'] ?? ''));

        if ($requested !== '' && ! preg_match('/^hdi_[A-Za-z0-9_-]{20,100}$/', $requested)) {
            return [
                'error' => 'intake_id tidak valid. Kosongkan untuk memulai intake baru.',
                'intake_id' => '',
            ];
        }

        $aliasKey = $this->aliasKey($clientId, $channel, $context);
        $intakeId = $requested;
        $minted = false;
        if ($intakeId === '' && $aliasKey !== '') {
            $aliasLock = Cache::lock('helpdesk-intake-alias-lock:'.hash('sha256', $aliasKey), 5);
            if (! $aliasLock->get()) {
                return [
                    'error' => 'Konteks percakapan sedang diproses. Coba lagi sebentar.',
                    'intake_id' => '',
                ];
            }

            try {
                $cached = Cache::get($aliasKey);
                if (is_string($cached) && preg_match('/^hdi_[A-Za-z0-9_-]{20,100}$/', $cached)) {
                    $cachedActorKey = 'intake:'.hash('sha256', $clientId."\0".$cached);
                    if (Cache::has($this->cacheKey($cachedActorKey))
                        || Cache::has($this->completedCacheKey(
                            $cachedActorKey,
                            $this->contextFingerprint($channel, $context),
                        ))
                        || Cache::has($this->reservationCacheKey($cachedActorKey))) {
                        $intakeId = $cached;
                    }
                }

                if ($intakeId === '') {
                    $intakeId = 'hdi_'.Str::random(40);
                    $minted = true;
                    $reservedActorKey = 'intake:'.hash('sha256', $clientId."\0".$intakeId);
                    Cache::put($aliasKey, $intakeId, now()->addMinutes($this->intakeTtlMinutes()));
                    Cache::put(
                        $this->reservationCacheKey($reservedActorKey),
                        true,
                        now()->addMinutes($this->intakeTtlMinutes()),
                    );
                }
            } finally {
                $aliasLock->release();
            }
        } elseif ($intakeId === '') {
            $intakeId = 'hdi_'.Str::random(40);
            $minted = true;
        }

        $actorKey = 'intake:'.hash('sha256', $clientId."\0".$intakeId);
        if ($requested !== '' && ! $minted
            && ! Cache::has($this->cacheKey($actorKey))
            && ! Cache::has($this->completedCacheKey(
                $actorKey,
                $this->contextFingerprint($channel, $context),
            ))
            && ! Cache::has($this->reservationCacheKey($actorKey))) {
            $durable = $this->durableCompletion($clientId, $requested, $channel, $context);
            if ($durable !== null) {
                return [
                    'client_id' => $clientId,
                    'intake_id' => $requested,
                    'actor_key' => $actorKey,
                    'alias_key' => $aliasKey,
                    'durable_result' => $durable,
                ];
            }

            return [
                'error' => 'Sesi intake tidak ditemukan atau sudah kedaluwarsa. Kosongkan intake_id lalu mulai lagi.',
                'intake_id' => $intakeId,
            ];
        }

        if ($aliasKey !== '' && $requested === '') {
            Cache::put($aliasKey, $intakeId, now()->addMinutes($this->intakeTtlMinutes()));
        }

        return [
            'client_id' => $clientId,
            'intake_id' => $intakeId,
            'actor_key' => $actorKey,
            'alias_key' => $aliasKey,
        ];
    }

    private function durableCompletion(
        string $clientId,
        string $intakeId,
        string $channel,
        array $context,
    ): ?array {
        try {
            $databaseClientKey = HelpdeskIntegrationClient::persistentKey($clientId);
            $request = McpTicketCreationRequest::query()
                ->where('client_key', $databaseClientKey)
                ->where('key_hash', hash('sha256', $this->idempotencyKey([
                    'intake_id' => $intakeId,
                    'channel' => $channel,
                    'external_user_id' => (string) ($context['external_user_id'] ?? ''),
                ])))
                ->where('status', 'completed')
                ->first();
            $response = $request?->response;
            if (! is_array($response) || ! ($response['ok'] ?? false)) {
                return null;
            }

            $ticket = data_get($response, 'data.ticket');
            $message = (string) ($response['message'] ?? 'Tiket sudah dibuat.');
            $result = $this->mcpReply($this->reply('ticket_created', true, $message, [
                'step' => self::STEP_IDLE,
                'ticket' => is_array($ticket) ? $ticket : null,
            ]), $intakeId);
            $result['replayed'] = true;

            return $result;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function aliasKey(string $clientId, string $channel, array $context): string
    {
        $conversation = '';
        foreach (['external_conversation_id', 'conversation_id'] as $field) {
            $candidate = trim((string) ($context[$field] ?? ''));
            if ($candidate !== '') {
                $conversation = $candidate;
                break;
            }
        }
        $user = trim((string) ($context['external_user_id'] ?? ''));

        // Do not alias by MCP-Session-Id. Hosts such as Atlas share one
        // Streamable HTTP session across many chats, so a transport alias
        // would merge concurrent reports into a single intake.
        if ($conversation === '' && $user === '') {
            return '';
        }

        $subject = $conversation !== ''
            ? 'conversation:'.$conversation."\0".$user
            : 'user:'.$user;
        $seed = $clientId."\0".$channel."\0".$subject;

        return 'helpdesk-intake-alias:'.hash('sha256', $seed);
    }

    private function messageId(array $context): string
    {
        return Str::limit(
            trim((string) ($context['external_message_id'] ?? $context['message_id'] ?? '')),
            HelpdeskIntakeContract::MAX_ID_LENGTH,
            '',
        );
    }

    private function messageCacheKey(
        string $actorKey,
        string $messageId,
        string $contextFingerprint,
    ): string {
        return 'helpdesk-intake-message:'.hash('sha256', $actorKey."\0".$contextFingerprint."\0".$messageId);
    }

    private function messageFingerprint(string $message, array $attachments, array $context): string
    {
        return hash('sha256', json_encode([
            'message' => $message,
            'attachments' => array_values($attachments),
            'start' => (bool) ($context['start'] ?? false),
            'channel' => $this->normalizeChannel((string) ($context['channel'] ?? '')),
            'phone' => $this->normalizePhone((string) ($context['phone'] ?? '')),
            'reporter_name' => trim((string) ($context['reporter_name'] ?? '')),
            'conversation_id' => trim((string) ($context['conversation_id'] ?? '')),
            'external_conversation_id' => trim((string) ($context['external_conversation_id'] ?? '')),
            'external_user_id' => trim((string) ($context['external_user_id'] ?? '')),
            'identity_assertion_hash' => hash('sha256', trim((string) ($context['identity_assertion'] ?? ''))),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function contextFingerprint(string $channel, array $context): string
    {
        return hash('sha256', $this->normalizeChannel($channel)."\0".trim((string) ($context['external_user_id'] ?? '')));
    }

    private function idempotencyKey(array $state): string
    {
        $contextFingerprint = $this->contextFingerprint(
            (string) ($state['channel'] ?? 'mcp'),
            ['external_user_id' => (string) ($state['external_user_id'] ?? '')],
        );

        return 'intake:'.(string) ($state['intake_id'] ?? '').':'.$contextFingerprint;
    }

    private function completedCacheKey(string $actorKey, string $contextFingerprint): string
    {
        return 'helpdesk-intake-completed:'.hash('sha256', $actorKey."\0".$contextFingerprint);
    }

    private function reservationCacheKey(string $actorKey): string
    {
        return 'helpdesk-intake-reservation:'.hash('sha256', $actorKey);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function mcpReply(array $result, string $intakeId): array
    {
        $step = (string) data_get($result, 'data.step', self::STEP_IDLE);
        $status = (string) ($result['status'] ?? 'invalid_input');
        $terminal = in_array($status, self::TERMINAL_STATUSES, true);

        return array_merge($result, [
            'intake_id' => $intakeId,
            'step' => $step,
            'requires_input' => ! $terminal && in_array($status, ['idle', 'in_progress'], true),
            'terminal' => $terminal,
            'expires_at' => now()->addMinutes($this->intakeTtlMinutes())->toIso8601String(),
            'replayed' => (bool) ($result['replayed'] ?? false),
            'ticket' => data_get($result, 'data.ticket'),
        ]);
    }

    private function mayUseReporterBinding(array $state): bool
    {
        return trim((string) ($state['external_user_id'] ?? '')) !== '';
    }

    private function intakeTtlMinutes(): int
    {
        return $this->configuration->intakeTtlMinutes();
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = Str::lower(trim($channel));

        if ($channel === '' || Str::length($channel) > HelpdeskIntakeContract::MAX_CHANNEL_LENGTH) {
            return '';
        }

        return preg_match('/'.HelpdeskIntakeContract::CHANNEL_PATTERN.'/D', $channel)
            ? $channel
            : '';
    }

    private function loadActor(string $actorKey): ?array
    {
        $state = Cache::get($this->cacheKey($actorKey));

        return is_array($state) ? $state : null;
    }

    private function storeState(array $state): void
    {
        $actorKey = trim((string) ($state['actor_key'] ?? ''));
        if ($actorKey !== '') {
            Cache::put(
                $this->cacheKey($actorKey),
                $state,
                now()->addMinutes($this->intakeTtlMinutes()),
            );
        }
    }

    private function clearState(array $state): void
    {
        $actorKey = trim((string) ($state['actor_key'] ?? ''));
        if ($actorKey !== '') {
            Cache::forget($this->cacheKey($actorKey));
        }
    }

    private function cacheKey(string $key): string
    {
        return 'helpdesk-intake-session:'.$key;
    }

    private function normalizePhone(string $value): ?string
    {
        return PhoneNumber::canonical($value);
    }

    private function isCancel(string $message): bool
    {
        return in_array($this->decisionText($message), ['batal', 'cancel', 'stop'], true);
    }

    private function isSkip(string $message): bool
    {
        $normalized = $this->decisionText($message);

        return in_array($normalized, ['lewati', 'skip', 'tidak ada', 'gaada', '0'], true)
            || trim($message) === '-';
    }

    private function isYes(string $message): bool
    {
        $normalized = $this->decisionText($message);
        if (in_array($normalized, ['ya', 'iya', 'yes', 'y', 'ok', 'oke', 'siap', 'kirim', 'simpan', 'setuju'], true)) {
            return true;
        }

        return (bool) preg_match(
            '/^(ya|iya|yes|ok|oke)(\s+(kak|kakak|min|bos|dong|lah|saja|aja|silakan|sudah|boleh|kirim))*$/u',
            $normalized,
        );
    }

    private function isNo(string $message): bool
    {
        return in_array($this->decisionText($message), ['tidak', 'nggak', 'enggak', 'gak', 'ulangi', 'tidak jadi', 'no'], true);
    }

    private function decisionText(string $message): string
    {
        $normalized = Str::lower(trim($message));
        $normalized = preg_replace('/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    /**
     * @return array{known: bool, blocked: bool, block_code: string, ambiguous: bool, ambiguity_source: string, source: string, label: string, name: string, email: string, identity: string, helpdesk_user_id: ?int, identity_fingerprint: string}
     */
    private function identifyReporter(string $phone): array
    {
        $unknown = [
            'known' => false,
            'blocked' => false,
            'block_code' => '',
            'ambiguous' => false,
            'ambiguity_source' => '',
            'source' => 'unknown',
            'label' => 'belum terdaftar',
            'name' => '',
            'email' => '',
            'identity' => '',
            'helpdesk_user_id' => null,
            'identity_fingerprint' => '',
        ];

        $users = User::query()
            ->withTrashed()
            ->where('phone_normalized', $phone)
            ->get();
        if ($users->count() > 1) {
            return array_merge($unknown, [
                'ambiguous' => true,
                'ambiguity_source' => 'helpdesk',
            ]);
        }
        $user = $users->first();
        if ($user && ($user->trashed() || ! $user->is_active)) {
            return array_merge($unknown, [
                'blocked' => true,
                'block_code' => 'HELPDESK_ACTOR_INACTIVE',
            ]);
        }
        if ($user) {
            return [
                'known' => true,
                'blocked' => false,
                'block_code' => '',
                'ambiguous' => false,
                'ambiguity_source' => '',
                'source' => 'registered',
                'label' => 'akun Helpdesk',
                'name' => trim((string) $user->name) !== '' ? (string) $user->name : 'Pelapor Helpdesk',
                'email' => (string) ($user->email ?? ''),
                'identity' => (string) ($user->identity ?? ''),
                'helpdesk_user_id' => $user->id,
                'identity_fingerprint' => '',
            ];
        }

        $employeeMatches = $this->employees->findAllByPhone($phone);
        if (count($employeeMatches) > 1) {
            return array_merge($unknown, [
                'ambiguous' => true,
                'ambiguity_source' => 'talenta',
            ]);
        }

        $employee = $employeeMatches[0] ?? null;
        if (is_array($employee)) {
            $name = trim(strip_tags(trim(($employee['first_name'] ?? '').' '.($employee['last_name'] ?? ''))));
            if ($name !== '') {
                return [
                    'known' => true,
                    'blocked' => false,
                    'block_code' => '',
                    'ambiguous' => false,
                    'ambiguity_source' => '',
                    'source' => 'employee',
                    'label' => 'data karyawan',
                    'name' => $name,
                    'email' => strtolower(trim((string) ($employee['email'] ?? ''))),
                    'identity' => trim((string) ($employee['id_employee'] ?? $employee['user_id'] ?? $employee['id'] ?? $employee['nik'] ?? '')),
                    'helpdesk_user_id' => null,
                    'identity_fingerprint' => TalentaEmployee::identityFingerprint($employee),
                ];
            }

            return array_merge($unknown, [
                'blocked' => true,
                'block_code' => 'TALENTA_IDENTITY_INVALID',
            ]);
        }

        return $unknown;
    }

    private function cleanName(string $message): ?string
    {
        return HelpdeskReporterName::normalize($message);
    }

    private function maskedPhone(string $phone): string
    {
        $phone = (string) ($this->normalizePhone($phone) ?? '');
        if ($phone === '') {
            return 'nomor Anda';
        }

        return substr($phone, 0, 4).str_repeat('•', max(3, strlen($phone) - 8)).substr($phone, -4);
    }
}
