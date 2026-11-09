<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TicketStatusResource\Pages;
use App\Models\TicketStatus;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Status tiket dikelola dari satu halaman: tambah, lihat, dan ubah dibuka
 * lewat panel geser (slide-over) di atas tabel, tanpa halaman detail terpisah.
 */
class TicketStatusResource extends Resource
{
    protected static ?string $model = TicketStatus::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->translateLabel()
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Infolists\Components\TextEntry::make('id')
                    ->label('#'),
                Infolists\Components\TextEntry::make('name')
                    ->translateLabel(),
                Infolists\Components\TextEntry::make('tickets_count')
                    ->label(__('Tickets Count'))
                    ->state(fn (TicketStatus $record): int => static::visibleTicketsCount($record)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#'),

                Tables\Columns\TextColumn::make('name')
                    ->translateLabel()
                    ->searchable(),

                Tables\Columns\TextColumn::make('tickets_count')
                    ->label(__('Tickets Count'))
                    ->sortable(),
            ])
            ->filters([])
            ->actions([
                Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTicketStatuses::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->withCount([
                'tickets' => fn (Builder $query): Builder => $user
                    ? $query->visibleTo($user)
                    : $query->whereRaw('1 = 0'),
            ])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * Jumlah tiket berstatus ini yang boleh dilihat pengguna aktif, mengikuti
     * aturan kotak masuk yang sama dengan kolom tabel.
     */
    public static function visibleTicketsCount(TicketStatus $record): int
    {
        $user = auth()->user();

        if (! $user) {
            return 0;
        }

        return $record->tickets()->visibleTo($user)->count();
    }

    public static function getPluralModelLabel(): string
    {
        return __('Ticket Statuses ');
    }
}
