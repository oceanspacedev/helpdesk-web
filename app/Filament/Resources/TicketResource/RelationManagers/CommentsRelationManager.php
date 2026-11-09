<?php

namespace App\Filament\Resources\TicketResource\RelationManagers;

use App\Filament\Resources\TicketResource;
use App\Models\Comment;
use App\Models\User;
use App\Support\SafeUploadedFile;
use Filament\Actions;
use Filament\Actions\Action as NotificationAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Component as Livewire;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $recordTitleAttribute = 'comment';

    protected static ?string $title = 'Komentar';

    protected static ?string $modelLabel = 'komentar';

    protected static ?string $pluralModelLabel = 'komentar';

    public function isReadOnly(): bool
    {
        return isset($this->ownerRecord) && $this->ownerRecord->trashed();
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\RichEditor::make('comment')
                    ->label('Komentar')
                    ->placeholder('Tulis tanggapan, update progres, atau pertanyaan untuk pelapor...')
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'link'],
                        ['bulletList', 'orderedList', 'attachFiles'],
                        ['undo', 'redo'],
                    ])
                    ->extraInputAttributes(['style' => 'min-height: 12rem;'])
                    ->required(),
                Forms\Components\FileUpload::make('attachments')
                    ->label('Lampiran')
                    ->helperText('Opsional. Satu file, maksimal 20 MB.')
                    ->disk('public')
                    ->directory('comment-attachments/'.date('m-y'))
                    ->downloadable()
                    ->rules([
                        SafeUploadedFile::rule(20 * 1024 * 1024, 20 * 1024 * 1024),
                    ]),
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
                Actions\CreateAction::make()
                    ->label('Tambah Komentar')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->slideOver()
                    ->modalWidth('md')
                    ->modalHeading('Tambah Komentar')
                    ->modalDescription('Komentar terlihat oleh pelapor dan petugas tiket ini.')
                    ->modalSubmitActionLabel('Kirim')
                    ->createAnother(false)
                    ->authorize(fn (): bool => ! $this->isReadOnly()
                        && (bool) auth()->user()?->can('create', Comment::class)
                        && (bool) auth()->user()?->can('view', $this->getOwnerRecord()))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = auth()->id();

                        return $data;
                    })
                    ->after(function (Livewire $livewire) {
                        $ticket = $livewire->ownerRecord;

                        $authId = (int) auth()->id();
                        $isOwner = $authId === (int) $ticket->owner_id;

                        if ($isOwner) {
                            $responsible = $ticket->eligibleResponsible();

                            if ($responsible) {
                                $receiver = $responsible;
                            } else {
                                $receiver = User::query()
                                    ->ticketProcessorsForUnit((int) $ticket->unit_id, includeGlobal: true)
                                    ->where('id', '!=', $authId)
                                    ->get();
                            }
                        } else {
                            $receiver = $ticket->owner;
                        }

                        if ($receiver) {
                            Notification::make()
                                ->title('Terdapat komentar baru pada tiket Anda')
                                ->actions([
                                    NotificationAction::make('Lihat')
                                        ->url(TicketResource::getUrl('view', ['record' => $ticket->id])),
                                ])
                                ->sendToDatabase($receiver);
                        }
                    }),
            ])
            ->actions([
                NotificationAction::make('attachment')
                    ->label('Lampiran')
                    ->icon('heroicon-o-paper-clip')
                    ->action(function (Comment $record) {
                        $download = $record->downloadStoredAttachment();
                        if ($download === null) {
                            Notification::make()
                                ->title('Lampiran tidak ditemukan.')
                                ->danger()
                                ->send();

                            return;
                        }

                        return $download;
                    })
                    ->hidden(fn (?Comment $record): bool => $record === null || blank($record->attachments))
                    ->authorize(fn (Comment $record): bool => (bool) auth()->user()?->can('view', $record)),
                Actions\EditAction::make()
                    ->slideOver()
                    ->modalWidth('md')
                    ->modalHeading('Ubah Komentar')
                    ->modalSubmitActionLabel('Simpan')
                    ->authorize(fn (Comment $record): bool => ! $this->isReadOnly()
                        && (bool) auth()->user()?->can('update', $record)),
                Actions\DeleteAction::make()
                    ->authorize(fn (Comment $record): bool => ! $this->isReadOnly()
                        && (bool) auth()->user()?->can('delete', $record)),
            ])
            ->bulkActions([]);
    }
}
