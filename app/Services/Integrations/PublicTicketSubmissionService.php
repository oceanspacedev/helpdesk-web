<?php

namespace App\Services\Integrations;

use App\Models\BusinessEntity;
use App\Models\McpTicketCreationRequest;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use App\Support\HelpdeskIntegrationClient;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PublicTicketSubmissionService
{
    private const CLIENT_ID = 'public-helpdesk-form';

    /**
     * @param  list<UploadedFile>  $uploads
     */
    public function create(
        User $reporter,
        array $data,
        array $uploads = [],
        string $submissionToken = '',
    ): Ticket {
        $expectedPhone = PhoneNumber::canonical($reporter->phone);
        if (! $reporter->is_active || $reporter->trashed() || $expectedPhone === null) {
            throw ValidationException::withMessages([
                'reporter' => 'Identitas pelapor sudah tidak aktif atau tidak valid.',
            ]);
        }

        $description = $this->description((string) $data['description']);
        if (strlen($description) > 65000) {
            throw ValidationException::withMessages([
                'description' => 'Detail kendala terlalu panjang setelah diformat. Ringkas isi laporan lalu coba lagi.',
            ]);
        }

        $submissionToken = trim($submissionToken) ?: (string) Str::uuid();
        $clientKey = HelpdeskIntegrationClient::persistentKey(self::CLIENT_ID);
        $keyHash = hash('sha256', $submissionToken);
        $requestHash = $this->requestHash($reporter, $data, $uploads);
        $storedPaths = [];

        try {
            $ticket = DB::transaction(function () use (
                $reporter,
                $data,
                $uploads,
                $description,
                $expectedPhone,
                $clientKey,
                $keyHash,
                $requestHash,
                &$storedPaths,
            ): Ticket {
                McpTicketCreationRequest::query()->insertOrIgnore([
                    'client_key' => $clientKey,
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $submission = McpTicketCreationRequest::query()
                    ->where('client_key', $clientKey)
                    ->where('key_hash', $keyHash)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! hash_equals((string) $submission->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'submission_token' => 'Sesi form sudah digunakan untuk isi laporan yang berbeda. Muat ulang halaman.',
                    ]);
                }

                if ($submission->status === 'completed') {
                    $ticketId = (int) data_get($submission->response, 'ticket_id');
                    $existing = Ticket::query()->find($ticketId);
                    if (! $existing) {
                        throw new RuntimeException('Catatan idempotensi tidak menunjuk tiket yang tersedia.');
                    }

                    return $existing;
                }

                $freshReporter = User::query()
                    ->withTrashed()
                    ->whereKey($reporter->id)
                    ->lockForUpdate()
                    ->first();
                $freshPhone = PhoneNumber::canonical($freshReporter?->phone);
                if (! $freshReporter || $freshReporter->trashed() || ! $freshReporter->is_active
                    || $freshPhone === null || ! hash_equals($expectedPhone, $freshPhone)) {
                    throw ValidationException::withMessages([
                        'reporter' => 'Nomor pelapor berubah atau akunnya dinonaktifkan. Masukkan kembali nomor WhatsApp sebelum mengirim.',
                    ]);
                }

                $unit = Unit::query()->findOrFail((int) $data['unit_id']);
                $category = ProblemCategory::query()
                    ->whereKey((int) $data['problem_category_id'])
                    ->where('unit_id', $unit->id)
                    ->firstOrFail();
                $priority = Priority::query()->findOrFail((int) $data['priority_id']);
                $businessEntity = BusinessEntity::query()->findOrFail((int) $data['business_entities_id']);

                foreach (array_slice($uploads, 0, 5) as $upload) {
                    if (! $upload instanceof UploadedFile) {
                        continue;
                    }

                    $path = $upload->store('ticket-supporting/'.now()->format('m-y'), 'public');
                    if (! is_string($path) || $path === '') {
                        throw new RuntimeException('Lampiran gagal disimpan.');
                    }

                    $storedPaths[] = $path;
                }

                $guard = Auth::guard();
                $previousUser = $guard->user();
                $guard->setUser($freshReporter);

                try {
                    $ticket = Ticket::query()->create([
                        'priority_id' => $priority->id,
                        'unit_id' => $unit->id,
                        'owner_id' => $freshReporter->id,
                        'problem_category_id' => $category->id,
                        'title' => (string) $data['title'],
                        'description' => $description,
                        'supporting_attachments' => $storedPaths === [] ? null : $storedPaths,
                        'ticket_statuses_id' => TicketStatus::OPEN,
                        'business_entities_id' => $businessEntity->id,
                    ]);
                } finally {
                    if ($previousUser) {
                        $guard->setUser($previousUser);
                    } else {
                        $guard->forgetUser();
                    }
                }

                $submission->update([
                    'status' => 'completed',
                    'response' => ['ticket_id' => $ticket->id],
                ]);

                return $ticket;
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('public')->delete($storedPaths);
            }

            throw $exception;
        }

        return $ticket->loadMissing([
            'priority',
            'unit',
            'owner',
            'problemCategory',
            'ticketStatus',
            'businessEntity',
        ]);
    }

    /**
     * @param  list<UploadedFile>  $uploads
     */
    private function requestHash(User $reporter, array $data, array $uploads): string
    {
        $files = [];
        foreach (array_slice($uploads, 0, 5) as $upload) {
            if (! $upload instanceof UploadedFile) {
                continue;
            }

            $path = $upload->getRealPath();
            $contentHash = is_string($path) && $path !== '' ? hash_file('sha256', $path) : false;
            if (! is_string($contentHash)) {
                throw new RuntimeException('Lampiran tidak dapat dibaca untuk verifikasi pengiriman.');
            }

            $files[] = [
                'name' => $upload->getClientOriginalName(),
                'size' => $upload->getSize(),
                'content_hash' => $contentHash,
            ];
        }

        return hash('sha256', json_encode([
            'reporter_id' => $reporter->id,
            'business_entities_id' => (int) $data['business_entities_id'],
            'unit_id' => (int) $data['unit_id'],
            'problem_category_id' => (int) $data['problem_category_id'],
            'priority_id' => (int) $data['priority_id'],
            'title' => (string) $data['title'],
            'description' => (string) $data['description'],
            'attachments' => $files,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function description(string $description): string
    {
        return '<p>'.nl2br(e(trim($description))).'</p>'
            .'<p><strong>Dilaporkan via Form Publik</strong></p>';
    }
}
