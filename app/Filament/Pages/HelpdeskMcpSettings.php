<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Integrations\HelpdeskMcpConfiguration;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * @property-read Schema $form
 */
class HelpdeskMcpSettings extends Page
{
    protected static ?string $title = 'Pengaturan MCP';

    protected static ?string $navigationLabel = 'Pengaturan MCP';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'pengaturan-mcp';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Kelola akses dan perilaku Helpdesk MCP tanpa mengubah file environment.';
    }

    public function mount(): void
    {
        $this->form->fill(app(HelpdeskMcpConfiguration::class)->formData());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Klien HTTP MCP')
                    ->description('Setiap token adalah identitas klien yang berbeda. Saat rotasi, pertahankan token lama sampai intake aktif selesai.')
                    ->schema([
                        Repeater::make('tokens')
                            ->label('Token aktif')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nama klien')
                                    ->placeholder('Contoh: WhatsApp Gateway')
                                    ->required()
                                    ->maxLength(100),
                                TextInput::make('token')
                                    ->label('Token')
                                    ->password()
                                    ->revealable()
                                    ->copyable(copyMessage: 'Token disalin')
                                    ->default(fn (): string => 'hdm_'.Str::random(48))
                                    ->required()
                                    ->minLength(32)
                                    ->maxLength(255)
                                    ->distinct()
                                    ->helperText('Salin token ini ke klien MCP. Token tersimpan terenkripsi di database.'),
                                Toggle::make('active')
                                    ->label('Aktif')
                                    ->default(true),
                                Toggle::make('workflow_enabled')
                                    ->label('Akses Proses/Done')
                                    ->default(false)
                                    ->live()
                                    ->helperText('Aktifkan hanya untuk token personal satu petugas, bukan token gateway bersama.'),
                                Select::make('workflow_user_id')
                                    ->label('PIC token ini')
                                    ->options(fn (): array => User::query()
                                        ->where('is_active', true)
                                        ->whereHas(
                                            'roles',
                                            fn ($roles) => $roles->whereIn('name', [
                                                'Super Admin',
                                                'Master Admin',
                                                'Admin Unit',
                                                'Staff Unit',
                                            ]),
                                        )
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->searchable()
                                    ->required(fn (Get $get): bool => (bool) $get('workflow_enabled'))
                                    ->disabled(fn (Get $get): bool => ! (bool) $get('workflow_enabled'))
                                    ->dehydrated()
                                    ->helperText('Semua perubahan status dan PIC akan dicatat atas nama petugas ini.'),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 5,
                            ])
                            ->defaultItems(0)
                            ->maxItems(25)
                            ->addActionLabel('Tambah token')
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->reorderable(false)
                            ->collapsible(),
                    ]),
                Section::make('Sesi dan batas akses')
                    ->description('Nilai ini berlaku langsung untuk semua node aplikasi yang memakai database yang sama.')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->schema([
                        TextInput::make('intake_ttl_minutes')
                            ->label('Masa aktif intake')
                            ->integer()
                            ->required()
                            ->minValue(10)
                            ->maxValue(1440)
                            ->suffix('menit')
                            ->helperText('Draft percakapan akan kedaluwarsa setelah waktu ini.'),
                        TextInput::make('rate_limit_per_minute')
                            ->label('Batas request')
                            ->integer()
                            ->required()
                            ->minValue(60)
                            ->maxValue(600)
                            ->suffix('/ menit')
                            ->helperText('Diterapkan per token; batas per IP adalah dua kali nilai ini.'),
                    ]),
                Section::make('Gateway tepercaya')
                    ->description('Opsional. Digunakan untuk memverifikasi assertion HMAC dari gateway WhatsApp tepercaya.')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->schema([
                        TextInput::make('identity_assertion_secret')
                            ->label('Secret assertion baru')
                            ->password()
                            ->revealable()
                            ->minLength(32)
                            ->maxLength(255)
                            ->dehydrated(fn (#[SensitiveParameter] $state): bool => filled($state))
                            ->helperText(fn (): string => $this->assertionSecretHelpText()),
                        TextInput::make('identity_assertion_leeway_seconds')
                            ->label('Toleransi waktu assertion')
                            ->integer()
                            ->required()
                            ->minValue(30)
                            ->maxValue(3600)
                            ->suffix('detik'),
                        Toggle::make('clear_identity_assertion_secret')
                            ->label('Nonaktifkan assertion tepercaya saat disimpan')
                            ->helperText('Nilai database akan dikosongkan dan fallback environment akan diabaikan.'),
                    ]),
                Section::make('Lanjutan')
                    ->description('Biarkan kosong kecuali Anda memahami dampaknya terhadap binding identitas reporter.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('identity_pepper')
                            ->label('Identity pepper baru')
                            ->password()
                            ->revealable()
                            ->minLength(32)
                            ->maxLength(255)
                            ->dehydrated(fn (#[SensitiveParameter] $state): bool => filled($state))
                            ->helperText(fn (): string => $this->identityPepperHelpText()),
                        Toggle::make('reset_identity_pepper')
                            ->label('Gunakan APP_KEY saat disimpan')
                            ->helperText('Mengganti pepper membuat binding reporter lama tidak dapat digunakan kembali.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $configuration = app(HelpdeskMcpConfiguration::class);
        $configuration->save($this->form->getState());
        $this->form->fill($configuration->formData());

        Notification::make()
            ->success()
            ->title('Pengaturan MCP disimpan')
            ->body('Perubahan berlaku pada request berikutnya.')
            ->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    EmbeddedSchema::make('form'),
                ])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Simpan pengaturan')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ]);
    }

    private function assertionSecretHelpText(): string
    {
        return match (app(HelpdeskMcpConfiguration::class)->identityAssertionSecretSource()) {
            'database' => 'Secret sudah tersimpan di database. Kosongkan kolom ini untuk mempertahankannya.',
            'environment' => 'Saat ini masih memakai fallback environment. Isi kolom untuk memindahkannya ke database.',
            default => 'Belum diatur. Biarkan kosong bila gateway tepercaya tidak digunakan.',
        };
    }

    private function identityPepperHelpText(): string
    {
        return match (app(HelpdeskMcpConfiguration::class)->identityPepperSource()) {
            'database' => 'Pepper sudah tersimpan di database. Kosongkan kolom ini untuk mempertahankannya.',
            'environment' => 'Saat ini masih memakai fallback environment. Isi kolom untuk memindahkannya ke database.',
            default => 'Saat ini memakai APP_KEY. Mengubah nilai akan memutus binding reporter yang sudah ada.',
        };
    }
}
