<?php

namespace App\Filament\Resources\UnitResource\RelationManagers;

use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can('viewAny', User::class)
            && ($user->hasGlobalTicketAccess()
                || $user->isAssignedToUnit((int) $ownerRecord->getKey()));
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('roles.name')
                    ->badge(),
            ])
            ->filters([
            ])
            ->headerActions([
                Actions\AttachAction::make(),
            ])
            ->actions([
                Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Actions\DetachBulkAction::make(),
            ]);
    }
}
