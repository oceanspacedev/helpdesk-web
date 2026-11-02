<?php

namespace App\Services;

use App\Mail\EmailLoginMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Login email tanpa password: satu email berisi kode 6 digit dan magic link. Keduanya disimpan di satu record
 * email_otps, jadi memakai salah satunya ikut menghanguskan yang lain.
 */
class EmailOtpService
{
    public const MAX_ATTEMPTS = 5;

    public const TOKEN_LENGTH = 64;

    /**
     * Login email hanya ditawarkan bila mailer default benar-benar mengirim email (bukan log/array).
     */
    public static function isConfigured(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport");

        return ! in_array($transport, ['', 'log', 'array'], true)
            && filled(config('mail.from.address'));
    }

    public static function normalizeEmail(mixed $value): ?string
    {
        $email = Str::lower(trim(is_scalar($value) ? (string) $value : ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * Akun aktif pemilik email ini. Email yang dipakai lebih dari satu user tidak bisa dipakai login.
     */
    public function findUser(string $email): ?User
    {
        $matches = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->limit(2)
            ->get();

        $user = $matches->count() === 1 ? $matches->first() : null;

        return $user?->is_active ? $user : null;
    }

    /**
     * Membuat kode dan magic link baru (yang lama untuk email ini hangus), lalu mengirimnya langsung.
     *
     * @throws RuntimeException bila email gagal dikirim; record-nya sudah di-expire.
     */
    public function issue(User $user, string $email, string $purpose): void
    {
        $expiresIn = $this->expiresIn();
        $lockKey = 'helpdesk:email-otp:lock:issue:'.hash('sha256', $purpose."\0".$email);

        Cache::lock($lockKey, 90)->block(10, function () use ($user, $email, $purpose, $expiresIn): void {
            $otp = (string) random_int(100000, 999999);
            $token = Str::random(self::TOKEN_LENGTH);

            $record = DB::transaction(function () use ($user, $email, $purpose, $otp, $token, $expiresIn): EmailOtp {
                EmailOtp::query()
                    ->where('email', $email)
                    ->where('purpose', $purpose)
                    ->whereNull('verified_at')
                    ->update(['expires_at' => now()]);

                return EmailOtp::query()->create([
                    'user_id' => $user->getKey(),
                    'email' => $email,
                    'purpose' => $purpose,
                    'otp_hash' => Hash::make($otp),
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addSeconds($expiresIn),
                    'attempt_count' => 0,
                ]);
            });

            try {
                Mail::to($email)->send(new EmailLoginMail(
                    name: (string) $user->name,
                    otp: $otp,
                    loginUrl: route('email-login.link', ['token' => $token]),
                    expiresInMinutes: max(1, intdiv($expiresIn, 60)),
                ));
            } catch (Throwable $exception) {
                $record->forceFill(['expires_at' => now()])->save();

                Log::warning('Gagal mengirim email login.', [
                    'user_id' => $user->getKey(),
                    'purpose' => $purpose,
                    'email_domain' => Str::after($email, '@'),
                    'exception' => $exception::class,
                ]);

                throw new RuntimeException('Gagal mengirim email login.', previous: $exception);
            }
        });
    }

    /**
     * Mencocokkan kode untuk permintaan terakhir email ini. Salah kode menambah attempt_count; setelah
     * MAX_ATTEMPTS kali record (termasuk magic link-nya) hangus.
     */
    public function verify(string $email, string $purpose, string $otp): ?User
    {
        $candidate = EmailOtp::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first(['id', 'user_id']);

        return $candidate ? $this->redeem($candidate, $otp) : null;
    }

    public function consumeMagicLink(string $token, string $purpose): ?User
    {
        if (strlen($token) !== self::TOKEN_LENGTH) {
            return null;
        }

        $candidate = EmailOtp::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('purpose', $purpose)
            ->first(['id', 'user_id']);

        return $candidate ? $this->redeem($candidate, null) : null;
    }

    public function expiresIn(): int
    {
        return max(60, (int) config('services.email_login.expires_in', 600));
    }

    /**
     * User dikunci sebelum record OTP, urutan yang sama di alur kode dan magic link, agar penggantian email user
     * tidak berpacu dengan login yang sedang berjalan.
     */
    private function redeem(EmailOtp $candidate, ?string $otp): ?User
    {
        if ($candidate->user_id === null) {
            return null;
        }

        return DB::transaction(function () use ($candidate, $otp): ?User {
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->first();
            $record = EmailOtp::query()
                ->whereKey($candidate->getKey())
                ->whereNull('verified_at')
                ->lockForUpdate()
                ->first();

            if (! $user || ! $record || ! $user->is_active || ! $this->isUsable($record)
                || self::normalizeEmail($user->email) !== $record->email) {
                return null;
            }

            if ($otp !== null && ! Hash::check($otp, $record->otp_hash)) {
                $attempts = $record->attempt_count + 1;
                $record->forceFill([
                    'attempt_count' => $attempts,
                    'expires_at' => $attempts >= self::MAX_ATTEMPTS ? now() : $record->expires_at,
                ])->save();

                return null;
            }

            $record->forceFill(['verified_at' => now()])->save();
            $this->markEmailVerified($user);

            return $user;
        });
    }

    /**
     * Kode atau link yang sampai ke kotak masuk membuktikan kepemilikan email, sehingga notifikasi email ikut aktif.
     */
    private function markEmailVerified(User $user): void
    {
        if ($user->canUseVerifiedEmail()) {
            return;
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verified_via' => 'email_link',
        ])->save();
    }

    private function isUsable(EmailOtp $record): bool
    {
        return $record->expires_at !== null
            && $record->expires_at->isFuture()
            && $record->attempt_count < self::MAX_ATTEMPTS;
    }
}
