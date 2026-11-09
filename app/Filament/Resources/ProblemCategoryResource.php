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
                    ->afterStateUpdated(fn (Set $set) => $set('default_responsibles', [])),
                Forms\Components\TextInput::make('name')
                    ->translateLabel()
                    ->required()
                    ->maxLength(255),
                static::defaultResponsiblesSelect(),
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
                Tables\Columns\TextColumn::make('defaultResponsibles.name')
                    ->label(__('Default PIC'))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('Semua anggota divisi')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('unit_id')
                    ->label(__('Work Unit'))
                    ->options(fn (): array => static::manageableUnitOptions())
                    ->searchable()
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
            ->with(['unit', 'defaultResponsibles'])
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
     * PIC default kategori (boleh lebih dari satu). Pilihannya mengikuti
     * divisi yang dipilih di form; pilihan di luar divisi itu ditolak saat
     * validasi karena daftar opsinya dimuat penuh (preload).
     */
    public static function defaultResponsiblesSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('default_responsibles')
            ->label(__('Default PIC'))
            ->relationship(
                name: 'defaultResponsibles',
                titleAttribute: 'name',
                modifyQueryUsing: fn (Builder $query, Get $get): Builder => ProblemCategory::constrainDefaultResponsibleCandidates(
                    $query,
                    (int) $get('unit_id'),
                ),
            )
            ->multiple()
            ->searchable()
            ->preload()
            ->placeholder('Semua anggota divisi')
            ->helperText('Bila diisi, tiket baru kategori ini langsung ditugaskan ke PIC yang tiket aktifnya paling sedikit, dan notifikasi tiket baru hanya dikirim ke para PIC. Kosongkan agar notifikasi dikirim ke semua anggota divisi.');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Problem Category');
    }
}
