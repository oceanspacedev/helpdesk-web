<?php

namespace App\Filament\Resources\UnitSlas;

use App\Filament\Resources\UnitSlas\Pages\ManageUnitSlas;
use App\Models\UnitSla;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UnitSlaResource extends Resource
{
    protected static ?string $model = UnitSla::class;

    protected static ?string $modelLabel = 'SLA';
    protected static ?string $pluralModelLabel = 'Target SLA';
    protected static ?string $navigationLabel = 'SLA';
    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Select::make('unit_id')
                    ->relationship('unit', 'name')
                    ->required()
                    ->translateLabel(),
                \Filament\Forms\Components\Select::make('priority_id')
                    ->relationship('priority', 'name')
                    ->required()
                    ->unique(
                        table: 'unit_slas',
                        column: 'priority_id',
                        modifyRuleUsing: function (\Illuminate\Validation\Rules\Unique $rule, callable $get) {
                            return $rule->where('unit_id', $get('unit_id'));
                        },
                        ignoreRecord: true
                    )
                    ->validationMessages([
                        'unique' => __('Aturan SLA untuk kombinasi Unit dan Prioritas ini sudah ada.'),
                    ])
                    ->translateLabel(),
                \Filament\Forms\Components\TextInput::make('target_hours')
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
                \Filament\Tables\Columns\TextColumn::make('unit.name')
                    ->translateLabel()
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('priority.name')
                    ->translateLabel()
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('target_hours')
                    ->suffix(' Jam')
                    ->translateLabel()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUnitSlas::route('/'),
        ];
    }
}
