<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\ExternalTicketForm;
use App\Models\User;
use App\Services\Integrations\External\ExternalFormSchema;
use App\Services\Integrations\External\ExternalNotFoundException;
use App\Services\Integrations\External\ExternalTicketService;
use App\Services\Integrations\External\ExternalUnavailableException;
use App\Services\Integrations\External\ExternalValidationException;
use App\Services\Integrations\PublicTicketSubmissionService;
use App\Support\SafeUploadedFile;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    /**
     * Kunci idempotensi per pengisian form sistem eksternal; dipakai ulang
     * saat mencoba kirim lagi, diganti saat kategori berganti.
     */
    #[Locked]
    public string $externalIdempotencyKey = '';

    public function mount(): void
    {
        parent::mount();

        $this->resetExternalIdempotencyKey();
    }

    public function create(bool $another = false): void
    {
        SafeUploadedFile::wrapValidation(fn () => parent::create($another));
    }

    public function resetExternalIdempotencyKey(): void
    {
        $this->externalIdempotencyKey = (string) Str::uuid();
    }

    /**
     * Lengkapi data sebelum disimpan ke database.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['owner_id'] = auth()->id();
        $data['ticket_statuses_id'] = 1;

        return $data;
    }

    /**
     * Kategori unit berintegrasi: isian diteruskan ke sistem eksternal dan
     * hanya tiket tipis yang dibuat (ExternalTicketService::submit).
     */
    protected function handleRecordCreation(array $data): Model
    {
        $category = ExternalTicketForm::category($data['problem_category_id'] ?? null);
        if (! $category) {
            return parent::handleRecordCreation($data);
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            throw new Halt;
        }

        $schemas = app(ExternalFormSchema::class);

        try {
            $form = $schemas->form($category);
            [$answers, $files] = ExternalTicketForm::splitState((array) ($data['answers'] ?? []));
            [$answers, $files] = $schemas->prepareAnswers($form, $answers, $files, $user, autoPrefill: false);
            $this->guardUploadLimits($files);

            return app(ExternalTicketService::class)->submit(
                $category,
                $user,
                (string) ($data['title'] ?? ''),
                $answers,
                $files,
                PublicTicketSubmissionService::externalIdempotencyKey('panel', $user, $this->externalIdempotencyKey),
                ['business_entities_id' => $data['business_entities_id'] ?? null],
            );
        } catch (ExternalValidationException $exception) {
            throw $this->panelValidationException($exception, $form ?? []);
        } catch (ExternalNotFoundException) {
            $this->failExternal('Kategori ini sudah tidak tersedia di sistem tujuan. Pilih kategori lain.');
        } catch (ExternalUnavailableException $exception) {
            report($exception);
            $this->failExternal($exception->userMessage());
        } catch (InvalidArgumentException $exception) {
            // Mis. kategori lama di unit yang baru diintegrasikan belum punya
            // external_key: tampilkan pesan, bukan halaman error 500.
            report($exception);
            $this->failExternal('Kategori ini belum dipetakan ke sistem tujuan. Pilih kategori lain atau hubungi administrator.');
        }
    }

    /**
     * Batas lampiran sama dengan form publik: jumlah file, ukuran per file,
     * dan total ukuran seluruh lampiran sebelum diteruskan ke sistem tujuan.
     *
     * @param  array<string, mixed>  $files
     */
    private function guardUploadLimits(array $files): void
    {
        $uploads = [];
        array_walk_recursive($files, function (mixed $file) use (&$uploads): void {
            if ($file instanceof UploadedFile) {
                $uploads[] = $file;
            }
        });

        $sizes = array_map(fn (UploadedFile $file): int => SafeUploadedFile::size($file) ?? 0, $uploads);
        $message = match (true) {
            count($uploads) > ExternalTicketService::MAX_FILES => 'Lampiran maksimal '.ExternalTicketService::MAX_FILES.' file per laporan.',
            $sizes !== [] && max($sizes) > ExternalTicketService::MAX_FILE_KB * 1024 => 'Ukuran setiap lampiran maksimal '.intdiv(ExternalTicketService::MAX_FILE_KB, 1024).' MB.',
            array_sum($sizes) > ExternalTicketService::MAX_TOTAL_KB * 1024 => 'Total ukuran seluruh lampiran maksimal '.intdiv(ExternalTicketService::MAX_TOTAL_KB, 1024).' MB.',
            default => null,
        };

        if ($message !== null) {
            Notification::make()->title($message)->danger()->send();

            throw ValidationException::withMessages(['data.answers' => [$message]]);
        }
    }

    private function failExternal(string $message): never
    {
        // Isian tetap di form; pengguna bisa mencoba kirim lagi.
        Notification::make()
            ->title($message)
            ->danger()
            ->persistent()
            ->send();

        throw new Halt;
    }

    /**
     * Petakan error 422 kontrak ("answers.proses.0.judul") ke state path
     * komponen Filament ("data.answers.proses.{uuid}.judul").
     *
     * @param  array<string, mixed>  $form
     */
    private function panelValidationException(ExternalValidationException $exception, array $form): ValidationException
    {
        $state = is_array($this->data['answers'] ?? null) ? $this->data['answers'] : [];
        $messages = [];
        foreach ($exception->errors() as $path => $fieldMessages) {
            $target = ExternalFormSchema::componentErrorPath($form, (string) $path, $state) ?? 'answers';
            $messages['data.'.$target] = [...($messages['data.'.$target] ?? []), ...$fieldMessages];
        }

        Notification::make()
            ->title('Isian ditolak sistem tujuan. Periksa field yang ditandai.')
            ->body(collect($exception->errors())->flatten()->take(5)->implode("\n") ?: null)
            ->danger()
            ->send();

        if ($messages === []) {
            $messages['data.title'] = [$exception->getMessage()];
        }

        return ValidationException::withMessages($messages);
    }
}
