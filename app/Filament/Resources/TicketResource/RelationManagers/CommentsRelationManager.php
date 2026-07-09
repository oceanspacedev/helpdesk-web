<?php

namespace App\Filament\Resources\TicketResource\RelationManagers;

use App\Filament\Resources\TicketResource;
use App\Models\User;
use Filament\Forms;
use Filament\Actions;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Storage;
use Livewire\Component as Livewire;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $recordTitleAttribute = 'comment';

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make()->schema([
                    Forms\Components\RichEditor::make('comment')
                        ->required(),
                    Forms\Components\FileUpload::make('attachments')
                        ->disk('public')
                        ->directory('comment-attachments/' . date('m-y'))
                            ->maxSize(20480)
                        ->enableDownload(),
                ])
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    Split::make([
                        TextColumn::make('user.name')
                            ->translateLabel()
                            ->weight('bold')
                            ->grow(false),
                        TextColumn::make('created_at')
                            ->translateLabel()
                            ->dateTime()
                            ->color('secondary'),
                    ]),
                    TextColumn::make('comment')
                        ->wrap()
                        ->html(),
                ]),
            ])
            ->filters([])
            ->headerActions([
                Actions\CreateAction::make()->mutateFormDataUsing(function (array $data): array {
                    $data['user_id'] = auth()->id();

                    return $data;
                })
                    ->label('Tambah Komentar')
                    ->after(function (Livewire $livewire) {
                        $ticket = $livewire->ownerRecord;

                        if (auth()->user()->hasAnyRole(['Super Admin', 'Admin Unit', 'Staf Unit'])) {
                            $receiver = $ticket->owner;
                        } else {
                            $receiver = User::whereHas(
                                'roles',
                                function ($q) {
                                    $q->where('name', 'Super Admin')
                                        ->orWhere('name', 'Admin Unit')
                                        ->orWhere('name', 'Staf Unit');
                                },
                            )->get();
                        }

                        Notification::make()
                            ->title('Terdapat komentar baru pada tiket Anda')
                            ->actions([
                                NotificationAction::make('Lihat')
                                    ->url(TicketResource::getUrl('view', ['record' => $ticket->id])),
                            ])
                            ->sendToDatabase($receiver);
                    }),
            ])
            ->actions([
                Actions\Action::make('attachment')->action(function ($record) {
                    return Storage::download($record->attachments);
                })->hidden(fn ($record) => $record->attachments == ''),
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }
}
