<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\TalentaEmployeeSync;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        // Sinkron menyentuh semua akun dan master data, jadi khusus admin global.
        $canImport = fn (): bool => auth()->user() instanceof User && auth()->user()->hasGlobalTicketAccess();

        return [
            // Sama seperti dnd-web dan gais-web: data karyawan bersumber dari ekspor JSON Talenta.
            Actions\Action::make('importTalenta')
                ->label('Import JSON (Talenta)')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->authorize($canImport)
                ->visible($canImport)
                ->schema([
                    FileUpload::make('file')
                        ->label('Upload JSON Talenta')
                        ->helperText('Ekspor direktori karyawan Talenta. Akun dicocokkan lewat ID Karyawan lalu No. HP; nama, badan usaha, divisi asal, jabatan, dan level mengikuti Talenta.')
                        ->acceptedFileTypes([
                            'application/json',
                            'text/json',
                            'text/plain',
                        ])
                        ->maxSize(12 * 1024)
                        ->required()
                        ->storeFiles(false),
                    Toggle::make('apply')
                        ->label('Simpan perubahan')
                        ->helperText('Matikan untuk melihat preview dulu. Saat disimpan, file ini juga menggantikan direktori karyawan untuk login OTP dan auto-registrasi. Akun yang tidak ada lagi di file akan dinonaktifkan.')
                        ->default(false),
                ])
                ->modalHeading('Import User dari JSON Talenta')
                ->modalWidth('md')
                ->modalSubmitActionLabel('Proses')
                ->action(function (array $data): void {
                    $file = $data['file'] ?? null;
                    $content = $file instanceof TemporaryUploadedFile
                        ? $file->get()
                        : (is_string($file) && is_readable($file) ? file_get_contents($file) : false);
                    $employees = is_string($content) ? TalentaEmployeeSync::employeesFromJson($content) : null;

                    if ($employees === null) {
                        Notification::make()
                            ->title('File JSON Talenta tidak dapat dibaca.')
                            ->body('Struktur harus { "data": { "data": [...] } } seperti ekspor Talenta.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $apply = (bool) ($data['apply'] ?? false);
                    $report = app(TalentaEmployeeSync::class)->run($employees, $apply);

                    if ($apply) {
                        try {
                            TalentaEmployeeSync::storeEmployeeFile($content);
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('File direktori karyawan gagal diganti.')
                                ->body('Sinkron akun tetap tersimpan, tetapi login OTP masih memakai file lama. Periksa izin tulis folder storage.')
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }

                    Notification::make()
                        ->title($apply ? 'Sinkron Talenta disimpan.' : 'Preview sinkron Talenta — belum ada yang disimpan.')
                        ->body(new HtmlString(implode('<br>', array_map('e', static::reportLines($report, count($employees))))))
                        ->color($apply ? 'success' : 'info')
                        ->icon($apply ? 'heroicon-o-check-circle' : 'heroicon-o-eye')
                        ->persistent()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    /**
     * @return list<string>
     */
    protected static function reportLines(array $report, int $employeeCount): array
    {
        $lines = collect(TalentaEmployeeSync::summary($report, $employeeCount))
            ->map(fn (int $count, string $label): string => "{$label}: {$count}")
            ->values()
            ->all();

        $details = [
            'Dinonaktifkan' => $report['users_deactivated'],
            'Dilewati' => collect($report['users_skipped'])
                ->map(fn (string $reason, string $employee): string => "{$employee} — {$reason}")
                ->values()
                ->all(),
        ];

        foreach ($details as $heading => $items) {
            if ($items === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = "{$heading}:";
            foreach (array_slice($items, 0, 8) as $item) {
                $lines[] = "- {$item}";
            }
            if (count($items) > 8) {
                $lines[] = '... dan '.(count($items) - 8).' lainnya.';
            }
        }

        return $lines;
    }
}
