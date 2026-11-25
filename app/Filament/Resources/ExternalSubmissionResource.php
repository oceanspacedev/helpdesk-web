<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExternalSubmissionResource\Pages;
use App\Models\ExternalSubmission;
use App\Services\Integrations\External\ExternalSystemClient;
use App\Services\Integrations\External\ExternalSystemException;
use App\Services\Integrations\External\ExternalTicketSnapshot;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Pengajuan Saya": pengajuan yang diteruskan ke sistem eksternal
 * (pass-through). Helpdesk tidak menyimpan isinya, jadi daftar ini hanya
 * berisi kode referensi, status terakhir, dan tombol "Lacak progres" ke
 * halaman lacak sistem tujuan. Hanya baca: tidak ada buat/ubah/hapus.
 */
class ExternalSubmissionResource extends Resource
{
    protected static ?string $model = ExternalSubmission::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'heroicon-s-paper-airplane';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Pengajuan';

    protected static ?string $pluralModelLabel = 'Pengajuan Saya';

    protected static ?string $navigationLabel = 'Pengajuan Saya';

    protected static ?string $slug = 'pengajuan-saya';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['integration', 'unit', 'user'])
            ->when($user, fn (Builder $query) => $query->visibleTo($user), fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('external_ref')
                    ->label('Kode referensi')
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->limit(60)
                    ->description(fn (ExternalSubmission $record): ?string => $record->category_name),
                Tables\Columns\TextColumn::make('unit.name')
                    ->label('Unit tujuan')
                    ->description(fn (ExternalSubmission $record): ?string => $record->integration?->name),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Pelapor')
                    ->visible(fn (): bool => (bool) auth()->user()?->hasGlobalTicketAccess())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status_label')
                    ->label('Status terakhir')
                    ->badge()
                    ->color(fn (ExternalSubmission $record): string => match ($record->status_code) {
                        'done' => 'success',
                        'in_progress' => 'info',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (ExternalSubmission $record): ?string => $record->status_checked_at
                        ? 'Dicek '.$record->status_checked_at->diffForHumans()
                        : null),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dikirim')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada pengajuan')
            ->emptyStateDescription('Pengajuan yang diteruskan ke sistem unit lain (mis. permintaan dokumen ke BUSDEV) muncul di sini beserta tautan lacaknya.')
            ->actions([
                Actions\Action::make('track')
                    ->label('Lacak progres')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ExternalSubmission $record): ?string => $record->track_url, shouldOpenInNewTab: true)
                    ->visible(fn (ExternalSubmission $record): bool => filled($record->track_url)),
                Actions\Action::make('refreshStatus')
                    ->label('Perbarui status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(fn (ExternalSubmission $record) => static::refreshStatus($record)),
            ]);
    }

    /** Ambil status terbaru dari sistem tujuan (GET {base}/tickets/{ref}). */
    public static function refreshStatus(ExternalSubmission $submission): void
    {
        $integration = $submission->integration;

        try {
            $snapshot = ExternalSystemClient::for($integration)->ticket($submission->external_ref);
        } catch (ExternalSystemException $exception) {
            Notification::make()->title('Status belum bisa diperbarui')->body($exception->getMessage())->warning()->send();

            return;
        }

        $submission->forceFill([
            'status_code' => (string) $snapshot['status']['code'],
            'status_label' => (string) $snapshot['status']['label'],
            'track_url' => ExternalTicketSnapshot::resolveTrackUrl($snapshot['track_url'] ?? null, $integration->base_url) ?? $submission->track_url,
            'status_checked_at' => now(),
        ])->save();

        Notification::make()->title('Status: '.$submission->status_label)->success()->send();
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageExternalSubmissions::route('/'),
        ];
    }
}
