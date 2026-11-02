<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\RolesRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\TicketsRelationManager;
use App\Models\User;
use App\Support\PhoneNumber;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('identity')
                    ->label('NIK Karyawan')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Diisi dari Talenta.'),
                Forms\Components\Select::make('business_entity_id')
                    ->label('Badan Usaha')
                    ->relationship('businessEntity', 'name')
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('unit_id')
                    ->label('Divisi')
                    ->relationship('unit', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Divisi asal. Anggota divisi menerima dan menangani laporan ke divisinya.'),
                Forms\Components\Select::make('units')
                    ->label('Divisi tambahan yang ditangani')
                    ->relationship('units', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Opsional. Divisi asal selalu ikut ditangani.'),
                Forms\Components\TextInput::make('job_title')
                    ->label('Jabatan')
                    ->maxLength(150),
                Forms\Components\TextInput::make('job_level')
                    ->label('Level')
                    ->maxLength(100),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                Forms\Components\DateTimePicker::make('email_verified_at')
                    ->helperText('Notifikasi email baru dikirim setelah waktu verifikasi dan sumber tepercaya diisi. Login tidak bergantung pada kolom ini.'),
                Forms\Components\Select::make('email_verified_via')
                    ->label('Sumber verifikasi email')
                    ->options([
                        'admin' => 'Diverifikasi administrator',
                        'email_link' => 'Tautan verifikasi email',
                        'socialite' => 'Penyedia Socialite',
                        'legacy_review_required' => 'Data lama — perlu ditinjau',
                    ])
                    ->helperText('Pilih "Diverifikasi administrator" hanya setelah kepemilikan email diperiksa.'),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create')
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->maxLength(15)
                    ->minLength(10)
                    ->dehydrateStateUsing(fn ($state) => PhoneNumber::canonical($state)),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('job_title')
                    ->label('Jabatan')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('unit.name')
                    ->label('Divisi')
                    ->sortable(),
                Tables\Columns\TextColumn::make('businessEntity.name')
                    ->label('Badan Usaha')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('email')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('roles.name')
                    ->badge(),
                Tables\Columns\TextColumn::make('units.name')
                    ->label('Divisi ditangani')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('unit_id')
                    ->label('Divisi')
                    ->relationship('unit', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('business_entity_id')
                    ->label('Badan Usaha')
                    ->relationship('businessEntity', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TrashedFilter::make(),
            ])
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
}
