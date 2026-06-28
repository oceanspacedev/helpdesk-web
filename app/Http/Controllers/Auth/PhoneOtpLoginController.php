<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WhatsAppGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PhoneOtpLoginController extends Controller
{
    public function showPhoneForm(): View
    {
        return view('auth.phone-login');
    }

    public function sendOtp(Request $request, WhatsAppGateway $whatsAppGateway): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $phone = $this->normalizePhone($validated['phone']);
        $user = $phone ? $this->findActiveUserByPhone($phone) : null;

        if (! $user) {
            return back()
                ->withInput()
                ->withErrors(['phone' => 'Nomor HP belum terdaftar atau tidak aktif.']);
        }

        $otp = (string) random_int(100000, 999999);
        $ttlMinutes = max(1, (int) config('services.phone_otp_login.ttl_minutes', 5));
        $message = "Kode OTP Helpdesk Anda: {$otp}\nBerlaku {$ttlMinutes} menit. Jangan bagikan kode ini kepada siapa pun.";

        if (! $whatsAppGateway->send($user->phone, $message)) {
            return back()
                ->withInput()
                ->withErrors(['phone' => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.']);
        }

        Cache::put($this->otpCacheKey($user->phone), [
            'user_id' => $user->id,
            'otp_hash' => Hash::make($otp),
        ], now()->addMinutes($ttlMinutes));

        return redirect()
            ->route('phone-login.verify')
            ->withInput(['phone' => $user->phone])
            ->with('status', 'Kode OTP sudah dikirim ke WhatsApp.');
    }

    public function showVerifyForm(Request $request): View
    {
        return view('auth.phone-login-verify', [
            'phone' => old('phone', $request->query('phone', '')),
        ]);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'otp' => ['required', 'digits:6'],
        ]);

        $phone = $this->normalizePhone($validated['phone']);
        $user = $phone ? $this->findActiveUserByPhone($phone) : null;

        if (! $user) {
            return back()
                ->withInput()
                ->withErrors(['phone' => 'Nomor HP belum terdaftar atau tidak aktif.']);
        }

        $cached = Cache::get($this->otpCacheKey($user->phone));

        if (! is_array($cached)
            || (int) ($cached['user_id'] ?? 0) !== (int) $user->id
            || ! Hash::check((string) $validated['otp'], (string) ($cached['otp_hash'] ?? ''))
        ) {
            return back()
                ->withInput()
                ->withErrors(['otp' => 'Kode OTP tidak valid atau sudah kedaluwarsa.']);
        }

        Cache::forget($this->otpCacheKey($user->phone));
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect('/admin');
    }

    private function findActiveUserByPhone(string $phone): ?User
    {
        $candidates = [$phone, '+'.$phone];
        if (str_starts_with($phone, '62')) {
            $candidates[] = '0'.substr($phone, 2);
            $candidates[] = substr($phone, 2);
        }

        return User::query()
            ->where('is_active', true)
            ->whereIn('phone', array_values(array_unique(array_filter($candidates))))
            ->first();
    }

    private function normalizePhone(mixed $value): ?string
    {
        $phone = trim((string) ($value ?? ''));
        if ($phone === '') {
            return null;
        }

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

    private function otpCacheKey(string $phone): string
    {
        return 'phone-otp-login:'.sha1($phone);
    }
}
