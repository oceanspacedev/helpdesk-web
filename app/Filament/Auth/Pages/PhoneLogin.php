<?php

namespace App\Filament\Auth\Pages;

use App\Filament\Auth\Concerns\InteractsWithPhoneNumbers;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\Integrations\HelpdeskReporterRegistrationService;
use App\Services\WhatsAppOtpService;
use App\Support\PhoneNumber;
use App\Support\TalentaEmployee;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    public ?string $otpChallengeId = null;

    public ?string $registrationProofId = null;

    public function mount(): void
    {
        $this->wagConfigured = ! empty(config('services.whatsapp_gateway.url')) && ! empty(config('services.whatsapp_gateway.token'));

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

    public function send(WhatsAppOtpService $otpService): void
    {
        $data = $this->form->getState();
        $phone = $this->normalizePhone($data['phone'] ?? null);

        if (! $phone) {
            throw ValidationException::withMessages([
                'data.phone' => 'Nomor HP tidak valid.',
            ]);
        }

        // Directory lookup deliberately happens only after the OTP proves
        // control of this number. Keep the pre-OTP response identical for
        // active, inactive, Talenta, and previously unknown numbers.
        $this->clearRegistrationProof();
        $this->requiresRegistration = false;

        $issued = $otpService->issue(
            'login',
            $this->otpSubject(),
            $this->otpPrincipal(),
            $phone,
            rotate: true,
            tenant: $this->otpTenant(),
        );
        if (! ($issued['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'data.phone' => $this->otpIssueFailureMessage($issued),
            ]);
        }

        $challengeId = (string) ($issued['challenge_id'] ?? '');
        if ($challengeId === '') {
            throw ValidationException::withMessages([
                'data.phone' => 'OTP belum bisa dibuat. Coba lagi sebentar lagi.',
            ]);
        }

        if ($this->otpChallengeId) {
            Cache::forget($this->pendingRegistrationKey($this->otpChallengeId));
        }

        $expiresAt = Carbon::parse((string) $issued['expires_at']);
        $stored = Cache::put($this->pendingRegistrationKey($challengeId), [
            'type' => 'unresolved',
            'phone_hash' => hash('sha256', $phone),
        ], $expiresAt);
        if (! $stored) {
            throw ValidationException::withMessages([
                'data.phone' => 'Sesi OTP tidak dapat disimpan. Mulai ulang proses login.',
            ]);
        }

        $this->otpChallengeId = $challengeId;
        $this->awaitingOtp = true;
        $this->form->fill([
            'phone' => $phone,
            'name' => null,
            'otp' => null,
        ]);
    }

    public function verify(
        WhatsAppOtpService $otpService,
        EmployeeService $employeeService,
    ): void {
        $data = $this->form->getState();
        $phone = $this->normalizePhone($data['phone'] ?? null);
        $challengeId = (string) ($this->otpChallengeId ?? '');
        $pending = $challengeId !== ''
            ? Cache::get($this->pendingRegistrationKey($challengeId))
            : null;
        if ($phone === null || ! is_array($pending)) {
            throw ValidationException::withMessages([
                'data.otp' => 'Kode OTP tidak valid atau sudah kedaluwarsa.',
            ]);
        }

        $verified = $otpService->verify(
            'login',
            $this->otpSubject(),
            $this->otpPrincipal(),
            $challengeId,
            (string) ($data['otp'] ?? ''),
        );
        if (! ($verified['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'data.otp' => $this->otpVerifyFailureMessage($verified),
            ]);
        }

        $verifiedPhone = $this->normalizePhone($verified['phone'] ?? null);
        if ($verifiedPhone === null
            || ! hash_equals($phone, $verifiedPhone)
            || ! hash_equals((string) ($pending['phone_hash'] ?? ''), hash('sha256', $verifiedPhone))) {
            $this->resetAfterConsumedOtp($challengeId);

            throw ValidationException::withMessages([
                'data.phone' => 'Identitas nomor OTP tidak cocok. Mulai ulang proses login.',
            ]);
        }

        if (! hash_equals('unresolved', (string) ($pending['type'] ?? ''))) {
            $this->resetAfterConsumedOtp($challengeId);

            throw ValidationException::withMessages([
                'data.phone' => 'Sesi verifikasi tidak valid. Mulai ulang proses login.',
            ]);
        }

        try {
            $resolved = $this->resolveAfterVerifiedOtp($verifiedPhone, $employeeService);
        } catch (Throwable $exception) {
            $this->resetAfterConsumedOtp($challengeId);

            throw $exception;
        }

        if (is_array($resolved) && ($resolved['user'] ?? null) instanceof User) {
            $this->resetAfterConsumedOtp($challengeId);
            $this->loginResolvedUser($resolved['user'], (bool) ($resolved['is_new'] ?? false));

            return;
        }

        $proofId = Str::random(40);
        $proofStored = Cache::put($this->registrationProofKey($proofId), [
            'purpose' => 'manual-phone-registration',
            'phone_hash' => hash('sha256', $verifiedPhone),
            'session_hash' => $this->registrationSessionHash(),
        ], now()->addMinutes($this->registrationProofTtlMinutes()));
        if (! $proofStored) {
            $this->resetAfterConsumedOtp($challengeId);

            throw ValidationException::withMessages([
                'data.phone' => 'Bukti verifikasi tidak dapat disimpan. Mulai ulang proses login.',
            ]);
        }

        Cache::forget($this->pendingRegistrationKey($challengeId));
        $this->otpChallengeId = null;
        $this->registrationProofId = $proofId;
        $this->awaitingOtp = false;
        $this->requiresRegistration = true;
        $this->form->fill([
            'phone' => $verifiedPhone,
            'name' => null,
            'otp' => null,
        ]);

        Notification::make()
            ->title('Nomor HP Belum Terdaftar')
            ->body('Nomor sudah terverifikasi. Lengkapi nama untuk membuat akun phone-only tanpa OTP kedua.')
            ->info()
            ->send();
    }

    public function completeRegistration(HelpdeskReporterRegistrationService $registrations): void
    {
        $data = $this->form->getState();
        $phone = $this->normalizePhone($data['phone'] ?? null);
        $proofId = trim((string) ($this->registrationProofId ?? ''));
        $proof = $proofId !== '' ? Cache::get($this->registrationProofKey($proofId)) : null;

        if ($phone === null || ! $this->registrationProofIsValid($proof, $phone)) {
            $this->clearRegistrationProof();
            $this->requiresRegistration = false;

            throw ValidationException::withMessages([
                'data.phone' => 'Bukti verifikasi sudah tidak valid atau kedaluwarsa. Mulai ulang proses login.',
            ]);
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'data.name' => (string) $validator->errors()->first('name'),
            ]);
        }

        $result = $registrations->createPhoneOnlyForVerifiedPhone(
            $phone,
            (string) ($data['name'] ?? ''),
        );
        $user = $result['user'] ?? null;
        if (($result['ok'] ?? false) && $user instanceof User) {
            $this->clearRegistrationProof();
            $this->requiresRegistration = false;
            $this->loginResolvedUser($user, (bool) ($result['created'] ?? false));

            return;
        }

        $status = (string) ($result['status'] ?? 'conflict');
        if (in_array($status, ['invalid_name', 'busy'], true)) {
            throw ValidationException::withMessages([
                'data.name' => (string) ($result['message'] ?? 'Pendaftaran belum dapat diproses. Coba lagi.'),
            ]);
        }

        $this->clearRegistrationProof();
        $this->requiresRegistration = false;

        throw ValidationException::withMessages([
            'data.phone' => $this->restartMessage(
                (string) ($result['message'] ?? 'Data nomor berubah selama pendaftaran.'),
            ),
        ]);
    }

    public function changePhone(): void
    {
        if ($this->otpChallengeId) {
            Cache::forget($this->pendingRegistrationKey($this->otpChallengeId));
        }
        $this->clearRegistrationProof();

        $this->otpChallengeId = null;
        $this->awaitingOtp = false;
        $this->requiresRegistration = false;
        $this->form->fill();
    }

    /**
     * @return null|array{user: User, is_new: bool}
     */
    private function resolveAfterVerifiedOtp(string $phone, EmployeeService $employeeService): ?array
    {
        $users = $this->usersByPhone($phone);
        if ($users->count() > 1) {
            throw ValidationException::withMessages([
                'data.phone' => 'Nomor HP cocok dengan lebih dari satu akun. Hubungi administrator untuk merapikan data. Mulai ulang proses login.',
            ]);
        }

        $user = $users->first();
        if ($user && ($user->trashed() || ! $user->is_active)) {
            throw ValidationException::withMessages([
                'data.phone' => 'Nomor HP terdaftar namun akun Anda dinonaktifkan. Hubungi administrator. Mulai ulang proses login.',
            ]);
        }
        if ($user) {
            return [
                'user' => $user,
                'is_new' => false,
            ];
        }

        $employeeMatches = $employeeService->findAllByPhone($phone);
        if (count($employeeMatches) > 1) {
            throw ValidationException::withMessages([
                'data.phone' => 'Nomor HP cocok dengan lebih dari satu data karyawan Talenta. Hubungi administrator untuk merapikan data. Mulai ulang proses login.',
            ]);
        }

        $employee = $employeeMatches[0] ?? null;
        if (! is_array($employee)) {
            return null;
        }

        return [
            'user' => $this->createTalentaUserAfterVerifiedOtp($employee, $phone, $employeeService),
            'is_new' => true,
        ];
    }

    private function createTalentaUserAfterVerifiedOtp(
        array $employee,
        string $phone,
        EmployeeService $employeeService,
    ): User {
        $lock = Cache::lock((string) PhoneNumber::reporterWriteLockKey($phone), 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'data.phone' => 'Pendaftaran nomor ini sedang diproses. Mulai ulang proses login sebentar lagi.',
            ]);
        }

        try {
            return DB::transaction(function () use ($employee, $phone, $employeeService): User {
                $matches = User::query()
                    ->withTrashed()
                    ->where('phone_normalized', $phone)
                    ->lockForUpdate()
                    ->get();
                if ($matches->isNotEmpty()) {
                    throw new DomainException('Nomor HP sudah terdaftar saat OTP diverifikasi. Mulai ulang proses login.');
                }

                return $this->createTalentaUser($employee, $phone, $employeeService);
            }, 3);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'data.phone' => $this->restartMessage($exception->getMessage()),
            ]);
        } catch (QueryException $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'data.phone' => 'Akun tidak dapat dibuat karena data nomor atau email sudah dipakai. Mulai ulang proses login.',
            ]);
        } finally {
            $lock->release();
        }
    }

    private function createTalentaUser(array $employee, string $phone, EmployeeService $employeeService): User
    {
        $employeeMatches = $employeeService->findAllByPhone($phone);
        $currentEmployee = count($employeeMatches) === 1 ? $employeeMatches[0] : null;
        if (! is_array($currentEmployee)
            || ! in_array($phone, TalentaEmployee::normalizedPhones($employee), true)
            || ! hash_equals(
                TalentaEmployee::identityFingerprint($employee),
                TalentaEmployee::identityFingerprint($currentEmployee),
            )) {
            throw new DomainException('Data Talenta berubah atau ambigu selama OTP diverifikasi. Mulai ulang proses login.');
        }

        return $employeeService->createUser($currentEmployee, null, $phone);
    }

    private function loginResolvedUser(User $user, bool $isNew): void
    {
        Auth::login($user, false);
        session()->regenerate();
        session()->put('helpdesk_phone_verified_user_id', $user->id);

        if ($isNew) {
            Notification::make()
                ->title('Pendaftaran Sukses!')
                ->body('Akun Anda berhasil didaftarkan. Gunakan OTP WhatsApp untuk masuk. Email hanya dapat dipakai setelah kepemilikannya diverifikasi secara terpisah.')
                ->success()
                ->duration(10000)
                ->send();
        }

        $this->redirect('/admin');
    }

    private function resetAfterConsumedOtp(string $challengeId): void
    {
        Cache::forget($this->pendingRegistrationKey($challengeId));
        if ($this->otpChallengeId !== null && hash_equals($this->otpChallengeId, $challengeId)) {
            $this->otpChallengeId = null;
        }
        $this->awaitingOtp = false;
        $this->requiresRegistration = false;
        $this->clearRegistrationProof();
        if (is_array($this->data)) {
            $this->data['otp'] = null;
        }
    }

    private function registrationProofIsValid(mixed $proof, string $phone): bool
    {
        return is_array($proof)
            && hash_equals('manual-phone-registration', (string) ($proof['purpose'] ?? ''))
            && hash_equals(hash('sha256', $phone), (string) ($proof['phone_hash'] ?? ''))
            && hash_equals($this->registrationSessionHash(), (string) ($proof['session_hash'] ?? ''));
    }

    private function clearRegistrationProof(): void
    {
        $proofId = trim((string) ($this->registrationProofId ?? ''));
        if ($proofId !== '') {
            $key = $this->registrationProofKey($proofId);
            $proof = Cache::get($key);
            if (is_array($proof)
                && hash_equals($this->registrationSessionHash(), (string) ($proof['session_hash'] ?? ''))) {
                Cache::forget($key);
            }
        }

        $this->registrationProofId = null;
    }

    private function registrationProofKey(string $proofId): string
    {
        return 'phone-login:verified-registration:'.hash('sha256', $proofId);
    }

    private function registrationSessionHash(): string
    {
        return hash('sha256', Session::getId());
    }

    private function registrationProofTtlMinutes(): int
    {
        return max(1, (int) config('services.phone_otp_login.ttl_minutes', 5));
    }

    private function restartMessage(string $message): string
    {
        $message = trim($message);
        if (Str::contains(Str::lower($message), 'mulai ulang proses login')) {
            return $message;
        }

        return rtrim($message, '.').'. Mulai ulang proses login.';
    }

    private function pendingRegistrationKey(string $challengeId): string
    {
        return 'phone-login:pending:'.hash('sha256', $challengeId);
    }

    private function otpSubject(): string
    {
        return 'phone-login';
    }

    private function otpPrincipal(): string
    {
        return 'phone-login-session:'.hash('sha256', Session::getId());
    }

    private function otpTenant(): string
    {
        return 'phone-login-ip:'.hash('sha256', (string) request()->ip());
    }

    private function otpIssueFailureMessage(array $result): string
    {
        return match ((string) ($result['status'] ?? '')) {
            'rate_limited' => 'Terlalu banyak permintaan OTP. Silakan coba lagi nanti.',
            'busy' => 'Pembuatan OTP sedang sibuk. Coba lagi sebentar lagi.',
            'invalid_phone' => 'Nomor HP tidak valid.',
            default => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
        };
    }

    private function otpVerifyFailureMessage(array $result): string
    {
        return match ((string) ($result['status'] ?? '')) {
            'rate_limited' => 'Terlalu banyak percobaan kode yang salah. Coba lagi nanti.',
            'busy' => 'Verifikasi OTP sedang diproses. Coba lagi sebentar lagi.',
            default => 'Kode OTP tidak valid atau sudah kedaluwarsa.',
        };
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
            ->disabled(fn (): bool => $this->awaitingOtp || $this->requiresRegistration || ! $this->wagConfigured)
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
            match (true) {
                $this->awaitingOtp => $this->getVerifyFormAction(),
                $this->requiresRegistration => $this->getCompleteRegistrationFormAction(),
                default => $this->getSendFormAction(),
            },
        ];
    }

    protected function getSendFormAction(): Action
    {
        return Action::make('send')
            ->label('Kirim OTP WhatsApp')
            ->disabled(! $this->wagConfigured)
            ->submit('send');
    }

    protected function getVerifyFormAction(): Action
    {
        return Action::make('verify')
            ->label('Verifikasi OTP')
            ->submit('verify');
    }

    protected function getCompleteRegistrationFormAction(): Action
    {
        return Action::make('completeRegistration')
            ->label('Buat Akun & Masuk')
            ->submit('completeRegistration');
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->wagConfigured) {
            return 'WhatsApp Gateway belum dikonfigurasi. Silakan login menggunakan Email.';
        }

        if ($this->awaitingOtp) {
            return Action::make('changePhone')
                ->link()
                ->label('Ganti nomor atau kirim ulang OTP')
                ->action('changePhone');
        }

        if ($this->requiresRegistration) {
            return 'Nomor HP sudah terverifikasi tetapi belum terdaftar. Lengkapi nama untuk membuat akun phone-only:';
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
            ->livewireSubmitHandler(fn (): string => match (true) {
                $this->awaitingOtp => 'verify',
                $this->requiresRegistration => 'completeRegistration',
                default => 'send',
            })
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
