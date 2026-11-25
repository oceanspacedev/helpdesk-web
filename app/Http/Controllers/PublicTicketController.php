<?php

namespace App\Http\Controllers;

use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\Integrations\External\ExternalFormSchema;
use App\Services\Integrations\External\ExternalNotFoundException;
use App\Services\Integrations\External\ExternalTicketService;
use App\Services\Integrations\External\ExternalTicketSnapshot;
use App\Services\Integrations\External\ExternalUnavailableException;
use App\Services\Integrations\External\ExternalValidationException;
use App\Services\Integrations\HelpdeskFormOptionsService;
use App\Services\Integrations\HelpdeskReporterRegistrationService;
use App\Services\Integrations\PublicReporterIdentityService;
use App\Services\Integrations\PublicTicketSubmissionService;
use App\Support\PhoneNumber;
use App\Support\SafeUploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class PublicTicketController extends Controller
{
    private const SESSION_SUBMISSION_TOKEN = 'helpdesk.public_ticket.submission_token';

    /** Batas pengaman unggahan form eksternal; aturan rinci divalidasi sistem tujuan. */
    private const EXTERNAL_MAX_FILES = ExternalTicketService::MAX_FILES;

    private const EXTERNAL_MAX_FILE_BYTES = ExternalTicketService::MAX_FILE_KB * 1024;

    private const EXTERNAL_MAX_TOTAL_BYTES = ExternalTicketService::MAX_TOTAL_KB * 1024;

    public function create(
        Request $request,
        PublicReporterIdentityService $reporterIdentities,
        HelpdeskFormOptionsService $formOptions,
    ): Response {
        $reporter = $reporterIdentities->current($request);
        if ($reporter) {
            $this->ensureSubmissionToken($request);
        }

        $options = $reporter
            ? $formOptions->formOptions()
            : [
                'units' => [],
                'problem_categories' => [],
                'priorities' => [],
                'business_entities' => [],
            ];

        $defaultPriorityId = collect($options['priorities'] ?? [])->first(
            fn (array $priority): bool => Str::lower((string) ($priority['name'] ?? '')) === 'medium',
        )['id'] ?? null;

        return Inertia::render('PublicTickets/Create', [
            'screen' => $reporter ? 'ticket' : 'identify',
            'reporter' => $reporter ? [
                'phone' => $reporterIdentities->maskedPhone($reporter),
            ] : null,
            'submissionToken' => (string) $request->session()->get(self::SESSION_SUBMISSION_TOKEN, ''),
            'options' => $options,
            'defaultPriorityId' => $defaultPriorityId,
            'old' => [
                'phone' => old('phone', ''),
                // Isian awal badan usaha kontrak pelapor; bisa diganti bila
                // laporannya untuk badan usaha lain.
                'business_entities_id' => old('business_entities_id', $reporter?->business_entity_id ?? ''),
                // Opsi badan usaha dari form sistem tujuan (unit berintegrasi).
                'external_business_entity' => old('external_business_entity', ''),
                'unit_id' => old('unit_id', ''),
                'problem_category_id' => old('problem_category_id', ''),
                'priority_id' => old('priority_id', $defaultPriorityId),
                'title' => old('title', ''),
                'description' => old('description', ''),
            ],
            'urls' => [
                'identify' => route('public-reporter.identify', absolute: false),
                'store' => route('public-tickets.store', absolute: false),
                // {category} diganti id kategori di browser.
                'externalForm' => str_replace(
                    '0000000000',
                    '{category}',
                    route('public-tickets.external-form', ['category' => '0000000000'], absolute: false),
                ),
                // {unit} diganti id unit di browser.
                'externalUnitForm' => str_replace(
                    '0000000000',
                    '{unit}',
                    route('public-tickets.external-unit-form', ['unit' => '0000000000'], absolute: false),
                ),
                'changeReporter' => route('public-reporter.change', absolute: false),
                'login' => route('filament.admin.auth.login', absolute: false),
            ],
            'brandUrl' => asset('images/icon.svg'),
            'appName' => (string) config('app.name'),
        ]);
    }

    public function identify(
        Request $request,
        PublicReporterIdentityService $reporterIdentities,
        HelpdeskReporterRegistrationService $registrations,
    ): RedirectResponse {
        if ($reporterIdentities->current($request)) {
            return redirect()->route('public-tickets.create');
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:0'],
        ], [
            'phone.required' => 'Nomor WhatsApp wajib diisi.',
        ]);
        $phone = PhoneNumber::canonical($validated['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor WhatsApp tidak valid. Gunakan format 08... atau 62....',
            ]);
        }

        $result = $registrations->resolveOrCreateFromSubmittedPhone($phone);

        $user = $result['user'] ?? null;
        if (($result['ok'] ?? false) && $user instanceof User) {
            $registrations->completeFromTalenta($user);
            $request->session()->regenerate(true);
            $reporterIdentities->rotateDeviceToken($request);

            return $this->finishReporterIdentification($request, $reporterIdentities, $user);
        }

        return redirect()
            ->route('public-tickets.create')
            ->withErrors(['phone' => 'Nomor WhatsApp belum dapat digunakan. Periksa kembali atau gunakan nomor aktif lain.'])
            ->withInput($request->only('phone'));
    }

    public function changeReporter(
        Request $request,
        PublicReporterIdentityService $reporterIdentities,
    ): RedirectResponse {
        if (! $reporterIdentities->forget($request)) {
            return redirect()
                ->route('public-tickets.create')
                ->withErrors(['reporter' => 'Identitas belum dapat dilepas. Coba lagi agar perangkat tidak meninggalkan akses lama.']);
        }

        $request->session()->forget(self::SESSION_SUBMISSION_TOKEN);
        $request->session()->regenerate(true);

        return redirect()
            ->route('public-tickets.create')
            ->with('status', 'Nomor WhatsApp di perangkat ini sudah dilepas.');
    }

    /**
     * Definisi form sistem eksternal untuk kategori unit berintegrasi. Hanya
     * untuk pelapor yang sudah dikenali di perangkat ini; server helpdesk yang
     * menghubungi sistem eksternal (token tidak pernah sampai ke browser).
     */
    public function externalForm(
        Request $request,
        int $category,
        PublicReporterIdentityService $reporterIdentities,
        ExternalFormSchema $schemas,
        HelpdeskReporterRegistrationService $registrations,
    ): JsonResponse {
        $reporter = $reporterIdentities->current($request);
        if (! $reporter) {
            return response()->json([
                'message' => 'Masukkan nomor WhatsApp pelapor terlebih dahulu.',
            ], 401);
        }

        $registrations->completeFromTalenta($reporter);

        $problemCategory = ProblemCategory::query()->with('unit.integration')->find($category);
        if (! $problemCategory || ! $problemCategory->unit || ! $problemCategory->isExternal()) {
            return response()->json([
                'message' => 'Kategori ini tidak memakai form sistem tujuan.',
            ], 404);
        }

        try {
            $form = $schemas->withPrefill($schemas->form($problemCategory), $reporter, reveal: false);
        } catch (ExternalNotFoundException) {
            return response()->json([
                'message' => 'Form kategori ini tidak tersedia di sistem tujuan. Pilih kategori lain.',
            ], 404);
        } catch (ExternalUnavailableException|InvalidArgumentException $exception) {
            report($exception);

            return response()->json([
                'message' => ExternalUnavailableException::USER_MESSAGE,
            ], 503);
        }

        return response()->json(['data' => $this->withCategoryCards($form, $problemCategory->unit, $schemas)]);
    }

    /**
     * Form pembuka unit berintegrasi (kontrak §3.2 "Langkah"): form kategori
     * terpetakan pertama, dipakai browser untuk menentukan apakah unit
     * memakai wizard dan untuk merender langkah sebelum pilihan kategori.
     * `category_id` = kategori yang formnya dipakai (belum dipilih pelapor).
     */
    public function externalUnitForm(
        Request $request,
        int $unit,
        PublicReporterIdentityService $reporterIdentities,
        ExternalFormSchema $schemas,
        HelpdeskReporterRegistrationService $registrations,
    ): JsonResponse {
        $reporter = $reporterIdentities->current($request);
        if (! $reporter) {
            return response()->json([
                'message' => 'Masukkan nomor WhatsApp pelapor terlebih dahulu.',
            ], 401);
        }

        $registrations->completeFromTalenta($reporter);

        $model = Unit::query()->acceptingTickets()->with('integration')->find($unit);
        if (! $model || ! $model->isExternal()) {
            return response()->json([
                'message' => 'Divisi ini tidak memakai form sistem tujuan.',
            ], 404);
        }

        try {
            $opening = $schemas->unitForm($model);
        } catch (ExternalNotFoundException) {
            $opening = null;
        } catch (ExternalUnavailableException|InvalidArgumentException $exception) {
            report($exception);

            return response()->json([
                'message' => ExternalUnavailableException::USER_MESSAGE,
            ], 503);
        }

        if ($opening === null) {
            return response()->json([
                'message' => 'Belum ada kategori divisi ini yang tersedia di sistem tujuan.',
            ], 404);
        }

        $form = $schemas->withPrefill($opening['form'], $reporter, reveal: false);

        return response()->json([
            'data' => $this->withCategoryCards($form, $model, $schemas),
            'category_id' => (int) $opening['category']->getKey(),
        ]);
    }

    /**
     * Form wizard dengan pilihan kategori: tambahkan kartu kategori unit.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function withCategoryCards(array $form, ?Unit $unit, ExternalFormSchema $schemas): array
    {
        if ($unit && ExternalFormSchema::hasCategoryPicker($form)) {
            $form['categories'] = $schemas->unitCategories($unit);
        }

        return $form;
    }

    public function store(
        Request $request,
        PublicReporterIdentityService $reporterIdentities,
        PublicTicketSubmissionService $tickets,
    ): HttpResponse {
        $reporter = $reporterIdentities->current($request);
        if (! $reporter) {
            return redirect()
                ->route('public-tickets.create')
                ->withErrors(['reporter' => 'Masukkan nomor WhatsApp pelapor terlebih dahulu.']);
        }

        if ($this->isExternalCategory($request)) {
            return $this->storeExternal($request, $reporter, $tickets);
        }

        $validator = Validator::make($request->all(), [
            'submission_token' => ['required', 'string', 'size:40'],
            'website' => ['nullable', 'string', 'max:0'],
            'business_entities_id' => [
                'required',
                'integer',
                Rule::exists('business_entities', 'id')->whereNull('deleted_at'),
            ],
            'unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
            ],
            'problem_category_id' => [
                'required',
                'integer',
                Rule::exists('problem_categories', 'id')
                    ->where('unit_id', (int) $request->input('unit_id'))
                    ->whereNull('deleted_at'),
            ],
            'priority_id' => ['required', 'integer', Rule::exists('priorities', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:60000'],
            'supporting_attachments' => ['nullable', 'array', 'max:5'],
            'supporting_attachments.*' => [
                'file',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,jpg,jpeg,png',
                'max:10240',
            ],
        ], [
            'business_entities_id.required' => 'Badan usaha wajib dipilih.',
            'unit_id.required' => 'Divisi wajib dipilih.',
            'problem_category_id.required' => 'Kategori tiket wajib dipilih.',
            'problem_category_id.exists' => 'Kategori tidak sesuai dengan divisi yang dipilih.',
            'priority_id.required' => 'Prioritas wajib dipilih.',
            'title.required' => 'Judul laporan wajib diisi.',
            'description.required' => 'Detail kendala wajib diisi.',
            'supporting_attachments.max' => 'Lampiran maksimal 5 file.',
            'supporting_attachments.*.mimes' => 'Jenis salah satu lampiran tidak didukung.',
            'supporting_attachments.*.max' => 'Ukuran setiap lampiran maksimal 10 MB.',
        ]);
        $validator->after(function ($validator) use ($request): void {
            $expectedToken = (string) $request->session()->get(self::SESSION_SUBMISSION_TOKEN, '');
            $providedToken = (string) $request->input('submission_token', '');
            if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
                $validator->errors()->add('submission_token', 'Sesi form sudah berubah. Muat ulang halaman sebelum mengirim.');
            }

            $files = $request->file('supporting_attachments', []);
            $files = is_array($files) ? $files : [$files];
            $totalBytes = collect($files)
                ->sum(function ($file): int {
                    try {
                        return SafeUploadedFile::size($file) ?? 0;
                    } catch (Throwable) {
                        return 0;
                    }
                });
            if ($totalBytes > 10 * 1024 * 1024) {
                $validator->errors()->add('supporting_attachments', 'Total ukuran seluruh lampiran maksimal 10 MB.');
            }
        });
        $validated = $validator->validate();
        $submissionToken = (string) $validated['submission_token'];

        try {
            $uploads = $request->file('supporting_attachments', []);
            $ticket = $tickets->create(
                $reporter,
                $validated,
                is_array($uploads) ? $uploads : [$uploads],
                $submissionToken,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $ticket = $tickets->findCommitted($reporter, $submissionToken);
            if (! $ticket) {
                return back()
                    ->withInput($request->except(['supporting_attachments']))
                    ->withErrors(['ticket' => 'Laporan belum dapat disimpan. Coba lagi atau hubungi administrator Helpdesk.']);
            }
        }

        $request->session()->forget(self::SESSION_SUBMISSION_TOKEN);

        return redirect()
            ->route('public-tickets.create')
            ->with('ticket_success', $this->successPayload($ticket));
    }

    /**
     * Kategori yang dipilih milik unit berintegrasi: laporan diteruskan ke
     * sistem eksternal (lihat docs/integrations/README.md §5).
     */
    private function isExternalCategory(Request $request): bool
    {
        $categoryId = $request->input('problem_category_id');
        $unitId = $request->input('unit_id');
        if (! is_numeric($categoryId) || ! is_numeric($unitId)) {
            return false;
        }

        $category = ProblemCategory::query()
            ->with('unit')
            ->whereKey((int) $categoryId)
            ->where('unit_id', (int) $unitId)
            ->first();

        return (bool) $category?->unit?->isExternal();
    }

    private function storeExternal(
        Request $request,
        User $reporter,
        PublicTicketSubmissionService $tickets,
    ): HttpResponse {
        $validator = Validator::make($request->all(), [
            'submission_token' => ['required', 'string', 'size:40'],
            'website' => ['nullable', 'string', 'max:0'],
            // Wajib atau tidaknya bergantung pada form sistem tujuan: bila
            // form punya field badan usaha, pertanyaan ini memakai opsinya
            // (external_business_entity). Diperiksa createExternal.
            'business_entities_id' => [
                'nullable',
                'integer',
                Rule::exists('business_entities', 'id')->whereNull('deleted_at'),
            ],
            'external_business_entity' => ['nullable', 'string', 'max:255'],
            'unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
            ],
            'problem_category_id' => [
                'required',
                'integer',
                Rule::exists('problem_categories', 'id')
                    ->where('unit_id', (int) $request->input('unit_id'))
                    ->whereNull('deleted_at'),
            ],
            // Form wizard tidak menanyakan judul: judul diturunkan server dari
            // isian (createExternal). Form satu halaman tetap wajib berjudul.
            'title' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'answers' => ['nullable', 'array'],
        ], [
            'external_business_entity.*' => 'Pilihan badan usaha tidak valid. Muat ulang halaman.',
            'unit_id.required' => 'Divisi wajib dipilih.',
            'problem_category_id.required' => 'Kategori tiket wajib dipilih.',
            'problem_category_id.exists' => 'Kategori tidak sesuai dengan divisi yang dipilih.',
            'title.required' => 'Judul laporan wajib diisi.',
            'idempotency_key.*' => 'Sesi form sudah berubah. Muat ulang halaman sebelum mengirim.',
            'answers.array' => 'Isian form tidak valid. Muat ulang halaman.',
        ]);
        $validator->after(function ($validator) use ($request): void {
            $expectedToken = (string) $request->session()->get(self::SESSION_SUBMISSION_TOKEN, '');
            $providedToken = (string) $request->input('submission_token', '');
            if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
                $validator->errors()->add('submission_token', 'Sesi form sudah berubah. Muat ulang halaman sebelum mengirim.');
            }

            $files = $this->uploadedFiles($request->file('answers', []));
            $sizes = array_map(function (UploadedFile $file): int {
                try {
                    return SafeUploadedFile::size($file) ?? 0;
                } catch (Throwable) {
                    return 0;
                }
            }, $files);
            if (count($files) > self::EXTERNAL_MAX_FILES) {
                $validator->errors()->add('answers', 'Lampiran maksimal '.self::EXTERNAL_MAX_FILES.' file per laporan.');
            } elseif ($sizes !== [] && max($sizes) > self::EXTERNAL_MAX_FILE_BYTES) {
                $validator->errors()->add('answers', 'Ukuran setiap lampiran maksimal 20 MB.');
            } elseif (array_sum($sizes) > self::EXTERNAL_MAX_TOTAL_BYTES) {
                $validator->errors()->add('answers', 'Total ukuran seluruh lampiran maksimal 50 MB.');
            }
        });
        $validated = $validator->validate();

        $answers = $request->input('answers', []);
        $files = $request->file('answers', []);

        try {
            $submission = $tickets->createExternal(
                $reporter,
                $validated,
                is_array($answers) ? $answers : [],
                is_array($files) ? $files : [],
            );
        } catch (ExternalValidationException $exception) {
            // Kunci error kontrak ("answers.proses.0.judul") dipakai apa
            // adanya agar tampil di bawah field terkait.
            throw $exception->toValidationException();
        } catch (ExternalNotFoundException) {
            return back()
                ->withInput($request->except(['answers']))
                ->withErrors(['problem_category_id' => 'Kategori ini sudah tidak tersedia di sistem tujuan. Pilih kategori lain.']);
        } catch (ExternalUnavailableException $exception) {
            report($exception);

            return back()
                ->withInput($request->except(['answers']))
                ->withErrors(['external' => $exception->userMessage()]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except(['answers']))
                ->withErrors(['ticket' => 'Laporan belum dapat disimpan. Coba lagi atau hubungi administrator Helpdesk.']);
        }

        $request->session()->forget(self::SESSION_SUBMISSION_TOKEN);

        return redirect()
            ->route('public-tickets.create')
            ->with('ticket_success', [
                'number' => null,
                'title' => $submission['title'],
                'unit' => $submission['unit'],
                'external_ref' => $submission['ref'],
                'track_url' => $submission['track_url'],
            ]);
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedFiles(mixed $value): array
    {
        if ($value instanceof UploadedFile) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $files = [];
        foreach ($value as $item) {
            array_push($files, ...$this->uploadedFiles($item));
        }

        return $files;
    }

    private function finishReporterIdentification(
        Request $request,
        PublicReporterIdentityService $reporterIdentities,
        User $user,
    ): RedirectResponse {
        if (! $reporterIdentities->remember($request, $user)) {
            return redirect()
                ->route('public-tickets.create')
                ->withErrors(['phone' => 'Nomor WhatsApp belum dapat diingat oleh Helpdesk. Coba masukkan kembali.']);
        }

        $this->ensureSubmissionToken($request);

        return redirect()
            ->route('public-tickets.create')
            ->with('status', 'Nomor WhatsApp tersimpan. Anda bisa langsung mengisi laporan.');
    }

    private function ensureSubmissionToken(Request $request): void
    {
        $token = (string) $request->session()->get(self::SESSION_SUBMISSION_TOKEN, '');
        if (! preg_match('/^[A-Za-z0-9]{40}$/D', $token)) {
            $request->session()->put(self::SESSION_SUBMISSION_TOKEN, Str::random(40));
        }
    }

    /**
     * @return array{number: string, title: string, unit: ?string, external_ref: ?string, track_url: ?string}
     */
    private function successPayload(Ticket $ticket): array
    {
        $unitName = null;
        try {
            $unitName = $ticket->unit?->name;
        } catch (Throwable) {
            $unitName = null;
        }

        $year = null;
        try {
            $year = $ticket->created_at?->format('Y');
        } catch (Throwable) {
            $year = null;
        }

        $externalRef = $ticket->isExternal() && filled($ticket->external_ref)
            ? (string) $ticket->external_ref
            : null;
        $trackUrl = $ticket->isExternal()
            ? ExternalTicketSnapshot::trackUrl($ticket)
            : null;

        return [
            'number' => $this->ticketNumber((int) $ticket->id, $year),
            'title' => (string) $ticket->title,
            'unit' => $unitName,
            'external_ref' => $externalRef,
            'track_url' => $trackUrl,
        ];
    }

    private function ticketNumber(int $id, ?string $year): string
    {
        return 'HD-'.($year ?: now()->format('Y')).'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }
}
