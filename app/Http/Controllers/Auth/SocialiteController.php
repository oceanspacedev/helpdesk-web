<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialiteUser;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    public function redirectToProvider($provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    public function handleProvideCallback($provider)
    {
        try {
            $user = Socialite::driver($provider)->user();
        } catch (\Exception $exception) {
            report($exception);

            return redirect()->back();
        }
        $authUser = $this->findOrCreateUser($user, $provider);

        if (! $authUser) {
            return redirect()
                ->route('filament.admin.auth.login')
                ->withErrors([
                    'email' => 'Akun Helpdesk belum tersedia. Hubungi admin untuk membuat akun.',
                ]);
        }

        Auth()->login($authUser, true);

        return redirect()->route('filament.admin.pages.dashboard');
    }

    public function findOrCreateUser($socialUser, $provider): ?User
    {
        $socialAccount = SocialiteUser::where('provider_id', $socialUser->id)
            ->where('provider', $provider)
            ->first();

        if ($socialAccount) {
            $linkedUser = $socialAccount->user;

            return $linkedUser
                && ! $linkedUser->trashed()
                && $linkedUser->is_active
                && $linkedUser->canUseVerifiedEmail()
                    ? $linkedUser
                    : null;
        }

        $email = strtolower(trim((string) $socialUser->getEmail()));
        if ($email === '') {
            return null;
        }

        $user = User::query()->withTrashed()->where('email', $email)->first();
        if ($user && ($user->trashed() || ! $user->is_active || ! $user->canUseVerifiedEmail())) {
            return null;
        }

        if (! $user) {
            return null;
        }

        $user->socialiteUsers()->create([
            'provider_id' => $socialUser->getId(),
            'provider' => $provider,
        ]);

        return $user;
    }
}
