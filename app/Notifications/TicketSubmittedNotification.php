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

class TicketSubmittedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;
    use ResolvesHelpdeskNotificationChannels;

    public function __construct(protected Ticket $ticket) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->withVerifiedMailChannel($notifiable, [WhatsAppChannel::class]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = HelpdeskWhatsAppMessage::ticketNumber($this->ticket);

        return (new MailMessage)
            ->subject('Laporan diterima: '.$number)
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Laporan Anda sudah masuk Helpdesk.')
            ->line('**Nomor:** '.$number)
            ->line('**Judul:** '.$this->ticket->title)
            ->line('Tim akan menindaklanjuti.');
    }

    /**
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
        return HelpdeskWhatsAppMessage::forReporter($this->ticket);
    }
}
