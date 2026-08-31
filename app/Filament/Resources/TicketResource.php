<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TicketResource\Pages;
use App\Filament\Resources\TicketResource\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\TicketResource\RelationManagers\TicketHistoriesRelationManager;
use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make()->schema([
                    Forms\Components\Select::make('unit_id')
                        ->label('Unit / Divisi Tujuan')
                        ->options(Unit::all()
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->helperText('Unit ini akan menerima dan memproses tiket. Salah kirim: Super Admin mengubah di sini, staf unit memakai Pindah unit.')
                        ->disabled(fn (string $operation): bool => $operation === 'edit'
                            && ! auth()->user()?->hasGlobalTicketAccess())
                        ->afterStateUpdated(function (?int $state, Get $get, Set $set): void {
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
                        ->live(),

                    Forms\Components\Select::make('problem_category_id')
                        ->label(__('Problem Category'))
                        ->options(function (Get $get): array {
                            $unit = Unit::find($get('unit_id'));
                            if ($unit) {
                                return $unit->problemCategories->pluck('name', 'id')->all();
                            }

                            return ProblemCategory::all()->pluck('name', 'id')->all();
                        })
                        ->searchable()
                        ->required()
                        ->helperText('Staf dapat mengubah kategori, termasuk dari Perlu diklasifikasi.'),

                    Forms\Components\TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpan([
                            'sm' => 2,
                        ]),

                    Forms\Components\RichEditor::make('description')
                        ->fileAttachmentsDisk('public')
                        ->fileAttachmentsDirectory('ticket-attachments/'.date('m-y'))
                        ->fileAttachmentsVisibility('public')
                        ->label(__('Description'))
                        ->required()
                        ->maxLength(65535)
                        ->columnSpan([
                            'sm' => 2,
                        ]),

                    Forms\Components\FileUpload::make('supporting_attachments')
                        ->label(__('Supporting Attachments'))
                        ->multiple()
                        ->disk('public')
                        ->directory('ticket-supporting/'.date('m-y'))
                        ->visibility('public')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-powerpoint',
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'text/plain',
                            'text/csv',
                            'application/zip',
                            'application/x-zip-compressed',
                            'application/vnd.rar',
                            'application/x-rar-compressed',
                            'image/jpeg',
                            'image/png',
                        ])
                        ->maxSize(10240)
                        ->maxFiles(5)
                        ->enableDownload()
                        ->rules([
                            fn () => function ($attribute, $value, $fail): void {
                                if (! is_array($value)) {
                                    return;
                                }

                                $totalBytes = 0;

                                foreach ($value as $file) {
                                    if (is_object($file) && method_exists($file, 'getSize')) {
                                        $totalBytes += $file->getSize();
                                    }
                                }

                                if ($totalBytes > (10 * 1024 * 1024)) {
                                    $fail('Total ukuran file maksimal 10MB.');
                                }
                            },
                        ])
                        ->helperText(__('Diizinkan: PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT, CSV, ZIP, RAR, JPG, PNG. Total maksimal 10MB (maks 5 file).'))
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

                Section::make()->schema([
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
                        ->required()
                        ->helperText('Termasuk dari Belum disebutkan bila pelapor tidak menyebut cabang.'),

                    Forms\Components\Select::make('ticket_statuses_id')
                        ->label(__('Status'))
                        ->options(TicketStatus::all()
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->hiddenOn('create')
                        ->disabled(),

                    Forms\Components\Select::make('responsible_id')
                        ->label(__('Responsible'))
                        ->options(function (Get $get): array {
                            $unitId = (int) $get('unit_id');

                            if (! $unitId) {
                                return [];
                            }

                            return User::query()
                                ->ticketProcessorsForUnit($unitId, includeGlobal: true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->hiddenOn('create')
                        ->hidden(
                            fn () => ! auth()
                                ->user()
                                ->hasAnyRole(['Super Admin', 'Master Admin']),
                        ),

                    Forms\Components\Placeholder::make('owner')
                        ->translateLabel()
                        ->content(fn (
                            ?Ticket $record,
                        ): string => $record ? $record->owner->name : '-')
                        ->hidden(
                            fn () => ! auth()
                                ->user()
                                ->hasAnyRole(['Super Admin', 'Master Admin', 'Admin Unit', 'Staff Unit']),
                        ),

                    Forms\Components\Placeholder::make('owner_phone')
                        ->label('Nomor WhatsApp Pelapor')
                        ->content(fn (?Ticket $record): string => $record?->owner?->phone ?: '-')
                        ->hidden(
                            fn () => ! auth()
                                ->user()
                                ->hasAnyRole(['Super Admin', 'Master Admin', 'Admin Unit', 'Staff Unit']),
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

                    Forms\Components\Placeholder::make('sla_due_at')
                        ->translateLabel()
                        ->content(fn (
                            ?Ticket $record,
                        ): string => $record && $record->sla_due_at ? $record->sla_due_at->format('d M Y H:i') : '-')
                        ->hiddenOn('create'),

                    Forms\Components\Placeholder::make('is_sla_met')
                        ->translateLabel()
                        ->content(fn (
                            ?Ticket $record,
                        ): string => $record && $record->is_sla_met !== null ? ($record->is_sla_met ? __('Yes') : __('No')) : '-')
                        ->hiddenOn('create'),
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
                        fn (Ticket $record): string => ($record->businessEntity?->name ?: 'N/A'),
                        position: 'below'
                    ),
                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Pengirim')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('owner.phone')
                    ->label('WhatsApp Pelapor')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('unit.name')
                    ->label('Unit Tujuan')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
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
                        'heroicon-o-x-mark' => static fn ($state): bool => $state === 'Cancel',
                        'heroicon-o-check' => static fn ($state): bool => $state === 'Closed',
                    ]),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('F j, Y')
                    ->translateLabel()
                    ->sortable()
                    ->toggleable(true),
                Tables\Columns\TextColumn::make('sla_due_at')
                    ->translateLabel()
                    ->dateTime('F j, Y H:i')
                    ->sortable()
                    ->toggleable(true)
                    ->color(fn (Ticket $record): string => $record->sla_due_at && Carbon::now()->gt($record->sla_due_at) && $record->ticket_statuses_id !== 4
                            ? 'danger'
                            : 'success'
                    ),
                Tables\Columns\TextColumn::make('is_sla_met')
                    ->label('SLA Status')
                    ->translateLabel()
                    ->badge()
                    ->getStateUsing(function ($record) {
                        if ($record->is_sla_met === null) {
                            if ($record->sla_due_at && Carbon::now()->gt($record->sla_due_at)) {
                                return 'Missed';
                            }

                            return $record->sla_due_at ? 'Pending' : 'N/A';
                        }

                        return $record->is_sla_met ? 'Achieved' : 'Missed';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Achieved' => 'success',
                        'Missed' => 'danger',
                        'Pending' => 'warning',
                        'N/A' => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'Achieved' => 'heroicon-o-check-circle',
                        'Missed' => 'heroicon-o-x-circle',
                        'Pending' => 'heroicon-o-clock',
                        'N/A' => 'heroicon-o-minus-circle',
                    })
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
                        $start = ! empty($data['start']) ? Carbon::parse($data['start'])->startOfDay() : null;

                        // Ambil akhir hari dari tanggal akhir
                        $end = ! empty($data['end']) ? Carbon::parse($data['end'])->endOfDay() : null;

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
                    ->label('Unit Tujuan')
                    ->options(Unit::all()->pluck('name', 'id'))
                    ->hidden(
                        fn () => ! auth()
                            ->user()
                            ->hasAnyRole(['Super Admin', 'Master Admin']),
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
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\ForceDeleteAction::make(),
            ])
            ->bulkActions([
                Actions\DeleteBulkAction::make(),
                Actions\ForceDeleteBulkAction::make(),
                Actions\RestoreBulkAction::make(),
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['owner', 'unit', 'businessEntity', 'responsible', 'problemCategory', 'ticketStatus', 'priority'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);

        $user = auth()->user();

        return $user
            ? $query->visibleTo($user)
            : $query->whereRaw('1 = 0');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user || ! $user->canProcessTickets()) {
            return null;
        }

        $unitKey = sha1(implode(',', $user->assignedUnitIds()));
        $count = cache()->remember("nav_badge_tickets_{$user->id}_{$unitKey}", 30, function () use ($user) {
            return Ticket::query()
                ->incomingFor($user)
                ->where('ticket_statuses_id', TicketStatus::OPEN)
                ->count();
        });

        return (string) $count;
    }

    public static function getPluralModelLabel(): string
    {
        return __('Tickets');
    }
}
