<?php

namespace App\Notifications;

use App\Services\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewTicketNotification extends Notification
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
        return ['mail', 'database', \App\Channels\WhatsAppChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $ticketUrl = url('/admin/tickets/'.$this->ticket->id);
        $phoneLoginUrl = route('phone-login');

        return (new MailMessage)
            ->subject('🔔 Tiket Baru: #'.$this->ticket->id.' - '.$this->ticket->title)
            ->greeting('Halo '.$notifiable->name.'!')
            ->line('Terdapat tiket baru yang perlu Anda periksa dan tindak lanjuti.')
            ->line('**Detail Tiket:**')
            ->line('• **ID Tiket:** #'.$this->ticket->id)
            ->line('• **Subjek:** '.$this->ticket->title)
            ->line('• **Tanggal Dibuat:** '.$this->ticket->created_at->format('d M Y H:i'))
            ->action('Buka & Lihat Tiket', $ticketUrl)
            ->line('📱 **Login Cepat via WhatsApp:** Anda juga dapat masuk langsung ke sistem menggunakan nomor WhatsApp di: '.$phoneLoginUrl)
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

    /**
     * Send the WhatsApp message notification using custom API.
     */
    public function toWhatsapp($notifiable)
    {
        $ticketUrl = url('/admin/tickets/'.$this->ticket->id);
        $phoneLoginUrl = route('phone-login');

        $message = "🔔 *Notifikasi Tiket Baru* 🔔\n\n";
        $message .= "Halo *".$notifiable->name."*, terdapat tiket baru yang perlu Anda periksa:\n\n";
        $message .= "📝 *ID Tiket:* #".$this->ticket->id."\n";
        $message .= "📌 *Subjek:* ".$this->ticket->title."\n";
        $message .= "📅 *Tanggal Dibuat:* ".$this->ticket->created_at->format('d M Y H:i')."\n\n";
        $message .= "🔗 *Buka Tiket:* ".$ticketUrl."\n";
        $message .= "📱 *Login via WA:* ".$phoneLoginUrl."\n\n";
        $message .= "Terima kasih, mohon segera ditindaklanjuti.\n\n";
        $message .= "— Supported by IT Support";

        return $message;
    }
}
