<?php

namespace App\Notifications;

use App\Services\WhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return ['mail', \App\Channels\WhatsAppChannel::class];
    }

    /**
     * Format email notifikasi.
     */
    public function toMail($notifiable): MailMessage
    {
        $ticketUrl = url('/admin/tickets/'.$this->ticket->id);
        $phoneLoginUrl = route('phone-login');

        return (new MailMessage)
            ->subject('💬 Komentar Baru: Tiket #'.$this->ticket->id.' - '.$this->ticket->title)
            ->greeting('Halo '.$notifiable->name.'!')
            ->line('Ada komentar/tanggapan baru pada tiket **#'.$this->ticket->id.' ('.$this->ticket->title.')**.')
            ->line('**Isi Komentar:**')
            ->line(html_entity_decode(strip_tags($this->comment->comment)))
            ->action('Lihat & Tanggapi Tiket', $ticketUrl)
            ->line('📱 **Login Cepat via WhatsApp:** Anda dapat masuk langsung ke sistem menggunakan nomor WhatsApp di: '.$phoneLoginUrl)
            ->salutation('Salam, '.config('app.name', 'Helpdesk Team'));
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
    public function toWhatsapp($notifiable)
    {
        $appName = config('app.name', 'Helpdesk');
        $ticketUrl = url('/admin/tickets/'.$this->ticket->id);
        $phoneLoginUrl = route('phone-login');

        $message = "🔔 *Notifikasi Komentar Baru* 🔔\n\n";
        $message .= "Halo *".$notifiable->name."*, ada komentar baru pada tiket Anda:\n\n";
        $message .= "📝 *Tiket:* #".$this->ticket->id." - ".$this->ticket->title."\n";
        $message .= "💬 *Komentar:* ".html_entity_decode(strip_tags($this->comment->comment))."\n";
        $message .= "📅 *Waktu:* ".now()->format('d M Y H:i')."\n\n";
        $message .= "🔗 *Buka & Balas Tiket:* ".$ticketUrl."\n";
        $message .= "📱 *Login via WA:* ".$phoneLoginUrl."\n\n";
        $message .= "— ".$appName;

        return $message;
    }
}
