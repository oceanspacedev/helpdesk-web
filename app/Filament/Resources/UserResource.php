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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
        $talentaHint = fn (?User $record): ?string => static::isSyncedFromTalenta($record)
            ? 'Mengikuti Talenta. Ubah di Talenta lalu import ulang JSON Talenta.'
            : null;

        return $form
            ->schema([
                Section::make('Informasi Pengguna')
                    ->description('Kontrak kerja karyawan: satu badan usaha dan satu divisi asal.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255)
                            ->disabled($fromTalenta)
                            ->helperText($talentaHint),
                        Forms\Components\TextInput::make('identity')
                            ->label('ID Karyawan')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Diisi dari Talenta.'),
                        Forms\Components\Select::make('business_entity_id')
                            ->label('Badan Usaha')
                            ->relationship('businessEntity', 'name')
                            ->searchable()
                            ->preload()
                            ->disabled($fromTalenta)
                            ->helperText($talentaHint),
                        Forms\Components\Select::make('unit_id')
                            ->label('Divisi Asal')
                            ->relationship('unit', 'name')
                            ->searchable()
                            ->preload()
                            ->disabled($fromTalenta)
                            ->helperText($talentaHint),
                        Forms\Components\TextInput::make('job_title')
                            ->label('Jabatan')
                            ->maxLength(150)
                            ->disabled($fromTalenta)
                            ->helperText($talentaHint),
                        Forms\Components\TextInput::make('job_level')
                            ->label('Level')
                            ->maxLength(100)
                            ->disabled($fromTalenta)
                            ->helperText($talentaHint),
                    ])
                    ->columns(2),
                Section::make('Penugasan')
                    ->description('Divisi yang tiketnya diterima dan ditangani user ini, di badan usaha mana pun.')
                    ->schema([
                        Forms\Components\Select::make('units')
                            ->label('Divisi yang ditangani')
                            ->relationship('units', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Divisi asal selalu ikut ditangani. Tambahkan divisi lain bila user juga menanganinya.'),
                    ]),
                Section::make('Akses & Keamanan')
                    ->schema([
                        Forms\Components\TextInput::make('phone')
                            ->label('No. HP / WhatsApp')
                            ->tel()
                            ->maxLength(15)
                            ->minLength(10)
                            ->helperText('Dipakai untuk login OTP WhatsApp dan notifikasi tiket.')
                            ->dehydrateStateUsing(fn ($state) => PhoneNumber::canonical($state)),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\DateTimePicker::make('email_verified_at')
                            ->label('Email diverifikasi pada')
                            ->helperText('Notifikasi email baru dikirim setelah waktu verifikasi dan sumber tepercaya diisi. Login tidak bergantung pada kolom ini.'),
                        Forms\Components\Select::make('email_verified_via')
                            ->label('Sumber verifikasi email')
                            ->options(static::emailVerificationSources())
                            ->helperText('Pilih "Diverifikasi administrator" hanya setelah kepemilikan email diperiksa.'),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('User nonaktif tidak bisa masuk panel dan tidak menerima tiket.')
                            ->default(true)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make('Informasi Pengguna')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Nama'),
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
                        Infolists\Components\TextEntry::make('talenta_synced_at')
                            ->label('Sinkron Talenta terakhir')
                            ->dateTime()
                            ->placeholder('Belum pernah'),
                    ])
                    ->columns(2),
                Section::make('Penugasan')
                    ->schema([
                        Infolists\Components\TextEntry::make('units.name')
                            ->label('Divisi yang ditangani')
                            ->badge()
                            ->placeholder('-'),
                    ]),
                Section::make('Akses & Keamanan')
                    ->schema([
                        Infolists\Components\TextEntry::make('phone')
                            ->label('No. HP / WhatsApp')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('email')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('email_verified_via')
                            ->label('Verifikasi email')
                            ->formatStateUsing(fn (?string $state): string => static::emailVerificationSources()[$state] ?? (string) $state)
                            ->placeholder('Belum diverifikasi'),
                        Infolists\Components\TextEntry::make('roles.name')
                            ->label('Role')
                            ->badge()
                            ->placeholder('-'),
                        Infolists\Components\IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                    ])
                    ->columns(2),
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
            'email_link' => 'Tautan verifikasi email',
            'socialite' => 'Penyedia Socialite',
            'legacy_review_required' => 'Data lama — perlu ditinjau',
        ];
    }
}
