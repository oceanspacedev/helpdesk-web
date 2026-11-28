<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Support\HelpdeskTicketNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Feed tiket read-only untuk dashboard luar (layar TV). Hanya tiket yang
 * ditujukan ke divisi di config helpdesk_dashboard.unit_names (default IT).
 * Kontrak lengkap: docs/integrations/dashboard-api.md.
 */
class DashboardTicketController extends Controller
{
    private const RELATIONS = [
        'priority:id,name',
        'unit:id,name',
        'problemCategory:id,name',
        'ticketStatus:id,name',
        'owner:id,name',
        'responsible:id,name',
        'reporterUnit:id,name',
        'businessEntity:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:100'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $this->baseQuery()->with(self::RELATIONS);

        if (filled($validated['status'] ?? null)) {
            $statusIds = $this->statusIds((string) $validated['status']);
            $query->whereIn('tickets.ticket_statuses_id', $statusIds === [] ? [0] : $statusIds);
        }
        if (filled($validated['created_from'] ?? null)) {
            $query->where('tickets.created_at', '>=', Carbon::parse($validated['created_from']));
        }
        if (filled($validated['created_to'] ?? null)) {
            $query->where('tickets.created_at', '<=', Carbon::parse($validated['created_to']));
        }
        if (filled($validated['updated_since'] ?? null)) {
            $query->where('tickets.updated_at', '>=', Carbon::parse($validated['updated_since']));
        }

        $page = $query
            ->orderByDesc('tickets.created_at')
            ->orderByDesc('tickets.id')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Ticket $ticket): array => $this->present($ticket))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'server_time' => now()->toIso8601String(),
                'stream_cursor' => (string) now()->getTimestamp(),
            ],
        ]);
    }

    public function show(int $ticket): JsonResponse
    {
        $model = $this->baseQuery()->with(self::RELATIONS)->whereKey($ticket)->first();
        if (! $model) {
            return response()->json(['error' => 'not_found', 'message' => 'Tiket tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $this->present($model)]);
    }

    public function summary(): JsonResponse
    {
        $counts = $this->baseQuery()
            ->toBase()
            ->selectRaw('tickets.ticket_statuses_id as status_id, count(*) as total')
            ->groupBy('tickets.ticket_statuses_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->status_id => (int) $row->total]);

        $byStatus = TicketStatus::query()->orderBy('id')->get(['id', 'name'])
            ->map(fn (TicketStatus $status): array => [
                'id' => (int) $status->id,
                'name' => $status->name,
                'total' => (int) ($counts[$status->id] ?? 0),
            ])->values();

        $today = now()->startOfDay();

        return response()->json([
            'data' => [
                'total' => (int) $counts->sum(),
                'by_status' => $byStatus,
                'created_today' => $this->baseQuery()->where('tickets.created_at', '>=', $today)->count(),
                'closed_today' => $this->baseQuery()
                    ->where('tickets.ticket_statuses_id', TicketStatus::CLOSED)
                    ->where('tickets.solved_at', '>=', $today)
                    ->count(),
                'overdue' => $this->baseQuery()
                    ->whereIn('tickets.ticket_statuses_id', [TicketStatus::OPEN, TicketStatus::IN_PROGRESS])
                    ->whereNotNull('tickets.sla_due_at')
                    ->where('tickets.sla_due_at', '<', now())
                    ->count(),
            ],
            'meta' => ['server_time' => now()->toIso8601String()],
        ]);
    }

    /**
     * Server-Sent Events: kirim tiket yang dibuat/berubah/dihapus sejak
     * cursor. Cursor = detik unix updated_at terakhir yang dikirim, dibawa
     * sebagai id event sehingga EventSource melanjutkan lewat Last-Event-ID.
     */
    public function stream(Request $request): StreamedResponse
    {
        $cursor = $this->initialCursor($request);
        $pollSeconds = max(1, (int) config('helpdesk_dashboard.stream_poll_seconds', 3));
        $lifetime = max(0, (int) config('helpdesk_dashboard.stream_lifetime_seconds', 300));

        return response()->stream(function () use ($cursor, $pollSeconds, $lifetime): void {
            if (! app()->runningUnitTests()) {
                @set_time_limit($lifetime + 30);
            }
            // Lepas buffer output PHP agar tiap event langsung terkirim. Saat
            // tes, buffer milik penangkap streamedContent() dibiarkan.
            $keepLevels = app()->runningUnitTests() ? ob_get_level() : 0;
            while (ob_get_level() > $keepLevels) {
                ob_end_flush();
            }

            $deadline = microtime(true) + $lifetime;
            $lastWrite = microtime(true);
            // Tiket yang sudah dikirim pada detik cursor, agar tidak ganda
            // saat query berikutnya memakai >= cursor.
            $sentAtCursor = [];

            echo "retry: 3000\n";
            $this->emit('ready', [
                'cursor' => (string) $cursor->getTimestamp(),
                'server_time' => now()->toIso8601String(),
            ], (string) $cursor->getTimestamp());

            do {
                try {
                    $changes = $this->baseQuery(withTrashed: true)
                        ->with(self::RELATIONS)
                        ->where('tickets.updated_at', '>=', $cursor)
                        ->orderBy('tickets.updated_at')
                        ->orderBy('tickets.id')
                        ->limit(200)
                        ->get();
                } catch (Throwable $exception) {
                    report($exception);
                    $changes = collect();
                }

                foreach ($changes as $ticket) {
                    $updatedAt = CarbonImmutable::instance($ticket->updated_at ?? now());
                    $key = $ticket->getKey().'@'.$updatedAt->getTimestamp();
                    if (isset($sentAtCursor[$key])) {
                        continue;
                    }

                    if ($updatedAt->getTimestamp() > $cursor->getTimestamp()) {
                        $cursor = $updatedAt;
                        $sentAtCursor = [];
                    }
                    $sentAtCursor[$key] = true;

                    $type = match (true) {
                        $ticket->deleted_at !== null => 'ticket.deleted',
                        $ticket->created_at !== null && $ticket->created_at->getTimestamp() >= $ticket->updated_at?->getTimestamp() => 'ticket.created',
                        default => 'ticket.updated',
                    };

                    $this->emit($type, $this->present($ticket), (string) $cursor->getTimestamp());
                    $lastWrite = microtime(true);
                }

                if (microtime(true) - $lastWrite >= 15) {
                    echo ": ping\n\n";
                    flush();
                    $lastWrite = microtime(true);
                }

                if (connection_aborted() || microtime(true) + $pollSeconds > $deadline) {
                    break;
                }

                sleep($pollSeconds);
            } while (true);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return Builder<Ticket>
     */
    private function baseQuery(bool $withTrashed = false): Builder
    {
        $query = $withTrashed ? Ticket::withTrashed() : Ticket::query();

        return $query
            ->withoutPendingExternal()
            ->whereIn('tickets.unit_id', $this->unitIds() ?: [0]);
    }

    /**
     * @return array<int, int>
     */
    private function unitIds(): array
    {
        $names = (array) config('helpdesk_dashboard.unit_names', ['IT']);

        return Unit::query()->whereIn('name', $names)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return array<int, int>
     */
    private function statusIds(string $value): array
    {
        $parts = array_filter(array_map('trim', explode(',', $value)));

        return TicketStatus::query()
            ->where(function (Builder $query) use ($parts): void {
                $ids = array_filter($parts, 'ctype_digit');
                $names = array_diff($parts, $ids);
                $query->whereIn('id', $ids ?: [0])->orWhereIn('name', $names ?: ['']);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function initialCursor(Request $request): CarbonImmutable
    {
        $raw = (string) ($request->header('Last-Event-ID') ?: $request->query('last_event_id', ''));
        if (ctype_digit($raw) && $raw !== '') {
            return CarbonImmutable::createFromTimestamp((int) $raw, config('app.timezone'));
        }

        $since = $request->query('since');
        if (is_string($since) && $since !== '') {
            try {
                return CarbonImmutable::parse($since);
            } catch (Throwable) {
                // Abaikan nilai yang tidak bisa dibaca; mulai dari sekarang.
            }
        }

        return CarbonImmutable::now();
    }

    private function emit(string $event, array $data, string $id): void
    {
        echo 'id: '.$id."\n";
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        flush();
    }

    /**
     * Hanya data yang aman tampil di layar umum: tanpa deskripsi, lampiran,
     * email, atau nomor telepon pelapor.
     *
     * @return array<string, mixed>
     */
    private function present(Ticket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'number' => HelpdeskTicketNumber::format($ticket),
            'title' => $ticket->title,
            'unit' => $ticket->unit?->name,
            'category' => $ticket->problemCategory?->name,
            'priority' => $ticket->priority ? ['id' => (int) $ticket->priority->id, 'name' => $ticket->priority->name] : null,
            'status' => $ticket->ticketStatus ? ['id' => (int) $ticket->ticketStatus->id, 'name' => $ticket->ticketStatus->name] : null,
            'reporter' => [
                'name' => $ticket->owner?->name,
                'unit' => $ticket->reporterUnit?->name,
                'business_entity' => $ticket->businessEntity?->name,
            ],
            'responsible' => $ticket->responsible?->name,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'approved_at' => $ticket->approved_at?->toIso8601String(),
            'solved_at' => $ticket->solved_at?->toIso8601String(),
            'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
            'is_sla_met' => $ticket->is_sla_met,
            'deleted' => $ticket->deleted_at !== null,
        ];
    }
}
