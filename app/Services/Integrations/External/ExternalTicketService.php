<?php

namespace App\Services\Integrations\External;

use App\Models\BusinessEntity;
use App\Models\ExternalSubmission;
use App\Models\Integration;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ExternalTicketCommentNotification;
use App\Support\HelpdeskNotifier;
use App\Support\HelpdeskReporterName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pengiriman laporan untuk unit berintegrasi. Helpdesk meneruskan isian form
 * langsung ke sistem eksternal secara sinkron tanpa menyimpan tiket ganda
 * di Helpdesk. Metode snapshot/webhook tetap dipertahankan untuk kompatibilitas
 * tiket eksternal historis.
 */
class ExternalTicketService
{
    public const IDEMPOTENCY_KEY_MAX_LENGTH = 64;

    /**
     * Batas lampiran yang diteruskan per laporan (form publik dan panel),
     * berlaku walau form eksternal tidak menyebut max_files/max_kb.
     */
    public const MAX_FILES = 20;

    public const MAX_FILE_KB = 20 * 1024;

    public const MAX_TOTAL_KB = 50 * 1024;

    /**
     * Teruskan laporan langsung ke sistem eksternal tanpa membuat baris Ticket
     * di database Helpdesk.
     *
     * @param  array<string, mixed>  $answers  isian non-file sesuai key field
     * @param  array<string, mixed>  $files  bentuk sama dengan answers; daun berisi UploadedFile atau list<UploadedFile>
     * @param  array{business_entities_id?: int|null, reporter_name?: string|null}  $options
     * @return array{
     *     ref: string,
     *     title: string,
     *     unit: string,
     *     integration: string,
     *     status_code: string,
     *     status_label: string,
     *     track_url: ?string,
     *     snapshot: array<string, mixed>,
     * }
     *
     * @throws ExternalValidationException 422 dari sistem eksternal (error per field "answers.*")
     * @throws ExternalUnavailableException 5xx/timeout/koneksi/integrasi nonaktif
     * @throws ExternalNotFoundException kategori tidak dikenal sistem eksternal
     * @throws InvalidArgumentException kategori bukan milik unit berintegrasi atau input tidak valid
     */
    public function submit(
        ProblemCategory $category,
        User $reporter,
        string $title,
        array $answers,
        array $files,
        string $idempotencyKey,
        array $options = [],
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || Str::length($idempotencyKey) > self::IDEMPOTENCY_KEY_MAX_LENGTH) {
            throw new InvalidArgumentException('idempotency_key wajib diisi dan maksimal 64 karakter.');
        }

        $title = Str::limit(trim($title), 250, '');
        if ($title === '') {
            throw new InvalidArgumentException('Judul tiket wajib diisi.');
        }

        $category->loadMissing('unit.integration');
        $unit = $category->unit;
        $integration = $unit?->integration;
        if (! $unit || ! $integration) {
            throw new InvalidArgumentException('Kategori ini bukan milik unit berintegrasi.');
        }

        $categoryKey = trim((string) $category->external_key);
        if ($categoryKey === '') {
            throw new ExternalUnavailableException('Kategori '.$category->name.' belum dipetakan ke sistem tujuan.');
        }

