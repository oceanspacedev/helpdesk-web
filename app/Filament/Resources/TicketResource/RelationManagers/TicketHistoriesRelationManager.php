<?php

namespace App\Filament\Resources\TicketResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class TicketHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ticketHistories';

    protected static ?string $recordTitleAttribute = 'ticket_id';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name'),
                Tables\Columns\TextColumn::make('ticketStatus.name'),
                Tables\Columns\TextColumn::make('created_at')->sortable(),
            ])
            ->filters([
            ])
            ->headerActions([
            ])
            ->actions([
            ])
            ->bulkActions([
            ])
            ->defaultSort('created_at', 'desc');
    }
}
