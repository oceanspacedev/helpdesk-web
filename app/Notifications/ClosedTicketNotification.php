<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use App\Notifications\Concerns\ResolvesHelpdeskNotificationChannels;
use App\Support\HelpdeskWhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClosedTicketNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;
    use ResolvesHelpdeskNotificationChannels;

    protected $ticket;

    /**
     * Create a new notification instance.
     */
    public function __construct($ticket)
    {
        $this->ticket = $ticket;
        $this->onQueue('notifications');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->withVerifiedMailChannel($notifiable, [WhatsAppChannel::class]);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->ticket instanceof Ticket
            ? HelpdeskWhatsAppMessage::ticketNumber($this->ticket)
            : '#'.($this->ticket->id ?? '');

        return (new MailMessage)
            ->subject('Laporan selesai: '.$number)
            ->greeting('Halo '.$notifiable->name.',')
            ->line('**Nomor:** '.$number)
            ->line('**Judul:** '.($this->ticket->title ?? ''))
            ->line('Laporan ini sudah diselesaikan.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_statuses_id' => $this->ticket->ticket_statuses_id,
        ];
    }

    public function toWhatsapp($notifiable): string
    {
        if (! $this->ticket instanceof Ticket) {
            return '';
        }

        return HelpdeskWhatsAppMessage::closed($this->ticket);
    }
}
