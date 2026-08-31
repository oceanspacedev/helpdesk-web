<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Integrations\HelpdeskFormOptionsService;
use App\Services\Integrations\HelpdeskReporterRegistrationService;
use App\Services\Integrations\PublicReporterIdentityService;
use App\Services\Integrations\PublicTicketSubmissionService;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicTicketController extends Controller
{
    private const SESSION_SUBMISSION_TOKEN = 'helpdesk.public_ticket.submission_token';

    public function create(
        Request $request,
        PublicReporterIdentityService $reporterIdentities,
        HelpdeskFormOptionsService $formOptions,
    ): View {
        $reporter = $reporterIdentities->current($request);
        if ($reporter) {
            $this->ensureSubmissionToken($request);
        }

        $options = $formOptions->formOptions();

        return view('public-tickets.create', [
            'reporter' => $reporter,
            'reporterPhone' => $reporter ? $reporterIdentities->maskedPhone($reporter) : null,
            'submissionToken' => (string) $request->session()->get(self::SESSION_SUBMISSION_TOKEN, ''),
            'options' => $options,
            'defaultPriorityId' => collect($options['priorities'] ?? [])->first(
                fn (array $priority): bool => Str::lower((string) ($priority['name'] ?? '')) === 'medium',
            )['id'] ?? null,
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

    public function store(
        Request $request,
        PublicReporterIdentityService $reporterIdentities,
        PublicTicketSubmissionService $tickets,
    ): RedirectResponse {
        $reporter = $reporterIdentities->current($request);
        if (! $reporter) {
            return redirect()
                ->route('public-tickets.create')
                ->withErrors(['reporter' => 'Masukkan nomor WhatsApp pelapor terlebih dahulu.']);
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
            'business_entities_id.required' => 'Entitas bisnis wajib dipilih.',
            'unit_id.required' => 'Unit tujuan wajib dipilih.',
            'problem_category_id.required' => 'Kategori masalah wajib dipilih.',
            'problem_category_id.exists' => 'Kategori tidak sesuai dengan unit tujuan yang dipilih.',
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
                ->sum(fn ($file): int => is_object($file) && method_exists($file, 'getSize')
                    ? (int) $file->getSize()
                    : 0);
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
            $request->session()->forget(self::SESSION_SUBMISSION_TOKEN);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except(['supporting_attachments']))
                ->withErrors(['ticket' => 'Laporan belum dapat disimpan. Coba lagi atau hubungi administrator Helpdesk.']);
        }

        return redirect()
            ->route('public-tickets.create')
            ->with('ticket_success', [
                'number' => $this->ticketNumber($ticket->id, $ticket->created_at?->format('Y')),
                'title' => $ticket->title,
                'unit' => $ticket->unit?->name,
            ]);
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

    private function ticketNumber(int $id, ?string $year): string
    {
        return 'HD-'.($year ?: now()->format('Y')).'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }
}
