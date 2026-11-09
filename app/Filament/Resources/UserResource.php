<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\RolesRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\TicketsRelationManager;
use App\Models\User;
use App\Support\PhoneNumber;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;
use STS\FilamentImpersonate\Actions\Impersonate;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Organisasi';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $form): Schema
    {
        // Kontrak kerja (nama, badan usaha, divisi asal, jabatan, level) mengikuti
        // Talenta dan ditimpa setiap sinkron, jadi dikunci untuk user dari Talenta.
        $fromTalenta = fn (?User $record): bool => static::isSyncedFromTalenta($record);
        $talentaEmail = fn (?User $record): bool => (bool) $record?->hasTalentaEmail();

        return $form
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make([
                    Section::make('Informasi Pengguna')
                        ->description(fn (?User $record): string => static::isSyncedFromTalenta($record)
                            ? 'Data kontrak mengikuti Talenta dan dikunci. Ubah di Talenta lalu import ulang JSON Talenta.'
                            : 'Kontrak kerja karyawan: satu badan usaha dan satu divisi asal.')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('Nama')
                                ->required()
                                ->maxLength(255)
                                ->disabled($fromTalenta)
                                // ID Karyawan tidak tampil saat membuat user, jadi
                                // nama mengisi baris pertama sendirian.
                                ->columnSpan(fn (string $operation): int|string => $operation === 'create' ? 'full' : 1),
                            Forms\Components\TextInput::make('identity')
                                ->label('ID Karyawan')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('Diisi otomatis dari Talenta')
                                ->hiddenOn('create'),
                            Forms\Components\Select::make('business_entity_id')
                                ->label('Badan Usaha')
                                ->relationship('businessEntity', 'name')
                                ->searchable()
                                ->preload()
                                ->disabled($fromTalenta)
                                // Select yang dikunci tetap memunculkan tombol hapus.
                                ->selectablePlaceholder(fn (?User $record): bool => ! static::isSyncedFromTalenta($record)),
                            Forms\Components\Select::make('unit_id')
                                ->label('Divisi Asal')
                                ->relationship('unit', 'name')
                                ->searchable()
                                ->preload()
                                ->disabled($fromTalenta)
                                // Select yang dikunci tetap memunculkan tombol hapus.
                                ->selectablePlaceholder(fn (?User $record): bool => ! static::isSyncedFromTalenta($record)),
                            Forms\Components\TextInput::make('job_title')
                                ->label('Jabatan')
                                ->maxLength(150)
                                ->disabled($fromTalenta),
                            Forms\Components\TextInput::make('job_level')
                                ->label('Level')
                                ->maxLength(100)
                                ->disabled($fromTalenta),
                        ]),
                    Section::make('Kontak & Login')
                        ->description('No. HP dipakai untuk login OTP WhatsApp dan notifikasi tiket.')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema([
                            Forms\Components\TextInput::make('phone')
                                ->label('No. HP / WhatsApp')
                                ->tel()
                                ->placeholder('08xx atau 628xx')
                                ->maxLength(15)
                                ->minLength(10)
                                ->dehydrateStateUsing(fn ($state) => PhoneNumber::canonical($state)),
                            Forms\Components\TextInput::make('email')
                                ->label('Email')
                                ->email()
                                ->maxLength(255)
                                ->disabled($talentaEmail)
                                ->helperText(fn (?User $record): ?string => $record?->hasTalentaEmail()
                                    ? 'Mengikuti Talenta.'
                                    : null),
                            Forms\Components\TextInput::make('password')
                                ->label('Password')
                                ->password()
                                ->revealable()
                                // Cegah browser mengisi password admin yang sedang login.
                                ->autocomplete('new-password')
                                ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                                ->dehydrated(fn ($state) => filled($state))
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                    ? 'Kosongkan bila password tidak diubah.'
                                    : null)
                                ->maxLength(255),
                        ]),
                ])->columnSpan(['lg' => 2]),
                Group::make([
                    Section::make('Status')
                        ->schema([
                            Forms\Components\Toggle::make('is_active')
                                ->label('Akun aktif')
                                ->helperText('User nonaktif tidak bisa masuk panel dan tidak menerima tiket.')
                                ->default(true)
                                ->required(),
                        ]),
                    Section::make('Penugasan')
                        ->description('Divisi yang tiketnya diterima dan ditangani user ini, di badan usaha mana pun.')
                        ->schema([
                            Forms\Components\Select::make('units')
                                ->label('Divisi yang ditangani')
                                ->relationship('units', 'name')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->helperText('Divisi asal selalu ikut ditangani.'),
                        ]),
                    Section::make('Verifikasi Email')
                        ->description(fn (?User $record): string => $record?->hasTalentaEmail()
                            ? 'Email dari Talenta otomatis tepercaya karena Talenta mewajibkan email aktif. Notifikasi email sudah aktif.'
                            : 'Notifikasi email baru dikirim setelah waktu dan sumber verifikasi diisi. Login tidak bergantung pada data ini.')
                        ->schema([
                            Forms\Components\DateTimePicker::make('email_verified_at')
                                ->label('Diverifikasi pada')
                                ->disabled($talentaEmail),
                            Forms\Components\Select::make('email_verified_via')
                                ->label('Sumber verifikasi')
                                ->options(static::emailVerificationSources())
                                // Sumber Talenta hanya diisi oleh sinkron Talenta. Untuk akun
                                // yang sudah bersumber Talenta field ini dikunci, tetapi nilainya
                                // tetap harus lolos validasi.
                                ->disableOptionWhen(fn (string $value, ?User $record): bool => $value === User::EMAIL_VERIFIED_VIA_TALENTA
                                    && ! $record?->hasTalentaEmail())
                                ->disabled($talentaEmail)
                                ->helperText(fn (?User $record): ?string => $record?->hasTalentaEmail()
                                    ? null
                                    : 'Pilih "Diverifikasi administrator" hanya setelah kepemilikan email diperiksa.'),
                        ]),
                ])->columnSpan(['lg' => 1]),
            ]);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make([
                    Section::make('Informasi Pengguna')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema([
                            Infolists\Components\TextEntry::make('name')
                                ->label('Nama')
                                ->weight(FontWeight::SemiBold),
                            Infolists\Components\TextEntry::make('identity')
                                ->label('ID Karyawan')
                                ->placeholder('-'),
                            Infolists\Components\TextEntry::make('businessEntity.name')
                                ->label('Badan Usaha')
                                ->placeholder('-'),
                            Infolists\Components\TextEntry::make('unit.name')
                                ->label('Divisi Asal')
                                ->placeholder('-'),
                            Infolists\Components\TextEntry::make('job_title')
                                ->label('Jabatan')
                                ->placeholder('-'),
                            Infolists\Components\TextEntry::make('job_level')
                                ->label('Level')
                                ->placeholder('-'),
                        ]),
                    Section::make('Kontak & Login')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema([
                            Infolists\Components\TextEntry::make('phone')
                                ->label('No. HP / WhatsApp')
                                ->icon('heroicon-m-phone')
                                ->copyable()
                                ->placeholder('-'),
                            Infolists\Components\TextEntry::make('email')
                                ->label('Email')
                                ->icon('heroicon-m-envelope')
                                ->copyable()
                                ->placeholder('-'),
                            Infolists\Components\TextEntry::make('email_verified_via')
                                ->label('Verifikasi email')
                                ->badge()
                                ->color('success')
                                ->formatStateUsing(fn (?string $state): string => static::emailVerificationSources()[$state] ?? (string) $state)
                                ->helperText(fn (User $record): ?string => $record->email_verified_at
                                    ? 'Sejak '.$record->email_verified_at->translatedFormat('d M Y H:i')
                                    : null)
                                ->placeholder('Belum diverifikasi')
                                ->columnSpanFull(),
                        ]),
                ])->columnSpan(['lg' => 2]),
                Group::make([
                    Section::make('Status')
                        ->schema([
                            Infolists\Components\TextEntry::make('is_active')
                                ->label('Status akun')
                                ->badge()
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                                ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                            Infolists\Components\TextEntry::make('roles.name')
                                ->label('Peran')
                                ->badge()
                                ->placeholder('Belum ada peran'),
                            Infolists\Components\TextEntry::make('talenta_synced_at')
                                ->label('Sinkron Talenta terakhir')
                                ->dateTime('d M Y H:i')
                                ->placeholder('Belum pernah'),
                        ]),
                    Section::make('Penugasan')
                        ->schema([
                            Infolists\Components\TextEntry::make('units.name')
                                ->label('Divisi yang ditangani')
                                ->badge()
                                ->placeholder('Belum ada divisi'),
                        ]),
                ])->columnSpan(['lg' => 1]),
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
                Tables\Columns\TextColumn::make('identity')
                    ->label('ID Karyawan')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('job_title')
                    ->label('Jabatan')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('unit.name')
                    ->label('Divisi Asal')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('businessEntity.name')
                    ->label('Badan Usaha')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('units.name')
                    ->label('Divisi ditangani')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('phone')
                    ->label('No. HP')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('unit_id')
                    ->label('Divisi Asal')
                    ->relationship('unit', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('business_entity_id')
                    ->label('Badan Usaha')
                    ->relationship('businessEntity', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktif'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->defaultSort('name')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->actions([
                Impersonate::make()
                    ->redirectTo(route('filament.admin.pages.dashboard'))
                    ->hidden(
                        fn () => ! auth()
                            ->user()
                            ->hasAnyRole(['Super Admin']),
                    ),
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
            ])
            ->bulkActions([
                Actions\DeleteBulkAction::make(),
                Actions\ForceDeleteBulkAction::make(),
                Actions\RestoreBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RolesRelationManager::class,
            TicketsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->byRole()
            ->with(['roles', 'units', 'unit', 'businessEntity'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * ID Karyawan hanya datang dari Talenta, jadi user yang memilikinya
     * kontrak kerjanya disinkronkan dari Talenta.
     */
    public static function isSyncedFromTalenta(?User $user): bool
    {
        return $user?->exists === true && filled($user->getOriginal('identity'));
    }

    /**
     * @return array<string, string>
     */
    public static function emailVerificationSources(): array
    {
        return [
            'admin' => 'Diverifikasi administrator',
            User::EMAIL_VERIFIED_VIA_TALENTA => 'Talenta',
            'email_link' => 'Tautan verifikasi email',
            'socialite' => 'Penyedia Socialite',
            'legacy_review_required' => 'Data lama — perlu ditinjau',
        ];
    }
}
