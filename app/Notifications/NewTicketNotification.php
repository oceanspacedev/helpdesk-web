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

class NewTicketNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;
    use ResolvesHelpdeskNotificationChannels;

    protected $ticket;

    /**
     * Create a new notification instance.
     *
     * @param  bool  $assigned  Tiket lama yang baru ditugaskan ke penerima, bukan laporan baru.
     */
    public function __construct($ticket, protected bool $assigned = false)
    {
        $this->ticket = $ticket;
        $this->onQueue('notifications');
    }

    /**
     * Notifikasi untuk PIC yang ditunjuk setelah tiket dibuat (ganti PIC
     * manual atau PIC default kategori baru).
     */
    public static function assigned(Ticket $ticket): static
    {
        return new static($ticket, assigned: true);
    }

    public function isAssignment(): bool
    {
        return $this->assigned;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->withVerifiedMailChannel($notifiable, ['database', WhatsAppChannel::class]);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->ticket instanceof Ticket
            ? HelpdeskWhatsAppMessage::ticketNumber($this->ticket)
            : '#'.($this->ticket->id ?? '');
        $ticketUrl = url('/admin/tickets/'.($this->ticket->id ?? ''));
        [$heading, $intro] = $this->assigned
            ? ['Laporan ditugaskan', 'Anda ditunjuk sebagai PIC laporan ini.']
            : ['Laporan baru', 'Ada laporan baru yang perlu ditindaklanjuti.'];

        return (new MailMessage)
            ->subject($heading.': '.$number)
            ->greeting('Halo '.$notifiable->name.',')
            ->line($intro)
            ->line('**Nomor:** '.$number)
            ->line('**Judul:** '.($this->ticket->title ?? ''))
            ->action('Buka tiket', $ticketUrl);
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

    /**
     * Send the WhatsApp message notification using custom API.
     */
    public function toWhatsapp($notifiable): string
    {
        if (! $this->ticket instanceof Ticket) {
            return '';
        }

        return $this->assigned
            ? HelpdeskWhatsAppMessage::forAssignedStaff($this->ticket)
            : HelpdeskWhatsAppMessage::forStaff($this->ticket);
    }
}
