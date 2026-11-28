<?php

namespace Tests\Feature;

use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class DashboardTicketApiTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private const TOKEN = 'tv-token-rahasia';

    private Unit $it;

    private Unit $busdev;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        if (! Schema::hasColumn('tickets', 'sla_due_at')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->timestamp('sla_due_at')->nullable();
                $table->boolean('is_sla_met')->nullable();
            });
        }
        config([
            'helpdesk_dashboard.tokens' => [self::TOKEN],
            'helpdesk_dashboard.unit_names' => ['IT'],
            'helpdesk_dashboard.stream_lifetime_seconds' => 0,
        ]);

        // Status Open/In Progress/Cancel/Closed sudah dibuat createTestSchema().
        Priority::query()->create(['name' => 'High']);

        $this->busdev = Unit::query()->create(['name' => 'BUSDEV']);
        $this->it = Unit::query()->create(['name' => 'IT']);
        ProblemCategory::query()->create(['unit_id' => $this->it->id, 'name' => 'Jaringan']);
        ProblemCategory::query()->create(['unit_id' => $this->busdev->id, 'name' => 'Memo Internal']);

        $this->reporter = User::query()->create([
            'name' => 'Budi',
            'email' => 'budi@example.test',
            'phone' => '0811111111',
        ]);

        Ticket::$isSeeding = true;
    }

    protected function tearDown(): void
    {
        Ticket::$isSeeding = false;
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rejects_missing_or_wrong_token(): void
    {
        $this->getJson('/api/dashboard/tickets')->assertStatus(401);
        $this->getJson('/api/dashboard/tickets', ['Authorization' => 'Bearer salah'])->assertStatus(401);

        config(['helpdesk_dashboard.tokens' => []]);
        $this->getJson('/api/dashboard/tickets', ['Authorization' => 'Bearer '.self::TOKEN])->assertStatus(401);
    }

    public function test_lists_only_it_tickets_without_sensitive_fields(): void
    {
        $itTicket = $this->ticket($this->it, 'Wifi lantai 2 mati');
        $this->ticket($this->busdev, 'Revisi SOP');

        $response = $this->getJson('/api/dashboard/tickets', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $itTicket->id)
            ->assertJsonPath('data.0.title', 'Wifi lantai 2 mati')
            ->assertJsonPath('data.0.unit', 'IT')
            ->assertJsonPath('data.0.category', 'Jaringan')
            ->assertJsonPath('data.0.status.name', 'Open')
            ->assertJsonPath('data.0.reporter.name', 'Budi')
            ->assertJsonPath('meta.total', 1);

        $json = $response->getContent();
        $this->assertStringNotContainsString('budi@example.test', $json);
        $this->assertStringNotContainsString('0811111111', $json);
        $this->assertStringNotContainsString('description', $json);
    }

    public function test_token_can_come_from_query_string(): void
    {
        $this->ticket($this->it, 'Printer macet');

        $this->getJson('/api/dashboard/tickets?token='.self::TOKEN)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_filters_by_status_name_or_id(): void
    {
        $this->ticket($this->it, 'Baru');
        $this->ticket($this->it, 'Dikerjakan', TicketStatus::IN_PROGRESS);

        $this->getJson('/api/dashboard/tickets?status=In Progress', $this->auth())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Dikerjakan');
        $this->getJson('/api/dashboard/tickets?status=1,2', $this->auth())
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/dashboard/tickets?status=Tidak Ada', $this->auth())
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_show_hides_tickets_from_other_units(): void
    {
        $it = $this->ticket($this->it, 'Laptop rusak');
        $busdev = $this->ticket($this->busdev, 'Memo');

        $this->getJson('/api/dashboard/tickets/'.$it->id, $this->auth())->assertOk()->assertJsonPath('data.title', 'Laptop rusak');
        $this->getJson('/api/dashboard/tickets/'.$busdev->id, $this->auth())->assertNotFound();
    }

    public function test_summary_counts_it_tickets_by_status(): void
    {
        $this->ticket($this->it, 'A');
        $this->ticket($this->it, 'B');
        $this->ticket($this->it, 'C', TicketStatus::CLOSED);
        $this->ticket($this->busdev, 'D');

        $response = $this->getJson('/api/dashboard/tickets/summary', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.created_today', 3);

        $byStatus = collect($response->json('data.by_status'))->pluck('total', 'name');
        $this->assertSame(2, $byStatus['Open']);
        $this->assertSame(1, $byStatus['Closed']);
    }

    public function test_api_is_read_only(): void
    {
        $ticket = $this->ticket($this->it, 'X');

        $this->postJson('/api/dashboard/tickets', [], $this->auth())->assertStatus(405);
        $this->putJson('/api/dashboard/tickets/'.$ticket->id, [], $this->auth())->assertStatus(405);
        $this->deleteJson('/api/dashboard/tickets/'.$ticket->id, [], $this->auth())->assertStatus(405);
    }

    public function test_stream_sends_new_updated_and_deleted_it_tickets_since_cursor(): void
    {
        Carbon::setTestNow('2026-11-28 08:00:00');
        $old = $this->ticket($this->it, 'Lama');

        Carbon::setTestNow('2026-11-28 09:00:00');
        $cursor = now()->getTimestamp();

        Carbon::setTestNow('2026-11-28 09:00:05');
        $new = $this->ticket($this->it, 'Internet kantor down');
        $this->ticket($this->busdev, 'Bukan IT');

        Carbon::setTestNow('2026-11-28 09:00:10');
        $old->update(['ticket_statuses_id' => TicketStatus::IN_PROGRESS]);

        Carbon::setTestNow('2026-11-28 09:00:20');
        $gone = $this->ticket($this->it, 'Salah input');
        Carbon::setTestNow('2026-11-28 09:00:30');
        $gone->delete();

        $response = $this->get('/api/dashboard/tickets/stream', $this->auth() + ['Last-Event-ID' => (string) $cursor]);
        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $events = $this->parseEvents($response->streamedContent());

        $this->assertSame(['ready', 'ticket.created', 'ticket.updated', 'ticket.deleted'], array_column($events, 'event'));
        $this->assertSame($new->id, $events[1]['data']['id']);
        $this->assertSame($old->id, $events[2]['data']['id']);
        $this->assertSame('In Progress', $events[2]['data']['status']['name']);
        $this->assertSame($gone->id, $events[3]['data']['id']);
        $this->assertTrue($events[3]['data']['deleted']);
        $this->assertSame((string) Carbon::parse('2026-11-28 09:00:30')->getTimestamp(), $events[3]['id']);
    }

    public function test_stream_without_cursor_starts_from_now(): void
    {
        Carbon::setTestNow('2026-11-28 08:00:00');
        $this->ticket($this->it, 'Sudah lama');
        Carbon::setTestNow('2026-11-28 09:00:00');

        $events = $this->parseEvents($this->get('/api/dashboard/tickets/stream?token='.self::TOKEN)->streamedContent());

        $this->assertSame(['ready'], array_column($events, 'event'));
    }

    private function ticket(Unit $unit, string $title, int $status = TicketStatus::OPEN): Ticket
    {
        return Ticket::query()->create([
            'unit_id' => $unit->id,
            'owner_id' => $this->reporter->id,
            'priority_id' => 1,
            'problem_category_id' => ProblemCategory::query()->where('unit_id', $unit->id)->value('id'),
            'title' => $title,
            'description' => 'Deskripsi rahasia',
            'ticket_statuses_id' => $status,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    /**
     * @return array<int, array{event: string, id: string, data: array<string, mixed>}>
     */
    private function parseEvents(string $body): array
    {
        $events = [];
        foreach (preg_split('/\n\n/', trim($body)) as $block) {
            $event = ['event' => '', 'id' => '', 'data' => []];
            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $event['event'] = substr($line, 7);
                } elseif (str_starts_with($line, 'id: ')) {
                    $event['id'] = substr($line, 4);
                } elseif (str_starts_with($line, 'data: ')) {
                    $event['data'] = json_decode(substr($line, 6), true);
                }
            }
            if ($event['event'] !== '') {
                $events[] = $event;
            }
        }

        return $events;
    }
}
