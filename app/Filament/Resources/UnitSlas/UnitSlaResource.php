<?php

namespace App\Filament\Resources\UnitSlas;

use App\Filament\Resources\UnitSlas\Pages\ManageUnitSlas;
use App\Models\UnitSla;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class UnitSlaResource extends Resource
{
    protected static ?string $model = UnitSla::class;

    protected static ?string $modelLabel = 'SLA';

    protected static ?string $pluralModelLabel = 'Target SLA';

    protected static ?string $navigationLabel = 'SLA';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('unit_id')
                    ->relationship('unit', 'name')
                    ->required()
                    ->translateLabel(),
                Select::make('priority_id')
                    ->relationship('priority', 'name')
                    ->required()
                    ->unique(
                        table: 'unit_slas',
                        column: 'priority_id',
                        modifyRuleUsing: function (Unique $rule, callable $get) {
                            return $rule->where('unit_id', $get('unit_id'));
                        },
                        ignoreRecord: true
                    )
                    ->validationMessages([
                        'unique' => __('Aturan SLA untuk kombinasi Unit dan Prioritas ini sudah ada.'),
                    ])
                    ->translateLabel(),
                TextInput::make('target_hours')
                    ->numeric()
                    ->required()
                    ->suffix(__('Hours'))
                    ->translateLabel(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('unit.name')
                    ->translateLabel()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('priority.name')
                    ->translateLabel()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('target_hours')
                    ->suffix(' Jam')
                    ->translateLabel()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['unit', 'priority']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUnitSlas::route('/'),
        ];
    }
}
