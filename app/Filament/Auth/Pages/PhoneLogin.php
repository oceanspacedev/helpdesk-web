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

    public function mount(): void
    {
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

    public function send(WhatsAppGateway $whatsAppGateway): void
    {
        $data = $this->form->getState();
        $phone = $this->normalizePhone($data['phone'] ?? null);
        $user = $phone ? $this->findActiveUserByPhone($phone) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'data.phone' => 'Nomor HP belum terdaftar atau tidak aktif.',
            ]);
        }

        $otp = (string) random_int(100000, 999999);
        $ttlMinutes = max(1, (int) config('services.phone_otp_login.ttl_minutes', 5));
        $message = "Kode OTP Helpdesk Anda: {$otp}\nBerlaku {$ttlMinutes} menit. Jangan bagikan kode ini kepada siapa pun.";

        if (! $whatsAppGateway->send($user->phone, $message)) {
            throw ValidationException::withMessages([
                'data.phone' => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
            ]);
        }

        Cache::put($this->otpCacheKey($user->phone), [
            'user_id' => $user->id,
            'otp_hash' => Hash::make($otp),
        ], now()->addMinutes($ttlMinutes));

        session()->flashInput(['phone' => $user->phone]);
        session()->flash('status', 'Kode OTP sudah dikirim ke WhatsApp.');

        $this->redirect(route('phone-login.verify'));
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

    public function getTitle(): string | Htmlable
    {
        return 'Masuk dengan Nomor HP';
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Masuk dengan Nomor HP';
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSendFormAction(),
        ];
    }

    protected function getSendFormAction(): Action
    {
        return Action::make('send')
            ->label('Kirim OTP WhatsApp')
            ->submit('send');
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function getSubheading(): string | Htmlable | null
    {
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
            ->livewireSubmitHandler('send')
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
