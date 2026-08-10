<?php

namespace App\Notifications;

use App\Services\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClosedTicketNotification extends Notification
{
    use Queueable;

    protected $ticket;

    /**
     * Create a new notification instance.
     */
    public function __construct($ticket)
    {
        $this->ticket = $ticket;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', \App\Channels\WhatsAppChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $ticketUrl = url('/admin/tickets/'.$this->ticket->id);
        $phoneLoginUrl = route('phone-login');

        return (new MailMessage)
            ->subject('✅ Tiket Selesai: #'.$this->ticket->id.' - '.$this->ticket->title)
            ->greeting('Halo '.$notifiable->name.'!')
            ->line('Kami ingin memberitahukan bahwa tiket yang Anda ajukan **#'.$this->ticket->id.' ('.$this->ticket->title.')** telah selesai ditangani.')
            ->action('Lihat Detail Tiket', $ticketUrl)
            ->line('📱 **Login Cepat via WhatsApp:** Anda dapat masuk langsung ke sistem menggunakan nomor WhatsApp di: '.$phoneLoginUrl)
            ->salutation('Supported by IT Support');
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

    public function toWhatsapp($notifiable)
    {
        $ticketUrl = url('/admin/tickets/'.$this->ticket->id);
        $phoneLoginUrl = route('phone-login');

        $message = "✅ *Notifikasi Tiket Selesai* ✅\n\n";
        $message .= "Halo *".$notifiable->name."*, tiket Anda telah selesai ditangani:\n\n";
        $message .= "📝 *ID Tiket:* #".$this->ticket->id."\n";
        $message .= "📌 *Subjek:* ".$this->ticket->title."\n";
        $message .= "📅 *Tanggal Ditutup:* ".now()->format('d M Y H:i')."\n\n";
        $message .= "🔗 *Lihat Detail Tiket:* ".$ticketUrl."\n";
        $message .= "📱 *Login via WA:* ".$phoneLoginUrl."\n\n";
        $message .= "Terima kasih telah menggunakan layanan Helpdesk kami.\n\n";
        $message .= "— Supported by IT Support";

        return $message;
    }
}
