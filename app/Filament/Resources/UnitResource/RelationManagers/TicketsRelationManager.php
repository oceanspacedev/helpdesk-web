<?php

namespace App\Filament\Resources\UnitResource\RelationManagers;

use App\Models\Ticket;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', Ticket::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $user = auth()->user();

                return $user
                    ? $query->visibleTo($user)
                    : $query->whereRaw('1 = 0');
            })
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->translateLabel()
                    ->searchable(),
                Tables\Columns\TextColumn::make('owner.name')
                    ->translateLabel()
                    ->searchable(),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->translateLabel()
                    ->searchable(),
                Tables\Columns\TextColumn::make('problemCategory.name')
                    ->label(__('Problem Category'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('ticketStatus.name')
                    ->label(__('Ticket Status'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->translateLabel()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([])
            ->headerActions([])
            ->actions([
                Actions\ViewAction::make()
                    ->url(fn (Ticket $record): string => route('filament.admin.resources.tickets.view', $record)),
            ])
            ->bulkActions([]);
    }
}
