<?php

namespace App\Http\Controllers\Auth;

use App\Filament\Auth\Concerns\InteractsWithPhoneNumbers;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PhoneOtpLoginController extends Controller
{
    use InteractsWithPhoneNumbers;

    public function showVerifyForm(Request $request): View
    {
        if (filament()->getCurrentPanel() === null) {
            filament()->setCurrentPanel(filament()->getPanel('admin'));
            filament()->bootCurrentPanel();
        }

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

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
