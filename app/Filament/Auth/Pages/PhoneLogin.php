<?php

namespace App\Filament\Auth\Pages;

use App\Filament\Auth\Concerns\InteractsWithPhoneNumbers;
use App\Services\WhatsAppGateway;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsIconAlias;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @property-read Action $loginAction
 * @property-read Schema $form
 */
class PhoneLogin extends SimplePage
{
    use InteractsWithPhoneNumbers;
    use RestrictsFileUploadsToSchemaComponents;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public bool $awaitingOtp = false;
    public bool $requiresRegistration = false;
    public bool $wagConfigured = false;

    public function mount(): void
    {
        $this->wagConfigured = !empty(config('services.whatsapp_gateway.url')) && !empty(config('services.whatsapp_gateway.token'));

        if (Filament::getCurrentPanel() === null) {
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Filament::bootCurrentPanel();
        }

        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }

        $this->maxWidth = 'full';
        $this->form->fill();
    }

    public function send(\App\Services\WhatsAppGateway $whatsAppGateway, \App\Services\EmployeeService $employeeService): void
    {
        $data = $this->form->getState();
        $phone = $this->normalizePhone($data['phone'] ?? null);

        if (!$phone) {
            throw ValidationException::withMessages([
                'data.phone' => 'Nomor HP tidak valid.',
            ]);
        }

        $throttleKey = 'send-otp:' . request()->ip() . ':' . $phone;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'data.phone' => "Terlalu banyak permintaan. Silakan coba dalam {$seconds} detik.",
            ]);
        }

        $user = $this->findActiveUserByPhone($phone);

        $generatedPassword = null;

        if (! $user) {
            // Cegah bentrok dengan constraint unik kolom phone: jika nomor sudah
            // terdaftar tapi akunnya nonaktif, jangan buat user baru (akan 500).
            $inactiveUser = $this->findUserByPhone($phone, false);
            if ($inactiveUser) {
                throw ValidationException::withMessages([
                    'data.phone' => 'Nomor HP terdaftar namun akun Anda dinonaktifkan. Hubungi administrator.',
                ]);
            }
        }

        if (! $user && ! $this->requiresRegistration) {
            $employeeData = $employeeService->findByPhone($phone);
            if ($employeeData) {
                $generatedPassword = \Illuminate\Support\Str::random(8);
                try {
                    $user = $employeeService->createUser($employeeData, $generatedPassword);
                } catch (\DomainException $e) {
                    throw ValidationException::withMessages([
                        'data.phone' => $e->getMessage(),
                    ]);
                }
            } else {
                $this->requiresRegistration = true;
                $this->form->fill([
                    'phone' => $data['phone'] ?? null,
                    'name' => null,
                    'email' => null,
                    'password' => null,
                ]);

                \Filament\Notifications\Notification::make()
                    ->title('Nomor HP Belum Terdaftar')
                    ->body('Silakan lengkapi pendaftaran untuk membuat akun baru.')
                    ->info()
                    ->send();

                return;
            }
        }

        if (! $user && $this->requiresRegistration) {
            $validator = \Illuminate\Support\Facades\Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ], [
                'name.required' => 'Nama lengkap wajib diisi.',
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Format email tidak valid.',
                'email.unique' => 'Email sudah terdaftar. Silakan gunakan email lain.',
                'password.required' => 'Password wajib diisi untuk keamanan akun.',
                'password.min' => 'Password minimal 8 karakter.',
            ]);

            if ($validator->fails()) {
                $messages = [];
                foreach ($validator->errors()->messages() as $field => $errors) {
                    $messages["data.{$field}"] = $errors[0];
                }
                throw ValidationException::withMessages($messages);
            }

            $user = \App\Models\User::create([
                'name' => trim(strip_tags($data['name'])),
                'email' => strtolower(trim($data['email'])),
                'phone' => $phone,
                'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
            ]);
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'data.phone' => 'Gagal memproses nomor HP.',
            ]);
        }

        $otp = (string) random_int(100000, 999999);
        $ttlMinutes = max(1, (int) config('services.phone_otp_login.ttl_minutes', 5));
        $message = "Kode OTP Helpdesk Anda: *{$otp}*\nBerlaku {$ttlMinutes} menit. Jangan bagikan kode ini kepada siapapun.";

        if (! $whatsAppGateway->send($user->phone, $message)) {
            throw ValidationException::withMessages([
                'data.phone' => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
            ]);
        }

        \Illuminate\Support\Facades\RateLimiter::hit($throttleKey, 120);

        Cache::put($this->otpCacheKey($user->phone), [
            'user_id' => $user->id,
            'otp_hash' => Hash::make($otp),
            // Disimpan terenkripsi: password default jangan pernah ada dalam
            // bentuk plaintext di cache.
            'generated_password' => $generatedPassword ? \Illuminate\Support\Facades\Crypt::encryptString($generatedPassword) : null,
            'is_new' => (bool) $generatedPassword || $this->requiresRegistration,
        ], now()->addMinutes($ttlMinutes));

        $this->awaitingOtp = true;
        $this->form->fill([
            'phone' => $user->phone,
            'otp' => null,
        ]);
    }

    public function verify(): void
    {
        $data = $this->form->getState();
        $phone = $this->normalizePhone($data['phone'] ?? null);
        
        $throttleKey = 'verify-otp:' . request()->ip() . ':' . $phone;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($throttleKey, 5)) { // 5 wrong attempts limits 
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'data.otp' => "Terlalu banyak percobaan kode yang salah. Tunggu {$seconds} detik.",
            ]);
        }

        $user = $phone ? $this->findActiveUserByPhone($phone) : null;
        $cached = $user ? Cache::get($this->otpCacheKey($user->phone)) : null;

        if (! $user || ! is_array($cached)
            || (int) ($cached['user_id'] ?? 0) !== (int) $user->id
            || ! Hash::check((string) ($data['otp'] ?? ''), (string) ($cached['otp_hash'] ?? ''))
        ) {
            \Illuminate\Support\Facades\RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'data.otp' => 'Kode OTP tidak valid atau sudah kedaluwarsa.',
            ]);
        }

        \Illuminate\Support\Facades\RateLimiter::clear($throttleKey);
        Cache::forget($this->otpCacheKey($user->phone));

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, true);
        session()->regenerate();

        if (!empty($cached['is_new'])) {
            $msg = 'Akun Anda telah berhasil didaftarkan. Ke depannya Anda bebas login menggunakan opsi OTP WhatsApp atau Password Email.';
            if (!empty($cached['generated_password'])) {
                $plainPassword = \Illuminate\Support\Facades\Crypt::decryptString($cached['generated_password']);
                $msg .= " Password default email Anda: **{$plainPassword}** (Harap ganti di menu profil).";
            }
            \Filament\Notifications\Notification::make()
                ->title('Pendaftaran Sukses!')
                ->body($msg)
                ->success()
                ->duration(10000)
                ->send();
        }

        $this->redirect('/admin');
    }

    public function changePhone(): void
    {
        $this->awaitingOtp = false;
        $this->requiresRegistration = false;
        $this->form->fill();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getPhoneFormComponent(),
                TextInput::make('name')
                    ->label('Nama Lengkap')
                    ->required()
                    ->maxLength(255)
                    ->visible(fn (): bool => $this->requiresRegistration && ! $this->awaitingOtp),
                TextInput::make('email')
                    ->label('Alamat Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->visible(fn (): bool => $this->requiresRegistration && ! $this->awaitingOtp),
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->required()
                    ->minLength(8)
                    ->visible(fn (): bool => $this->requiresRegistration && ! $this->awaitingOtp),
                TextInput::make('otp')
                    ->label('Kode OTP')
                    ->helperText('Masukkan 6 digit kode yang dikirim ke WhatsApp.')
                    ->required()
                    ->numeric()
                    ->length(6)
                    ->autocomplete('one-time-code')
                    ->autofocus()
                    ->visible(fn (): bool => $this->awaitingOtp),
            ]);
    }

    protected function getPhoneFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('Nomor HP')
            ->tel()
            ->required()
            ->maxLength(30)
            ->autocomplete('tel')
            ->disabled(fn (): bool => $this->awaitingOtp || $this->requiresRegistration || !$this->wagConfigured)
            ->dehydrated()
            ->autofocus();
    }

    public function loginAction(): Action
    {
        return Action::make('login')
            ->link()
            ->label('Kembali ke halaman masuk')
            ->icon(match (__('filament-panels::layout.direction')) {
                'rtl' => FilamentIcon::resolve(PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN_RTL) ?? Heroicon::ArrowRight,
                default => FilamentIcon::resolve(PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN) ?? Heroicon::ArrowLeft,
            })
            ->url(filament()->getLoginUrl());
    }

    public function getTitle(): string|Htmlable
    {
        if ($this->requiresRegistration && ! $this->awaitingOtp) {
            return 'Pendaftaran Akun Baru';
        }

        return 'Masuk dengan Nomor HP';
    }

    public function getHeading(): string|Htmlable|null
    {
        if ($this->requiresRegistration && ! $this->awaitingOtp) {
            return 'Pendaftaran Akun Baru';
        }

        return 'Masuk dengan Nomor HP';
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->awaitingOtp ? $this->getVerifyFormAction() : $this->getSendFormAction(),
        ];
    }

    protected function getSendFormAction(): Action
    {
        return Action::make('send')
            ->label($this->requiresRegistration ? 'Daftar & Kirim OTP' : 'Kirim OTP WhatsApp')
            ->disabled(!$this->wagConfigured)
            ->submit('send');
    }

    protected function getVerifyFormAction(): Action
    {
        return Action::make('verify')
            ->label('Verifikasi dan Masuk')
            ->submit('verify');
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (!$this->wagConfigured) {
            return 'WhatsApp Gateway belum dikonfigurasi. Silakan login menggunakan Email.';
        }

        if ($this->awaitingOtp) {
            return Action::make('changePhone')
                ->link()
                ->label('Ganti nomor atau kirim ulang OTP')
                ->action('changePhone');
        }

        if (! filament()->hasLogin()) {
            return null;
        }

        return $this->loginAction;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler(fn (): string => $this->awaitingOtp ? 'verify' : 'send')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->key('form-actions'),
            ]);
    }

    public function getView(): string
    {
        return 'filament.auth.phone-login';
    }

    public function hasLogo(): bool
    {
        return false;
    }
}
