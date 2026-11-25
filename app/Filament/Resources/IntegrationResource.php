<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationResource\Pages;
use App\Models\Integration;
use App\Services\Integrations\External\ExternalSystemClient;
use App\Services\Integrations\External\ExternalSystemException;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Master sistem eksternal (kontrak docs/integrations/README.md). Token dan
 * rahasia webhook hanya bisa ditulis: nilai tersimpan tidak pernah
 * ditampilkan, dan field kosong saat ubah berarti nilai lama dipertahankan.
 */
class IntegrationResource extends Resource
{
    protected static ?string $model = Integration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'heroicon-s-puzzle-piece';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'Integrasi';

    protected static ?string $pluralModelLabel = 'Integrasi';

    protected static ?string $navigationLabel = 'Integrasi';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nama sistem')
                    ->placeholder('Contoh: Modul OD')
                    ->required()
                    ->maxLength(100)
                    ->columnSpan('full'),
                Forms\Components\TextInput::make('base_url')
                    ->label('Base URL')
                    ->placeholder('https://modul.example.com/api/helpdesk/v1')
                    ->helperText('Sudah termasuk prefix versi. Helpdesk memanggil {base_url}/categories, /forms/{kode}, dan /tickets.')
                    ->url()
                    ->required()
                    ->maxLength(500)
                    ->columnSpan('full'),
                static::secretInput('token')
                    ->label('Token (Bearer)'),
                static::secretInput('webhook_secret')
                    ->label('Rahasia webhook')
                    ->suffixAction(
                        Actions\Action::make('generateWebhookSecret')
                            ->label('Buat acak')
                            ->icon('heroicon-m-sparkles')
                            ->action(fn (Set $set) => $set('webhook_secret', Str::random(48))),
                    ),
                Forms\Components\TextInput::make('timeout_seconds')
                    ->label('Timeout (detik)')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(Integration::MAX_TIMEOUT_SECONDS)
                    ->default(Integration::DEFAULT_TIMEOUT_SECONDS)
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
                Infolists\Components\TextEntry::make('webhook_url')
                    ->label('URL webhook untuk sistem eksternal')
                    ->state(fn (?Integration $record): ?string => $record?->webhookUrl())
                    ->copyable()
                    ->visible(fn (?Integration $record): bool => $record !== null)
                    ->columnSpan('full'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('base_url')
                    ->label('Base URL')
                    ->limit(50)
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                Tables\Columns\TextColumn::make('units_count')
                    ->label('Unit')
                    ->counts('units'),
                Tables\Columns\IconColumn::make('last_check_ok')
                    ->label('Tes terakhir')
                    ->boolean()
                    ->tooltip(fn (Integration $record): ?string => $record->last_check_message),
                Tables\Columns\TextColumn::make('last_checked_at')
                    ->label('Dites pada')
                    ->dateTime()
                    ->placeholder('Belum pernah')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->actions([
                Actions\Action::make('testConnection')
                    ->label('Tes koneksi')
                    ->icon('heroicon-o-signal')
                    ->authorize(fn (Integration $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->action(fn (Integration $record) => static::testConnection($record)),
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
                Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Tes koneksi memanggil GET {base}/categories dan menyimpan hasilnya.
     */
    public static function testConnection(Integration $integration): bool
    {
        try {
            $categories = ExternalSystemClient::for($integration)->categories();
            $ok = true;
            $message = 'Terhubung. '.count($categories).' kategori ditemukan.';
        } catch (ExternalSystemException $exception) {
            $ok = false;
            $message = Str::limit($exception->getMessage(), 480);
        }

        $integration->forceFill([
            'last_checked_at' => now(),
            'last_check_ok' => $ok,
            'last_check_message' => $message,
        ])->save();

        Notification::make()
            ->title($ok ? 'Koneksi berhasil' : 'Koneksi gagal')
            ->body($message)
            ->status($ok ? 'success' : 'danger')
            ->send();

        return $ok;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageIntegrations::route('/'),
        ];
    }

    private static function secretInput(string $name): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->password()
            ->revealable(false)
            ->autocomplete('new-password')
            ->maxLength(500)
            // Nilai tersimpan tidak pernah diisi ke form.
            ->formatStateUsing(fn (): ?string => null)
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->required(fn (string $operation): bool => $operation === 'create')
            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                ? 'Nilai tersimpan tidak ditampilkan. Kosongkan untuk mempertahankannya.'
                : null)
            ->columnSpan('full');
    }
}
