<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProblemCategoryResource\Pages;
use App\Filament\Resources\ProblemCategoryResource\RelationManagers\TicketsRelationManager;
use App\Models\ProblemCategory;
use App\Models\Unit;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProblemCategoryResource extends Resource
{
    protected static ?string $model = ProblemCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\Select::make('unit_id')
                    ->label(__('Work Unit'))
                    ->options(fn (): array => static::manageableUnitOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('default_responsible_id', null)),
                Forms\Components\TextInput::make('name')
                    ->translateLabel()
                    ->required()
                    ->maxLength(255),
                static::defaultResponsibleSelect(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->translateLabel()
                    ->searchable(),
                Tables\Columns\TextColumn::make('unit.name')
                    ->searchable()
                    ->label(__('Work Unit')),
                Tables\Columns\TextColumn::make('defaultResponsible.name')
                    ->label(__('Default PIC'))
                    ->placeholder('Semua anggota divisi')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('unit_id')
                    ->label(__('Work Unit'))
                    ->options(fn (): array => static::manageableUnitOptions())
                    ->hidden(fn (): bool => ! auth()->user()?->hasGlobalTicketAccess()),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
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
            TicketsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProblemCategories::route('/'),
            'create' => Pages\CreateProblemCategory::route('/create'),
            'view' => Pages\ViewProblemCategory::route('/{record}'),
            'edit' => Pages\EditProblemCategory::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['unit', 'defaultResponsible'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);

        $user = auth()->user();

        if ($user && ! $user->hasGlobalTicketAccess()) {
            $query->whereIn('problem_categories.unit_id', $user->assignedUnitIds());
        }

        return $query;
    }

    /** @return array<int, string> */
    public static function manageableUnitOptions(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        return Unit::query()
            ->when(
                ! $user->hasGlobalTicketAccess(),
                fn (Builder $query): Builder => $query->whereIn('units.id', $user->assignedUnitIds()),
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * PIC default kategori. Pilihannya mengikuti divisi yang dipilih di form
     * (atau divisi pemilik relasi saat dibuka dari halaman Unit).
     */
    public static function defaultResponsibleSelect(?int $fixedUnitId = null): Forms\Components\Select
    {
        return Forms\Components\Select::make('default_responsible_id')
            ->label(__('Default PIC'))
            ->options(fn (Get $get): array => ProblemCategory::defaultResponsibleOptions(
                $fixedUnitId ?? (int) $get('unit_id'),
            ))
            ->searchable()
            ->preload()
            ->nullable()
            ->placeholder('Semua anggota divisi')
            ->helperText('Bila diisi, tiket baru kategori ini langsung ditugaskan ke orang ini dan notifikasi tiket baru hanya dikirim kepadanya. Kosongkan agar notifikasi dikirim ke semua anggota divisi.');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Problem Category');
    }
}
