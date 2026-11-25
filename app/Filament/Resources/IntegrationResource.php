<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationResource\Pages;
use App\Models\Integration;
use App\Services\Integrations\External\ExternalSystemException;
use App\Services\Integrations\IntegrationConnector;
use App\Services\Integrations\IntegrationHealthCheck;
use App\Services\Integrations\IntegrationSelfTest;
use App\Support\OutboundUrl;
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
use Illuminate\Support\HtmlString;
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
                    ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (is_string($value) && ($problem = OutboundUrl::problem($value)) !== null) {
                            $fail($problem);
                        }
                    }])
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
                Tables\Columns\TextColumn::make('last_check_message')
                    ->label('Status koneksi')
                    ->placeholder('Belum pernah dites')
                    ->wrap()
                    ->limit(160)
                    ->color(fn (Integration $record): string => match (true) {
                        $record->last_check_ok === false => 'danger',
                        str_contains((string) $record->last_check_message, 'belum diimpor'),
                        str_contains((string) $record->last_check_message, 'tidak ada lagi') => 'warning',
                        default => 'gray',
                    }),
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
                Actions\Action::make('syncCategories')
                    ->label('Sinkron kategori')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Integration $record): string => "Sinkron kategori {$record->name}")
                    ->modalDescription('Kategori aktif baru di sistem tujuan ditambahkan ke unit yang memakai integrasi ini. Kategori yang Anda hapus tidak dipulihkan, dan kategori yang tidak ada lagi di sistem tujuan tidak dihapus.')
                    ->modalSubmitActionLabel('Sinkron')
                    ->authorize(fn (Integration $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->visible(fn (Integration $record): bool => $record->units()->exists())
                    ->action(fn (Integration $record) => static::syncCategories($record)),
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
                Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Tes koneksi = tes menyeluruh tanpa tiket: token dan kategori, form
     * setiap kategori unit, lalu webhook bolak-balik (IntegrationSelfTest).
     * Mengembalikan kesehatan koneksi dasarnya.
     */
    public static function testConnection(Integration $integration): bool
    {
        $result = app(IntegrationSelfTest::class)->run($integration);
        $health = $result->health;

        Notification::make()
            ->title(match (true) {
                ! $health->ok => 'Koneksi gagal',
                ! $result->passed() => 'Terhubung, tetapi ada yang bermasalah',
                default => 'Koneksi berhasil',
            })
            ->body(new HtmlString(nl2br(e($result->summary()))))
            ->status(match (true) {
                ! $result->passed() => 'danger',
                $health->newCategories !== [] || $health->missingCategories !== [] => 'warning',
                default => 'success',
            })
            ->persistent(! $result->passed())
            ->send();

        return $health->ok;
    }

    public static function syncCategories(Integration $integration): void
    {
        try {
            $result = app(IntegrationConnector::class)->syncCategories($integration);
        } catch (ExternalSystemException $exception) {
            Notification::make()->title('Sinkron gagal')->body($exception->getMessage())->danger()->send();

            return;
        }

        $health = app(IntegrationHealthCheck::class)->run($integration);
        $lines = [];
        if ($result['created'] !== []) {
            $lines[] = count($result['created']).' kategori ditambahkan: '.implode(', ', $result['created']).'.';
        }
        if ($result['mapped'] !== []) {
            $lines[] = count($result['mapped']).' kategori lama dipetakan: '.implode(', ', $result['mapped']).'.';
        }
        if ($lines === []) {
            $lines[] = 'Semua kategori sudah sesuai.';
        }
        if ($health->missingCategories !== []) {
            $lines[] = 'Tidak ada lagi di sistem tujuan (tidak dihapus): '.implode(', ', $health->missingCategories).'.';
        }

        Notification::make()
            ->title('Sinkron kategori selesai')
            ->body(implode(' ', $lines))
            ->status($health->missingCategories === [] ? 'success' : 'warning')
            ->send();
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
