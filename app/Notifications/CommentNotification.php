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

class CommentNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;
    use ResolvesHelpdeskNotificationChannels;

    protected $comment;

    protected $ticket;

    /**
     * Buat instance notifikasi baru.
     */
    public function __construct($comment)
    {
        $this->comment = $comment;
        $this->ticket = $comment->ticket;
    }

    /**
     * Tentukan channel notifikasi.
     */
    public function via($notifiable): array
    {
        return $this->withVerifiedMailChannel($notifiable, [WhatsAppChannel::class]);
    }

    /**
     * Format email notifikasi.
     */
    public function toMail($notifiable): MailMessage
    {
        $number = $this->ticket instanceof Ticket
            ? HelpdeskWhatsAppMessage::ticketNumber($this->ticket)
            : '#'.($this->ticket->id ?? '');
        $ticketUrl = url('/admin/tickets/'.($this->ticket->id ?? ''));

        return (new MailMessage)
            ->subject('Komentar baru: '.$number)
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Ada komentar baru pada tiket **'.$number.'**.')
            ->line(html_entity_decode(strip_tags($this->comment->comment)))
            ->action('Buka tiket', $ticketUrl);
    }

    /**
     * Array untuk notifikasi database (opsional).
     */
    public function toArray($notifiable): array
    {
        return [
            'comment_id' => $this->comment->id,
            'ticket_id' => $this->ticket->id,
            'comment' => $this->comment->comment,
        ];
    }

    /**
     * Mengirim notifikasi melalui WhatsApp.
     */
    public function toWhatsapp($notifiable): string
    {
        if (! $this->ticket instanceof Ticket) {
            return '';
        }

        return HelpdeskWhatsAppMessage::comment($this->ticket, (string) $this->comment->comment);
    }
}
