<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use App\Notifications\CommentNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Comment.
 *
 * @property int $id
 * @property int $tiket_id
 * @property int $user_id
 * @property string $comment
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
                if ($ticket->responsible_id) {
                    $responsibleUser = User::find($ticket->responsible_id);
                    if ($responsibleUser && $responsibleUser->is_active) {
                        $receivers->push($responsibleUser);
                    }
                } else {
                    $unitUsers = User::where('is_active', 1)
                        ->where('id', '!=', $comment->user_id)
                        ->whereHas('roles', function ($query) use ($ticket) {
                            $query->whereIn('name', ['Super Admin', 'Master Admin', 'Admin Unit', 'Staff Unit', 'Staf Unit'])
                                ->when($ticket->unit_id, function ($q) use ($ticket) {
                                    $q->where('unit_id', $ticket->unit_id);
                                });
                        })
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
