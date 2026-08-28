<?php

namespace App\Services\Integrations;

use App\Models\HelpdeskReporterBinding;
use App\Models\User;
use App\Support\HelpdeskIntakeContract;
use App\Support\HelpdeskIntegrationClient;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class HelpdeskReporterIdentityService
{
    public function linkedUser(string $clientId, string $channel, string $externalUserId): ?User
    {
        $clientKey = $this->clientKey($clientId);
        $channel = $this->channel($channel);
        $subjectHash = $this->subjectHash($clientKey, $channel, $externalUserId);
        if ($subjectHash === null) {
            return null;
        }

        try {
            $binding = HelpdeskReporterBinding::query()
                ->active()
                ->where('client_key', $clientKey)
                ->where('channel', $channel)
                ->where('external_user_hash', $subjectHash)
                ->with('user')
                ->first();
            if (! $binding) {
                return null;
            }

            $user = $binding->user;
            $phone = PhoneNumber::canonical((string) ($user?->phone ?? ''));
            if (! $user || ! $user->is_active || $phone === null
                || ! hash_equals((string) $binding->verified_phone_hash, $this->phoneHash($phone))) {
                $binding->update(['revoked_at' => now()]);

                return null;
            }

            $binding->update(['last_seen_at' => now()]);

            return $user;
        } catch (Throwable $exception) {
            report($exception);

            // Deployments must run the additive binding migration. Falling back
            // to OTP keeps ticket intake safe if the table is temporarily absent.
            return null;
        }
    }

    public function bind(
        string $clientId,
        string $channel,
        string $externalUserId,
        User $user,
        string $method = 'whatsapp_otp',
    ): bool {
        $clientKey = $this->clientKey($clientId);
        $channel = $this->channel($channel);
        $subjectHash = $this->subjectHash($clientKey, $channel, $externalUserId);
        $phone = PhoneNumber::canonical((string) $user->phone);
        if ($subjectHash === null || $phone === null || ! $user->is_active) {
            return false;
        }

        try {
            $timestamp = now();
            HelpdeskReporterBinding::query()->upsert([[
                'client_key' => $clientKey,
                'channel' => $channel,
                'external_user_hash' => $subjectHash,
                'user_id' => $user->id,
                'verified_phone_hash' => $this->phoneHash($phone),
                'verification_method' => Str::limit($method, 32, ''),
                'verified_at' => $timestamp,
                'last_seen_at' => $timestamp,
                'revoked_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]], [
                'client_key',
                'channel',
                'external_user_hash',
            ], [
                'user_id',
                'verified_phone_hash',
                'verification_method',
                'verified_at',
                'last_seen_at',
                'revoked_at',
                'updated_at',
            ]);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Verifies an optional assertion produced outside the model/tool call by a
     * trusted WhatsApp gateway. Format: v1.<unix timestamp>.<hex HMAC-SHA256>.
     */
    public function assertionIsValid(
        string $assertion,
        string $clientId,
        string $channel,
        string $externalUserId,
        string $phone,
        string $externalMessageId,
        string $intakeId,
    ): bool {
        $secret = trim((string) config('services.helpdesk_mcp.identity_assertion_secret', ''));
        $phone = (string) (PhoneNumber::canonical($phone) ?? '');
        $channel = $this->channel($channel);
        $externalUserId = trim($externalUserId);
        $externalMessageId = trim($externalMessageId);
        $intakeId = trim($intakeId);
        if ($secret === '' || $phone === '' || $channel !== 'whatsapp'
            || $externalUserId === '' || $externalMessageId === '' || $intakeId === '') {
            return false;
        }

        $parts = explode('.', trim($assertion));
        if (count($parts) !== 3 || $parts[0] !== 'v1' || ! ctype_digit($parts[1])
            || ! preg_match('/^[a-f0-9]{64}$/Di', $parts[2])) {
            return false;
        }

        $timestamp = (int) $parts[1];
        $leeway = max(30, (int) config('services.helpdesk_mcp.identity_assertion_leeway_seconds', 300));
        if (abs(now()->timestamp - $timestamp) > $leeway) {
            return false;
        }

        $payload = implode("\n", [
            'v1',
            (string) $timestamp,
            $this->clientKey($clientId),
            $channel,
            $externalUserId,
            $phone,
            $externalMessageId,
        ]);
        $expected = hash_hmac('sha256', $payload, $secret);
        $signature = strtolower($parts[2]);
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $assertionHash = hash('sha256', $signature);
        $intakeHash = hash('sha256', $intakeId);
        $expiresAt = now()->setTimestamp($timestamp)->addSeconds($leeway);

        try {
            return DB::transaction(function () use ($assertionHash, $intakeHash, $expiresAt): bool {
                DB::table('helpdesk_identity_assertion_uses')
                    ->where('expires_at', '<', now())
                    ->delete();

                $now = now();
                DB::table('helpdesk_identity_assertion_uses')->insertOrIgnore([
                    'assertion_hash' => $assertionHash,
                    'intake_hash' => $intakeHash,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $claim = DB::table('helpdesk_identity_assertion_uses')
                    ->where('assertion_hash', $assertionHash)
                    ->lockForUpdate()
                    ->first();

                return is_object($claim)
                    && is_string($claim->intake_hash ?? null)
                    && hash_equals($claim->intake_hash, $intakeHash);
            });
        } catch (Throwable $exception) {
            report($exception);

            // Fail closed to the normal WhatsApp OTP flow if the additive
            // assertion-use migration is unavailable or persistence fails.
            return false;
        }
    }

    public function clientKey(string $clientId): string
    {
        return HelpdeskIntegrationClient::persistentKey($clientId);
    }

    private function subjectHash(string $clientKey, string $channel, string $externalUserId): ?string
    {
        $externalUserId = trim($externalUserId);
        if ($externalUserId === '' || Str::length($externalUserId) > HelpdeskIntakeContract::MAX_ID_LENGTH) {
            return null;
        }

        return hash_hmac(
            'sha256',
            $clientKey."\0".$channel."\0".$externalUserId,
            $this->pepper(),
        );
    }

    private function phoneHash(string $phone): string
    {
        return hash_hmac('sha256', $phone, $this->pepper());
    }

    private function pepper(): string
    {
        $pepper = (string) config('services.helpdesk_mcp.identity_pepper', '');

        return $pepper !== '' ? $pepper : (string) config('app.key');
    }

    private function channel(string $channel): string
    {
        $channel = Str::lower(trim($channel));

        return Str::length($channel) <= HelpdeskIntakeContract::MAX_CHANNEL_LENGTH
            && preg_match('/'.HelpdeskIntakeContract::CHANNEL_PATTERN.'/D', $channel)
            ? $channel
            : 'mcp';
    }
}
