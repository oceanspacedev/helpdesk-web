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

/**
 * Komentar baru dari sistem eksternal pada tiket tipis, dikirim ke pelapor.
 */
class ExternalTicketCommentNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;
    use ResolvesHelpdeskNotificationChannels;

    /**
     * @param  list<array<string, mixed>>  $comments
     */
    public function __construct(
        protected Ticket $ticket,
        protected array $comments,
    ) {
        $this->onQueue('notifications');
    }

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
            ->subject('Komentar baru: '.$number)
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Ada komentar baru pada tiket **'.$number.'**.')
            ->line($this->latestText())
            ->action('Buka tiket', url('/admin/tickets/'.$this->ticket->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'external_comment_ids' => array_values(array_filter(array_map(
                fn (array $comment): ?string => is_scalar($comment['id'] ?? null) ? (string) $comment['id'] : null,
                $this->comments,
            ))),
            'comment' => $this->latestText(),
        ];
    }

    public function toWhatsapp($notifiable): string
    {
        return HelpdeskWhatsAppMessage::comment($this->ticket, $this->latestText());
    }

    private function latestText(): string
    {
        $latest = $this->comments[array_key_last($this->comments)] ?? [];
        $author = trim((string) (is_scalar($latest['author'] ?? null) ? $latest['author'] : ''));
        $body = trim((string) (is_scalar($latest['body'] ?? null) ? $latest['body'] : ''));
        $text = $author !== '' ? $author.': '.$body : $body;

        $more = count($this->comments) - 1;

        return $more > 0 ? $text.' (+'.$more.' komentar lain)' : $text;
    }
}