        $cacheKey = 'external_submit:'.$integration->id.':'.hash('sha256', $idempotencyKey);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['ref'])) {
            return $cached;
        }

        $snapshot = $this->client($integration)->submit([
            'idempotency_key' => $idempotencyKey,
            'category_key' => $categoryKey,
            'title' => $title,
            'reporter' => $this->reporterPayload($reporter, $options['reporter_name'] ?? null),
            'answers' => $this->withoutFiles($answers),
        ], $files);

        if (HelpdeskReporterName::isPlaceholder($reporter) && is_string($options['reporter_name'] ?? null)) {
            $normalizedName = HelpdeskReporterName::normalize($options['reporter_name']);
            if ($normalizedName !== null) {
                $reporter->forceFill(['name' => $normalizedName])->save();
            }
        }

        $result = [
            'ref' => (string) $snapshot['ref'],
            'title' => $title,
            'unit' => (string) $unit->name,
            'integration' => (string) $integration->name,
            'status_code' => (string) $snapshot['status']['code'],
            'status_label' => (string) $snapshot['status']['label'],
            'track_url' => ExternalTicketSnapshot::resolveTrackUrl($snapshot['track_url'] ?? null, $integration->base_url),
            'snapshot' => $snapshot,
        ];

        Cache::put($cacheKey, $result, now()->addMinutes(30));

        // Jejak ringan (kode referensi + tautan lacak) untuk "Pengajuan Saya".
        ExternalSubmission::record($integration, $reporter, $category, $result);

        return $result;
    }

    /**
     * Muat ulang snapshot lewat GET tickets/{ref} lalu terapkan.
     *
     * @return bool true bila snapshot diterapkan, false bila lebih lama dari yang tersimpan
     *
     * @throws ExternalUnavailableException|ExternalNotFoundException
     */
    public function refresh(Ticket $ticket): bool
    {
        $ticket->loadMissing('integration');
        $integration = $ticket->integration;
        if (! $ticket->isExternal() || ! $integration || blank($ticket->external_ref)) {
            throw new InvalidArgumentException('Tiket ini bukan tiket eksternal.');
        }

        $snapshot = $this->client($integration)->ticket((string) $ticket->external_ref);

        return $this->applySnapshot($ticket, $snapshot);
    }

    /**
     * Terapkan TicketSnapshot (dari webhook atau muat ulang): abaikan bila
     * updated_at lebih lama dari yang tersimpan, perbarui status cermin dan
     * snapshot, catat riwayat bila status berubah (lewat hook model), dan
     * kabari pelapor bila status berubah atau ada komentar baru.
     *
     * @param  array<string, mixed>  $snapshot  TicketSnapshot mentah atau ternormalisasi
     * @return bool true bila diterapkan, false bila diabaikan karena lebih lama
     *
     * @throws InvalidArgumentException snapshot tidak sesuai kontrak atau ref berbeda
     */
    public function applySnapshot(Ticket $ticket, array $snapshot): bool
    {
        $snapshot = ExternalTicketSnapshot::normalize($snapshot);

        if (! $ticket->isExternal()) {
            throw new InvalidArgumentException('Tiket ini bukan tiket eksternal.');
        }

        if (filled($ticket->external_ref) && ! hash_equals((string) $ticket->external_ref, $snapshot['ref'])) {
            throw new InvalidArgumentException('Ref snapshot tidak cocok dengan tiket.');
        }

        return DB::transaction(function () use ($ticket, $snapshot): bool {
            /** @var Ticket $locked */
            $locked = Ticket::query()->withTrashed()->lockForUpdate()->findOrFail($ticket->getKey());
            $previous = is_array($locked->external_snapshot) ? $locked->external_snapshot : null;

            $storedAt = ExternalTicketSnapshot::parseTime($previous['updated_at'] ?? null);
            $incomingAt = ExternalTicketSnapshot::parseTime($snapshot['updated_at']);
            if ($storedAt && $incomingAt && $incomingAt->lt($storedAt)) {
                return false;
            }

            $newComments = ExternalTicketSnapshot::newComments($previous, $snapshot);

            // Perubahan status memicu riwayat dan notifikasi status ke
            // pelapor lewat hook updated di model Ticket.
            $this->fillFromSnapshot($locked, $snapshot);
            $locked->save();

            if ($newComments !== []) {
                $owner = User::query()->find($locked->owner_id);
                if ($owner) {
                    HelpdeskNotifier::send($owner, new ExternalTicketCommentNotification($locked, $newComments));
                }
            }

            $ticket->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Tiket lokal untuk referensi eksternal pada satu integrasi, termasuk
     * yang sudah dihapus (soft delete).
     */
    public function findByRef(Integration $integration, string $ref): ?Ticket
    {
        return Ticket::query()
            ->withTrashed()
            ->where('integration_id', $integration->id)
            ->where('external_ref', $ref)
            ->first();
    }

    /**
     * Definisi form untuk kategori unit berintegrasi (GET forms/{external_key}).
     *
     * @return array<string, mixed>
     *
     * @throws ExternalUnavailableException|ExternalNotFoundException
     */
    public function form(ProblemCategory $category): array
    {
        $category->loadMissing('unit.integration');
        $integration = $category->unit?->integration;
        if (! $integration || blank($category->external_key)) {
            throw new InvalidArgumentException('Kategori ini bukan milik unit berintegrasi.');
        }

        return $this->client($integration)->form((string) $category->external_key);
    }

    public function client(Integration $integration): ExternalSystemClient
    {
        return ExternalSystemClient::for($integration);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function fillFromSnapshot(Ticket $ticket, array $snapshot): void
    {
        $ticket->external_ref = $snapshot['ref'];
        $ticket->external_status_code = $snapshot['status']['code'];
        $ticket->external_status_label = $snapshot['status']['label'];
        $ticket->external_snapshot = $snapshot;
        $ticket->external_synced_at = now();
        $ticket->ticket_statuses_id = ExternalTicketSnapshot::statusId($snapshot['status']['code']);
    }

    /**
     * Identitas pelapor dari helpdesk; hanya name dan phone yang wajib.
     *
     * @return array<string, string>
     */
    private function reporterPayload(User $reporter, ?string $typedName = null): array
    {
        $unitName = $reporter->unit_id
            ? (string) Unit::query()->withTrashed()->whereKey($reporter->unit_id)->value('name')
            : '';
        $entityName = $reporter->business_entity_id
            ? (string) BusinessEntity::query()->withTrashed()->whereKey($reporter->business_entity_id)->value('name')
            : '';

        return [
            'nik' => (string) ($reporter->identity ?? ''),
            // Kontrak §3.3: name tidak pernah kosong. Akun bernama sementara
            // ("Pelapor WhatsApp 1234") memakai nama yang diisi di form bila ada.
            'name' => HelpdeskReporterName::isPlaceholder($reporter) && filled($typedName)
                ? trim((string) $typedName)
                : (string) $reporter->name,
            'phone' => (string) ($reporter->phone ?? ''),
            'email' => (string) ($reporter->email ?? ''),
            'unit' => $unitName,
            'job_title' => (string) ($reporter->job_title ?? ''),
            'business_entity' => $entityName,
        ];
    }

    /**
     * File hanya dikirim lewat $files; UploadedFile yang terselip di answers
     * dibuang agar tidak terkirim dua kali.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function withoutFiles(array $answers): array
    {
        $clean = [];
        foreach ($answers as $key => $value) {
            if ($value instanceof UploadedFile) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->withoutFiles($value) : $value;
        }

        return $clean;
    }
}
