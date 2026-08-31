<?php

namespace App\Services\Integrations;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class PublicReporterIdentityService
{
    public const COOKIE_NAME = 'helpdesk_reporter_device';

    private const CLIENT_ID = 'public-helpdesk-form';

    private const CHANNEL = 'web';

    private const SESSION_DEVICE_TOKEN = 'helpdesk.public_reporter.device_token';

    private const COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(
        private readonly HelpdeskReporterIdentityService $identities,
    ) {}

    public function current(Request $request): ?User
    {
        $token = $this->deviceToken($request);
        $user = $this->identities->linkedUser(self::CLIENT_ID, self::CHANNEL, $token);

        if ($user) {
            $this->queueDeviceCookie($request, $token);
        }

        return $user;
    }

    public function remember(Request $request, User $user): bool
    {
        $token = $this->deviceToken($request);
        $remembered = $this->identities->bind(
            self::CLIENT_ID,
            self::CHANNEL,
            $token,
            $user,
            'phone_input',
        );

        if ($remembered) {
            $this->queueDeviceCookie($request, $token);
        }

        return $remembered;
    }

    public function forget(Request $request): bool
    {
        foreach ($this->deviceTokens($request) as $token) {
            if (! $this->identities->revoke(self::CLIENT_ID, self::CHANNEL, $token)) {
                return false;
            }
        }

        $request->session()->forget(self::SESSION_DEVICE_TOKEN);
        Cookie::queue(Cookie::forget(
            self::COOKIE_NAME,
            '/',
            config('session.domain'),
        ));

        return true;
    }

    public function deviceToken(Request $request): string
    {
        $token = $this->existingDeviceToken($request);
        if ($token === null) {
            $token = Str::random(64);
        }

        $request->session()->put(self::SESSION_DEVICE_TOKEN, $token);
        $this->queueDeviceCookie($request, $token);

        return $token;
    }

    public function rotateDeviceToken(Request $request): string
    {
        $token = Str::random(64);

        $request->session()->put(self::SESSION_DEVICE_TOKEN, $token);
        $this->queueDeviceCookie($request, $token);

        return $token;
    }

    public function maskedPhone(User $user): string
    {
        $phone = PhoneNumber::canonical($user->phone);
        if ($phone === null || strlen($phone) < 7) {
            return 'Nomor WhatsApp tersimpan';
        }

        return substr($phone, 0, 4).str_repeat('•', max(4, strlen($phone) - 8)).substr($phone, -4);
    }

    private function existingDeviceToken(Request $request): ?string
    {
        return $this->deviceTokens($request)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function deviceTokens(Request $request): array
    {
        return collect([
            $request->session()->get(self::SESSION_DEVICE_TOKEN),
            $request->cookie(self::COOKIE_NAME),
        ])->map(static fn (mixed $candidate): string => is_string($candidate) ? trim($candidate) : '')
            ->filter(static fn (string $candidate): bool => (bool) preg_match('/^[A-Za-z0-9]{64}$/D', $candidate))
            ->unique()
            ->values()
            ->all();
    }

    private function queueDeviceCookie(Request $request, string $token): void
    {
        Cookie::queue(Cookie::make(
            self::COOKIE_NAME,
            $token,
            self::COOKIE_MINUTES,
            '/',
            config('session.domain'),
            config('session.secure') ?? $request->isSecure(),
            true,
            false,
            config('session.same_site', 'lax'),
        ));
    }
}
