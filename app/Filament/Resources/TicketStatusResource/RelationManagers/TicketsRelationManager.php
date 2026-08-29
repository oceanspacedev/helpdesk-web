<?php

namespace App\Filament\Resources\TicketStatusResource\RelationManagers;

use App\Models\Ticket;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $recordTitleAttribute = 'title';

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
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->translateLabel()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('problemCategory.name')
                    ->searchable()
                    ->label(__('Problem Category'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ticketStatus.name')
                    ->label('Status')
                    ->sortable(),
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
