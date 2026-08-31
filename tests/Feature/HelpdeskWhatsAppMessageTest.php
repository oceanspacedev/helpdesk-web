<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Priority;
use App\Models\ProblemCategory;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\NewTicketNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\TicketSubmittedNotification;
use App\Support\HelpdeskWhatsAppMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\TestCase;

class HelpdeskWhatsAppMessageTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;

    private Unit $unit;

    private ProblemCategory $category;

    private Priority $priority;

    private BusinessEntity $businessEntity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
        Notification::fake();

        $this->unit = Unit::query()->create(['name' => 'IT']);
        $this->category = ProblemCategory::query()->create([
            'unit_id' => $this->unit->id,
            'name' => 'Akses Akun',
        ]);
        $this->priority = Priority::query()->create([
            'id' => Priority::MEDIUM,
            'name' => 'Medium',
        ]);
        $this->businessEntity = BusinessEntity::query()->create([
            'name' => 'Complete Selular',
        ]);
    }

    public function test_reporter_and_staff_whatsapp_templates_share_one_frame(): void
    {
        $reporter = $this->user('Pelapor WA', '6281234500401');
        $staff = $this->user('Petugas WA', '6281234500402');
        $ticket = $this->ticket($reporter, 'Printer kasir tidak bisa mencetak');

        $submitted = HelpdeskWhatsAppMessage::forReporter($ticket);
        $received = HelpdeskWhatsAppMessage::forStaff($ticket);

        $number = HelpdeskWhatsAppMessage::ticketNumber($ticket);
        $this->assertSame("HD-".now()->format('Y').'-'.str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT), $number);

        foreach ([$submitted, $received] as $message) {
            $this->assertStringStartsWith("*Helpdesk*\n\n", $message);
            $this->assertStringContainsString('*'.$number.'*', $message);
            $this->assertStringContainsString('Printer kasir tidak bisa mencetak', $message);
            $this->assertStringContainsString('IT · Complete Selular · Medium', $message);
            $this->assertStringNotContainsString('Supported by IT Support', $message);
            $this->assertStringNotContainsString('Login via WA', $message);
            $this->assertDoesNotMatchRegularExpression('/🔔|📝|📌|📅|🔗|📱/', $message);
        }

        $this->assertStringContainsString('Laporan diterima', $submitted);
        $this->assertStringNotContainsString('Nomor follow-up', $submitted);
        $this->assertStringNotContainsString('Koordinasi WA', $submitted);
        $this->assertStringNotContainsString('/admin/tickets/', $submitted);
        $this->assertStringNotContainsString('Pelapor:', $submitted);
        $this->assertStringNotContainsString('wa.me/', $submitted);

        $this->assertStringContainsString('Laporan baru', $received);
        $this->assertStringContainsString('Pelapor: Pelapor WA', $received);
        $this->assertStringContainsString('Koordinasi WA: 6281234500401', $received);
        $this->assertStringContainsString('https://wa.me/6281234500401?text=', $received);
        $this->assertStringContainsString('/admin/tickets/'.$ticket->id, $received);

        $this->assertSame(
            $submitted,
            (new TicketSubmittedNotification($ticket))->toWhatsapp($reporter),
        );
        $this->assertSame(
            $received,
            (new NewTicketNotification($ticket))->toWhatsapp($staff),
        );
    }

    public function test_process_cancel_and_closed_templates_match_the_reporter_frame(): void
    {
        $reporter = $this->user('Pelapor Status', '6281234500405');
        $ticket = $this->ticket($reporter, 'AC ruang server mati');
        $number = HelpdeskWhatsAppMessage::ticketNumber($ticket);

        $messages = [
            'Laporan diproses' => HelpdeskWhatsAppMessage::inProgress($ticket),
            'Laporan dibatalkan' => HelpdeskWhatsAppMessage::cancelled($ticket),
            'Laporan selesai' => HelpdeskWhatsAppMessage::closed($ticket),
        ];

        foreach ($messages as $heading => $message) {
            $this->assertStringStartsWith("*Helpdesk*\n\n".$heading."\n*".$number.'*', $message);
            $this->assertStringNotContainsString('Nomor follow-up', $message);
            $this->assertStringContainsString('AC ruang server mati', $message);
            $this->assertStringContainsString('IT · Complete Selular · Medium', $message);
            $this->assertStringNotContainsString('/admin/tickets/', $message);
            $this->assertStringNotContainsString('wa.me/', $message);
            $this->assertStringNotContainsString('Koordinasi WA', $message);
        }

        $this->assertStringContainsString('Tim sedang menangani laporan ini.', $messages['Laporan diproses']);
        $this->assertStringContainsString('Laporan ini tidak dilanjutkan.', $messages['Laporan dibatalkan']);
        $this->assertStringContainsString('Laporan ini sudah diselesaikan.', $messages['Laporan selesai']);

        $this->assertSame(
            $messages['Laporan diproses'],
            (new TicketStatusChangedNotification($ticket, TicketStatus::IN_PROGRESS))->toWhatsapp($reporter),
        );
        $this->assertSame(
            $messages['Laporan dibatalkan'],
            (new TicketStatusChangedNotification($ticket, TicketStatus::CANCEL))->toWhatsapp($reporter),
        );
        $this->assertSame(
            $messages['Laporan selesai'],
            (new TicketStatusChangedNotification($ticket, TicketStatus::CLOSED))->toWhatsapp($reporter),
        );
    }

    public function test_status_changes_notify_the_reporter(): void
    {
        $reporter = $this->user('Pelapor Transisi', '6281234500406');
        $ticket = $this->ticket($reporter, 'Printer antrean rusak');

        $ticket->update(['ticket_statuses_id' => TicketStatus::IN_PROGRESS]);
        Notification::assertSentTo($reporter, TicketStatusChangedNotification::class);

        Notification::fake();
        $ticket->update(['ticket_statuses_id' => TicketStatus::CANCEL]);
        Notification::assertSentTo(
            $reporter,
            TicketStatusChangedNotification::class,
            fn (TicketStatusChangedNotification $notification): bool => str_contains(
                $notification->toWhatsapp($reporter),
                'Laporan dibatalkan',
            ),
        );
    }

    public function test_creating_a_ticket_notifies_the_reporter_and_not_as_staff(): void
    {
        $reporter = $this->user('Pelapor Form', '6281234500403');
        $ticket = $this->ticket($reporter, 'Laptop tidak nyala');

        Notification::assertSentTo($reporter, TicketSubmittedNotification::class);
        Notification::assertNotSentTo($reporter, NewTicketNotification::class);
        $this->assertSame($reporter->id, $ticket->owner_id);
    }

    private function user(string $name, string $phone): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => null,
            'password' => null,
            'phone' => $phone,
            'is_active' => true,
        ]);
    }

    private function ticket(User $owner, string $title): Ticket
    {
        Auth::setUser($owner);

        return Ticket::query()->create([
            'priority_id' => $this->priority->id,
            'unit_id' => $this->unit->id,
            'owner_id' => $owner->id,
            'problem_category_id' => $this->category->id,
            'title' => $title,
            'description' => $title,
            'ticket_statuses_id' => TicketStatus::OPEN,
            'business_entities_id' => $this->businessEntity->id,
        ]);
    }
}
