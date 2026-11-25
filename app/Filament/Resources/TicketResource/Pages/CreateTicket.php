<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\ExternalTicketForm;
use App\Models\ProblemCategory;
use App\Models\Unit;
use App\Models\User;
use App\Services\Integrations\External\ExternalFormSchema;
use App\Services\Integrations\External\ExternalNotFoundException;
use App\Services\Integrations\External\ExternalTicketService;
use App\Services\Integrations\External\ExternalUnavailableException;
use App\Services\Integrations\External\ExternalValidationException;
use App\Services\Integrations\PublicTicketSubmissionService;
use App\Support\SafeUploadedFile;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Facades\FilamentView;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Throwable;

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
        if (! $this->isExternalSubmission()) {
            SafeUploadedFile::wrapValidation(fn () => parent::create($another));

            return;
        }

        $this->createExternal($another);
    }

    /**
     * Form wizard sistem tujuan punya tombol "Kirim pengajuan" sendiri di
     * langkah terakhir, jadi tombol buat halaman disembunyikan.
     */
    protected function getFormActions(): array
    {
        $isWizard = fn (): bool => ExternalTicketForm::isWizardUnit($this->data['unit_id'] ?? null);

        return [
            $this->getCreateFormAction()->hidden($isWizard),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()->hidden($isWizard)] : []),
            $this->getCancelFormAction(),
        ];
    }

    public function resetExternalIdempotencyKey(): void
    {
        $this->externalIdempotencyKey = (string) Str::uuid();
    }

    /**
     * Lengkapi data sebelum disimpan ke database (tiket internal Helpdesk).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['owner_id'] = auth()->id();
        $data['ticket_statuses_id'] = 1;

        return $data;
    }

    private function isExternalSubmission(): bool
    {
        if (ExternalTicketForm::isExternalCategory($this->data['problem_category_id'] ?? null)) {
            return true;
        }

        $unitId = $this->data['unit_id'] ?? null;

        return is_numeric($unitId) && (bool) Unit::query()->find((int) $unitId)?->isExternal();
    }

    /**
     * Unit berintegrasi: pengajuan diteruskan langsung ke sistem eksternal
     * tanpa membuat baris Ticket di database Helpdesk.
     */
    private function createExternal(bool $another): void
    {
        if ($this->isCreating) {
            return;
        }

        $this->isCreating = true;
        $this->authorizeAccess();

        try {
            $this->callHook('beforeValidate');

            $data = SafeUploadedFile::wrapValidation(fn (): array => $this->form->getState());

            $this->callHook('afterValidate');

            $category = ExternalTicketForm::category($data['problem_category_id'] ?? null);
            if (! $category instanceof ProblemCategory) {
                throw ValidationException::withMessages([
                    'data.problem_category_id' => ['Kategori tiket wajib dipilih.'],
                ]);
            }

            $user = auth()->user();
            if (! $user instanceof User) {
                throw new Halt;
            }

            $result = $this->forwardExternal($category, $user, $data);
        } catch (Halt) {
            $this->isCreating = false;

            return;
        } catch (Throwable $exception) {
            $this->isCreating = false;

            throw $exception;
        }

        $notification = Notification::make()
            ->success()
            ->title("Pengajuan diteruskan ke {$result['unit']} ({$result['ref']})")
            ->body($result['title']);

        if (! empty($result['track_url'])) {
            $notification->actions([
                Action::make('track')
                    ->label('Lacak progres')
                    ->url($result['track_url'], shouldOpenInNewTab: true),
            ]);
        }

        $notification->send();

        if ($another) {
            $this->resetExternalIdempotencyKey();
            $this->fillForm();
            $this->form->dispatchClientSideStateReset();
            $this->isCreating = false;

            return;
        }

        $this->isCreating = false;

        $redirectUrl = static::getResource()::getUrl('index');
        $this->redirect($redirectUrl, navigate: FilamentView::hasSpaMode($redirectUrl));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ref: string, title: string, unit: string, integration: string, status_code: string, status_label: string, track_url: ?string, snapshot: array<string, mixed>}
     */
    private function forwardExternal(ProblemCategory $category, User $user, array $data): array
    {
        $schemas = app(ExternalFormSchema::class);

        try {
            $form = $schemas->form($category);
            // Pertanyaan badan usaha: opsi form sistem tujuan bila ada field
            // badan usahanya, selain itu daftar badan usaha helpdesk.
            [$businessEntityId, $option] = PublicTicketSubmissionService::externalBusinessEntity($form, $data, 'data.');
            [$answers, $files] = ExternalTicketForm::splitState((array) ($data['answers'] ?? []));
            [$answers, $files] = $schemas->prepareAnswers($form, $answers, $files, $user, $businessEntityId, $option);
            $this->guardUploadLimits($files);
            // Form wizard: judul diturunkan dari isian (kontrak §5).
            $title = PublicTicketSubmissionService::externalTitle(
                $form,
                $answers,
                $category,
                $data['title'] ?? null,
                'data.',
                ! ExternalFormSchema::hasSteps($form) && ExternalTicketForm::isWizardUnit($category->unit_id),
            );

            return app(ExternalTicketService::class)->submit(
                $category,
                $user,
                $title,
                $answers,
                $files,
                PublicTicketSubmissionService::externalIdempotencyKey('panel', $user, $this->externalIdempotencyKey),
                [
                    'business_entities_id' => $businessEntityId,
                    'reporter_name' => ExternalFormSchema::reporterNameAnswer($form, $answers),
                ],
            );
        } catch (ExternalValidationException $exception) {
            $wizardForm = isset($form) && ExternalTicketForm::isWizardUnit($category->unit_id)
                ? ExternalFormSchema::asWizard($form, ExternalTicketForm::unitWizard($category->unit_id)['form'] ?? null)
                : ($form ?? []);

            throw $this->panelValidationException($exception, $wizardForm);
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
            // Field badan usaha dijawab lewat pertanyaan badan usaha helpdesk.
            $target = ExternalFormSchema::businessEntityErrorKey($form, (string) $path)
                ?? ExternalFormSchema::componentErrorPath($form, (string) $path, $state)
                ?? 'answers';
            $messages['data.'.$target] = [...($messages['data.'.$target] ?? []), ...$fieldMessages];
        }

        Notification::make()
            ->title('Isian ditolak sistem tujuan. Periksa field yang ditandai.')
            ->body(collect($exception->errors())->flatten()->take(5)->implode("\n") ?: null)
            ->danger()
            ->send();

        if ($messages === []) {
            $fallbackKey = ExternalTicketForm::isWizardUnit($this->data['unit_id'] ?? null)
                ? 'data.problem_category_id'
                : 'data.title';
            $messages[$fallbackKey] = [$exception->getMessage()];
        }

        $wizard = $this->form->getComponent(
            fn (mixed $item): bool => $item instanceof Wizard
                && str_ends_with('.'.$item->getKey(), '.'.ExternalTicketForm::WIZARD_KEY),
            withHidden: true,
        );
        if ($wizard instanceof Wizard && $wizard->isVisible()) {
            $targetStepKey = ExternalTicketForm::wizardErrorStepKey($form, array_keys($exception->errors()));
            if ($targetStepKey !== null) {
                $step = collect($wizard->getChildSchema()?->getComponents() ?? [])
                    ->first(fn (mixed $item): bool => $item instanceof Step
                        && $item->getKey(isAbsolute: false) === $targetStepKey);
                if ($step instanceof Step) {
                    $this->dispatch('go-to-wizard-step', key: $wizard->getKey(), step: $step->getKey());
                }
            }
        }

        return ValidationException::withMessages($messages);
    }
}
