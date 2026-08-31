<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Notifications\Concerns\ResolvesHelpdeskNotificationChannels;
use App\Support\HelpdeskWhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketStatusChangedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;
    use ResolvesHelpdeskNotificationChannels;

    public function __construct(
        protected $ticket,
        protected int $status,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->withVerifiedMailChannel($notifiable, [WhatsAppChannel::class]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->ticket instanceof Ticket
            ? HelpdeskWhatsAppMessage::ticketNumber($this->ticket)
            : '#'.($this->ticket->id ?? '');
        [$heading, $footer] = $this->copy();

        return (new MailMessage)
            ->subject($heading.': '.$number)
            ->greeting('Halo '.$notifiable->name.',')
            ->line('**Nomor:** '.$number)
            ->line('**Judul:** '.($this->ticket->title ?? ''))
            ->line($footer);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id ?? null,
            'ticket_statuses_id' => $this->status,
        ];
    }

    public function toWhatsapp($notifiable): string
    {
        if (! $this->ticket instanceof Ticket) {
            return '';
        }

        return match ($this->status) {
            TicketStatus::IN_PROGRESS => HelpdeskWhatsAppMessage::inProgress($this->ticket),
            TicketStatus::CANCEL => HelpdeskWhatsAppMessage::cancelled($this->ticket),
            default => HelpdeskWhatsAppMessage::closed($this->ticket),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function copy(): array
    {
        return match ($this->status) {
            TicketStatus::IN_PROGRESS => ['Laporan diproses', 'Tim sedang menangani laporan ini.'],
            TicketStatus::CANCEL => ['Laporan dibatalkan', 'Laporan ini tidak dilanjutkan.'],
            default => ['Laporan selesai', 'Laporan ini sudah diselesaikan.'],
        };
    }
}
