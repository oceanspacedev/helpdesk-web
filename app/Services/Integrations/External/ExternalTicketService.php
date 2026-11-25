<?php

namespace App\Services\Integrations\External;

use App\Models\BusinessEntity;
use App\Models\Integration;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ExternalTicketCommentNotification;
use App\Support\HelpdeskNotifier;
use App\Support\HelpdeskTicketNumber;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tiket untuk unit berintegrasi. Helpdesk meneruskan isian form ke sistem
 * eksternal secara sinkron dan hanya menyimpan tiket tipis: nomor, judul,
 * pelapor, referensi eksternal, status cermin, dan snapshot terakhir.
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
     * Kirim tiket ke sistem eksternal lalu buat tiket tipis lokal.
     *
     * Nomor helpdesk (HD-YYYY-NNNNN) dicadangkan lebih dulu sebagai baris
     * tiket tertunda yang di-commit (soft delete, tanpa external_ref) agar
     * nomor yang dikirim sebagai helpdesk_ticket_number tetap sama saat
     * pengiriman diulang dengan kunci idempotensi yang sama, termasuk setelah
     * timeout ketika sistem eksternal ternyata sudah menyimpan tiketnya.
     * Panggilan HTTP berjalan di luar transaksi database.
     *
     * - 2xx: baris cadangan diaktifkan dengan status dari snapshot; riwayat
     *   dan notifikasi "tiket dibuat" dikirim sekali (lihat Ticket::announceCreation()).
     * - 422/404: baris cadangan dihapus; tidak ada tiket yang tersisa.
     * - 5xx/timeout: baris cadangan dipertahankan (tetap tersembunyi) untuk
     *   dipakai ulang saat mencoba lagi.
     *
     * Kunci idempotensi yang sudah aktif mengembalikan tiket lokal yang ada
     * tanpa memanggil sistem eksternal lagi.
     *
     * @param  array<string, mixed>  $answers  isian non-file sesuai key field
     * @param  array<string, mixed>  $files  bentuk sama dengan answers; daun berisi UploadedFile atau list<UploadedFile>
     * @param  array{priority_id?: int|null, business_entities_id?: int|null}  $options
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
    ): Ticket {
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

        $existing = $this->findByIdempotencyKey($integration, $idempotencyKey);
        if ($existing && ! $existing->isPendingExternal()) {
            return $existing;
        }

        $client = $this->client($integration);
        $attributes = [
            'priority_id' => $this->priorityId($options['priority_id'] ?? null),
            'unit_id' => $unit->id,
            'problem_category_id' => $category->id,
            'title' => $title,
            'business_entities_id' => filled($options['business_entities_id'] ?? null)
                ? (int) $options['business_entities_id']
                : $reporter->business_entity_id,
        ];

        $reservation = $existing ?? $this->reserve($integration, $reporter, $idempotencyKey, $attributes);
        if (! $reservation->isPendingExternal()) {
            return $reservation;
        }

        try {
            $snapshot = $client->submit([
                'idempotency_key' => $idempotencyKey,
                'helpdesk_ticket_number' => HelpdeskTicketNumber::format($reservation),
                'category_key' => $categoryKey,
                'title' => $title,
                'reporter' => $this->reporterPayload($reporter),
                'answers' => $this->withoutFiles($answers),
            ], $files);
        } catch (ExternalValidationException|ExternalNotFoundException $exception) {
            // Ditolak sistem eksternal: nomor cadangan tidak dipakai.
            $this->discardReservation($reservation);

            throw $exception;
        }

        return $this->activate($reservation, $reporter, $integration, $category, $attributes, $snapshot);
    }

    /**
     * Cadangkan nomor tiket: baris tertunda di-commit dalam keadaan soft
     * delete sehingga tidak tampil di daftar mana pun sebelum diaktifkan.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function reserve(Integration $integration, User $reporter, string $idempotencyKey, array $attributes): Ticket
    {
        try {
            return $this->runAs($reporter, function () use ($integration, $reporter, $idempotencyKey, $attributes): Ticket {
                $ticket = new Ticket([
                    ...$attributes,
                    'integration_id' => $integration->id,
                    'external_idempotency_key' => $idempotencyKey,
                    'owner_id' => $reporter->id,
                    'description' => $this->pendingDescription($integration),
                    'ticket_statuses_id' => TicketStatus::OPEN,
                ]);
                $ticket->deleted_at = now();
                $ticket->save();

                return $ticket;
            });
        } catch (QueryException $exception) {
            // Pengiriman ganda bersamaan dengan kunci yang sama: baris lain
            // sudah menang di indeks unik, pakai baris itu.
            $existing = $this->findByIdempotencyKey($integration, $idempotencyKey);
            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $snapshot
     */
    private function activate(
        Ticket $reservation,
        User $reporter,
        Integration $integration,
        ProblemCategory $category,
        array $attributes,
        array $snapshot,
    ): Ticket {
        return DB::transaction(function () use ($reservation, $reporter, $integration, $category, $attributes, $snapshot): Ticket {
            /** @var Ticket $locked */
            $locked = Ticket::query()->withTrashed()->lockForUpdate()->findOrFail($reservation->getKey());
            if (! $locked->isPendingExternal()) {
                // Sudah diaktifkan oleh pengiriman bersamaan.
                return $locked;
            }

            // Aktor riwayat awal adalah pelapor (hook updated di model Ticket).
            $this->runAs($reporter, function () use ($locked, $integration, $category, $attributes, $snapshot): void {
                $locked->fill($attributes);
                $locked->description = $this->summaryDescription($integration, $category, $snapshot);
                $this->fillFromSnapshot($locked, $snapshot);
                $locked->deleted_at = null;
                $locked->save();
            });

            return $locked->refresh();
        });
    }

    private function discardReservation(Ticket $reservation): void
    {
        $fresh = Ticket::query()->withTrashed()->find($reservation->getKey());
        if ($fresh?->isPendingExternal()) {
            $fresh->forceDelete();
        }
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
     * Termasuk baris cadangan yang masih tertunda (soft delete).
     */
    private function findByIdempotencyKey(Integration $integration, string $key): ?Ticket
    {
        return Ticket::query()
            ->withTrashed()
            ->where('integration_id', $integration->id)
            ->where('external_idempotency_key', $key)
            ->first();
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
    private function reporterPayload(User $reporter): array
    {
        $unitName = $reporter->unit_id
            ? (string) Unit::query()->withTrashed()->whereKey($reporter->unit_id)->value('name')
            : '';
        $entityName = $reporter->business_entity_id
            ? (string) BusinessEntity::query()->withTrashed()->whereKey($reporter->business_entity_id)->value('name')
            : '';

        return [
            'nik' => (string) ($reporter->identity ?? ''),
            'name' => (string) $reporter->name,
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

    private function priorityId(mixed $requested): int
    {
        if (filled($requested) && Priority::query()->whereKey((int) $requested)->exists()) {
            return (int) $requested;
        }

        $id = Priority::query()->whereKey(Priority::MEDIUM)->value('id')
            ?? Priority::query()->orderBy('id')->value('id');
        if (! $id) {
            throw new \RuntimeException('Master prioritas kosong; tiket tidak dapat dibuat.');
        }

        return (int) $id;
    }

    private function pendingDescription(Integration $integration): string
    {
        return '<p>Diteruskan ke '.e($integration->name).'.</p>';
    }

    /**
     * Deskripsi lokal hanya ringkasan; isian lengkap ada di sistem eksternal.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function summaryDescription(Integration $integration, ProblemCategory $category, array $snapshot): string
    {
        // Hanya kepala statis: ringkasan snapshot (progres, tahap) berubah
        // terus dan sudah ditampilkan langsung dari external_snapshot,
        // jadi tidak dibekukan ke deskripsi saat tiket dibuat.
        return '<p>Diteruskan ke '.e($integration->name).' · Kategori '.e($category->name)
            .' · Ref '.e($snapshot['ref']).'</p>';
    }

    private function runAs(User $user, callable $callback): mixed
    {
        $guard = Auth::guard();
        $previousUser = $guard->user();
        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            if ($previousUser) {
                $guard->setUser($previousUser);
            } else {
                $guard->forgetUser();
            }
        }
    }
}
