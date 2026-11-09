<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnitResource\Pages;
use App\Models\ProblemCategory;
use App\Models\Unit;
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
 * Divisi dikelola dari satu halaman: tambah, lihat, dan ubah dibuka lewat
 * panel geser (slide-over) di atas tabel, tanpa halaman detail terpisah.
 */
class UnitResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static string|\UnitEnum|null $navigationGroup = 'Organisasi';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->translateLabel()
                    ->required()
                    ->maxLength(255)
                    ->columnSpan('full'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Infolists\Components\TextEntry::make('name')
                    ->translateLabel(),
                Infolists\Components\TextEntry::make('problem_categories')
                    ->label('Kategori tiket')
                    ->state(fn (Unit $record): array => $record->problemCategories()
                        ->with('defaultResponsible')
                        ->orderBy('name')
                        ->get()
                        ->map(fn (ProblemCategory $category): string => $category->defaultResponsible
                            ? "{$category->name} · PIC: {$category->defaultResponsible->name}"
                            : $category->name)
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('Belum ada kategori'),
                Infolists\Components\TextEntry::make('members')
                    ->label('Anggota')
                    ->state(fn (Unit $record): array => $record->users()
                        ->orderBy('name')
                        ->pluck('name')
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('Belum ada anggota'),
                Infolists\Components\TextEntry::make('tickets_total')
                    ->label('Jumlah tiket')
                    ->state(fn (Unit $record): int => $record->tickets()->count()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->translateLabel()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('problem_categories_count')
                    ->label('Kategori')
                    ->counts('problemCategories')
                    ->sortable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->label('Anggota')
                    ->counts('users')
                    ->sortable(),
                Tables\Columns\TextColumn::make('tickets_count')
                    ->label('Tiket')
                    ->counts('tickets')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Actions\ViewAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
                Actions\DeleteAction::make(),
                Actions\RestoreAction::make(),
                Actions\ForceDeleteAction::make(),
            ])
            ->bulkActions([
                Actions\DeleteBulkAction::make(),
                Actions\ForceDeleteBulkAction::make(),
                Actions\RestoreBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUnits::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
