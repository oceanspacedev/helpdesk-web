<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TicketResource\Pages;
use App\Filament\Resources\TicketResource\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\TicketResource\RelationManagers\TicketHistoriesRelationManager;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\BusinessEntity;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Card;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Filters\FilterGroup;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Card::make()->schema([
                    Forms\Components\Select::make('unit_id')
                        ->label(__('Work Unit'))
                        ->options(Unit::all()
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->afterStateUpdated(function ($state, callable $get, callable $set) {
                            $unit = Unit::find($state);
                            if ($unit) {
                                $problemCategoryId = (int) $get('problem_category_id');
                                if ($problemCategoryId && $problemCategory = ProblemCategory::find($problemCategoryId)) {
                                    if ($problemCategory->unit_id !== $unit->id) {
                                        $set('problem_category_id', null);
                                    }
                                }
                            }
                        })
                        ->reactive(),

                    Forms\Components\Select::make('problem_category_id')
                        ->label(__('Problem Category'))
                        ->options(function (callable $get, callable $set) {
                            $unit = Unit::find($get('unit_id'));
                            if ($unit) {
                                return $unit->problemCategories->pluck('name', 'id');
                            }

                            return ProblemCategory::all()->pluck('name', 'id');
                        })
                        ->searchable()
                        ->required(),

                    Forms\Components\TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpan([
                            'sm' => 2,
                        ]),

                    Forms\Components\RichEditor::make('description')
                        ->fileAttachmentsDisk('public')
                        ->fileAttachmentsDirectory('ticket-attachments/' . date('m-y'))
                        ->label(__('Description'))
                        ->required()
                        ->maxLength(65535)
                        ->columnSpan([
                            'sm' => 2,
                        ]),

                    Forms\Components\Placeholder::make('approved_at')
                        ->translateLabel()
                        ->hiddenOn('create')
                        ->content(fn (
                            ?Ticket $record,
                        ): string => $record->approved_at ? $record->approved_at->diffForHumans() : '-'),

                    Forms\Components\Placeholder::make('solved_at')
                        ->translateLabel()
                        ->hiddenOn('create')
                        ->content(fn (
                            ?Ticket $record,
                        ): string => $record->solved_at ? $record->solved_at->diffForHumans() : '-'),
                ])->columns([
                    'sm' => 2,
                ])->columnSpan(2),

                Card::make()->schema([
                    Forms\Components\Select::make('priority_id')
                        ->label(__('Priority'))
                        ->options(Priority::all()
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('business_entities_id')
                        ->label(__('Business Entity'))
                        ->options(BusinessEntity::all()
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('ticket_statuses_id')
                        ->label(__('Status'))
                        ->options(TicketStatus::all()
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->hiddenOn('create')
                        ->hidden(
                            fn () => !auth()
                                ->user()
                                ->hasAnyRole(['Super Admin', 'Admin Unit', 'Staff Unit']),
                        ),

                    Forms\Components\Select::make('responsible_id')
                        ->label(__('Responsible'))
                        ->options(
                            User::pluck('name', 'id')->toArray() // Mengambil nama pengguna dan id
                        )
                        ->searchable()
                        ->required()
                        ->hiddenOn('create')
                        ->hidden(
                            fn () => !auth()
                                ->user()
                                ->hasAnyRole(['Super Admin', 'Admin Unit']),
                        ),

                    Forms\Components\Placeholder::make('owner')
                        ->translateLabel()
                        ->content(fn (
                            ?Ticket $record,
                        ): string => $record ? $record->owner->name : '-')
                        ->hidden(
                            fn () => !auth()
                                ->user()
                                ->hasAnyRole(['Super Admin', 'Admin Unit']),
                        ),

                    Forms\Components\Placeholder::make('created_at')
                        ->translateLabel()
                        ->content(fn (
                            ?Ticket $record,
                        ): string => $record ? $record->created_at->diffForHumans() : '-'),

                    Forms\Components\Placeholder::make('updated_at')
                        ->translateLabel()
                        ->content(fn (
                            ?Ticket $record,
                        ): string => $record ? $record->updated_at->diffForHumans() : '-'),
                ])->columnSpan(1),
            ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('title')
                    ->limit(25)
                    ->translateLabel()
                    ->searchable()
                    ->toggleable()
                    ->description(
                        fn (Ticket $record): string =>
                        ($record->owner?->name ?: 'N/A') . ' - ' . ($record->businessEntity?->name ?: 'N/A'),
                        position: 'below'
                    ),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->translateLabel()
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('problemCategory.name')
                    ->searchable()
                    ->label(__('Problem Category'))
                    ->limit(20)
                    ->toggleable(),
                Tables\Columns\BadgeColumn::make('ticketStatus.name')
                    ->label(__('Status'))
                    ->sortable()
                    ->colors([
                        'secondary' => static fn ($state): bool => $state === 'Open',
                        'warning' => static fn ($state): bool => $state === 'In Progress',
                        'danger' => static fn ($state): bool => $state === 'Cancel',
                        'success' => static fn ($state): bool => $state === 'Closed',
                    ])
                    ->icons([
                        'heroicon-o-sparkles' => static fn ($state): bool => $state === 'Open',
                        'heroicon-o-paper-airplane' => static fn ($state): bool => $state === 'In Progress',
                        'heroicon-o-x' => static fn ($state): bool => $state === 'Cancel',
                        'heroicon-o-check' => static fn ($state): bool => $state === 'Closed',
                    ]),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('F j, Y')
                    ->translateLabel()
                    ->sortable()
                    ->toggleable(true),
            ])
            ->filters([
                Filter::make('created_at_range')
                    ->form([
                        Forms\Components\DatePicker::make('start')
                            ->label(__('Start Date'))
                            ->closeOnDateSelection(),
                        Forms\Components\DatePicker::make('end')
                            ->label(__('End Date'))
                            ->closeOnDateSelection(),
                    ])
                    ->query(function (Builder $query, array $data) {
                        // Jika tidak ada tanggal yang dipilih, jangan tambahkan kondisi kueri
                        if (empty($data['start']) && empty($data['end'])) {
                            return;
                        }

                        // Ambil awal hari dari tanggal awal
                        $start = !empty($data['start']) ? Carbon::parse($data['start'])->startOfDay() : null;

                        // Ambil akhir hari dari tanggal akhir
                        $end = !empty($data['end']) ? Carbon::parse($data['end'])->endOfDay() : null;

                        // Tentukan logika filter berdasarkan apakah tanggal awal dan/atau akhir diisi
                        if ($start && $end) {
                            // Jika kedua tanggal diisi, filter antara dua tanggal tersebut
                            $query->whereBetween('created_at', [$start, $end]);
                        } elseif ($start) {
                            // Jika hanya tanggal awal diisi, filter dari tanggal awal ke masa kini
                            $query->where('created_at', '>=', $start);
                        } elseif ($end) {
                            // Jika hanya tanggal akhir diisi, filter dari awal waktu hingga tanggal akhir
                            $query->where('created_at', '<=', $end);
                        }
                    }),
                Tables\Filters\SelectFilter::make('unit_id')
                    ->label(__('Work Unit'))
                    ->options(Unit::all()->pluck('name', 'id'))
                    ->hidden(
                        fn () => !auth()
                            ->user()
                            ->hasAnyRole(['Super Admin']),
                    ),
                Tables\Filters\SelectFilter::make('ticket_statuses_id')
                    ->label(__('Status'))
                    ->options(TicketStatus::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('business_entities_id')
                    ->label(__('Business Entity'))
                    ->options(BusinessEntity::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('priority_id')
                    ->label(__('Priority'))
                    ->options(Priority::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('priority_id')
                    ->label(__('Priority'))
                    ->options(Priority::pluck('name', 'id')),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
                Tables\Actions\ForceDeleteBulkAction::make(),
                Tables\Actions\RestoreBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            CommentsRelationManager::class,
            TicketHistoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'create' => Pages\CreateTicket::route('/create'),
            'view' => Pages\ViewTicket::route('/{record}'),
            'edit' => Pages\EditTicket::route('/{record}/edit'),
        ];
    }

    /**
     * Display tickets based on each role.
     *
     * If it is a Super Admin, then display all tickets.
     * If it is a Admin Unit, then display tickets based on the tickets they have created and their unit id.
     * If it is a Staff Unit, then display tickets based on the tickets they have created and the tickets assigned to them.
     * If it is a Regular User, then display tickets based on the tickets they have created.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function ($query) {
                $user = auth()->user();

                // Display all tickets to Super Admin
                if ($user->hasRole('Super Admin')) {
                    return;
                }

                if ($user->hasRole('Admin Unit')) {
                    // Admin Unit: view tickets they own or within their units
                    $query->where('tickets.owner_id', $user->id)
                        ->orWhereIn('tickets.unit_id', $user->units->pluck('id'));
                } elseif ($user->hasRole('Staff Unit')) {
                    // Staff Unit: view tickets assigned to them or that they own
                    $query->whereIn('tickets.owner_id', [$user->id, 'tickets.responsible_id']);
                } else {
                    // Default: only view tickets owned by the user
                    $query->where('tickets.owner_id', $user->id);
                }
            })
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }


    protected static function getNavigationBadge(): ?string
    {
        if (auth()->user()->hasRole(['Super Admin', 'Admin Unit'])) {
            return Ticket::where('ticket_statuses_id', 1)
            ->where('unit_id', auth()->user()->unit_id)
            ->count();;
        }

        return false;
    }

    public static function getPluralModelLabel(): string
    {
        return __('Tickets');
    }
}
