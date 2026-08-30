<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use App\Notifications\CommentNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Class Comment.
 *
 * @property int $id
 * @property int $tiket_id
 * @property int $user_id
 * @property string $comment
 * @property null|string $attachments
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|string $deleted_at
 * @property User $user
 * @property Ticket $ticket
 */
class Comment extends Model
{
    use SoftDeletes;

    protected $table = 'comments';

    protected $casts = [
        'tiket_id' => 'int',
        'user_id' => 'int',
    ];

    protected $fillable = [
        'tiket_id',
        'user_id',
        'comment',
        'attachments',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'tiket_id');
    }

    public function storedAttachmentPath(): ?string
    {
        $path = ltrim((string) $this->attachments, '/');

        return $path === '' ? null : $path;
    }

    public function downloadStoredAttachment(): ?StreamedResponse
    {
        $path = $this->storedAttachmentPath();
        $disk = Storage::disk('public');
        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        return $disk->download($path);
    }

    protected static function booted()
    {
        static::created(function ($comment) {
            $ticket = $comment->ticket;
            if (! $ticket) {
                return;
            }

            $receivers = collect();

            // Jika yang berkomentar adalah pelapor (owner)
            if ((int) $comment->user_id === (int) $ticket->owner_id) {
                $responsible = $ticket->eligibleResponsible();

                if ($responsible) {
                    $receivers->push($responsible);
                } else {
                    $unitUsers = User::query()
                        ->ticketProcessorsForUnit((int) $ticket->unit_id, includeGlobal: true)
                        ->where('id', '!=', $comment->user_id)
                        ->get();
                    $receivers = $receivers->merge($unitUsers);
                }
            } else {
                // Jika yang berkomentar adalah staf/teknisi/admin, kirim ke pelapor (owner)
                if ($ticket->owner && $ticket->owner->is_active) {
                    $receivers->push($ticket->owner);
                }
            }

            // Kirim notifikasi ke semua penerima valid kecuali pembuat komentar
            $receivers->unique('id')
                ->reject(fn ($u) => (int) $u->id === (int) $comment->user_id)
                ->each(function ($user) use ($comment) {
                    $user->notify(new CommentNotification($comment));
                });
        });
    }
}
