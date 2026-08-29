<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CommentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Comment');
    }

    public function view(User $authUser, Comment $comment): bool
    {
        if ($comment->trashed() || ! $authUser->can('View:Comment')) {
            return false;
        }

        $ticket = $comment->ticket;

        return $ticket !== null
            && ((int) $ticket->owner_id === (int) $authUser->getKey()
                || $authUser->canProcessTicketsForUnit((int) $ticket->unit_id));
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Comment');
    }

    public function update(User $authUser, Comment $comment): bool
    {
        if ($comment->trashed() || ! $authUser->can('Update:Comment')) {
            return false;
        }

        return ! $comment->ticket?->trashed()
            && $this->view($authUser, $comment)
            && ((int) $authUser->id === (int) $comment->user_id
                || $this->canModerateTicket($authUser, $comment));
    }

    public function delete(User $authUser, Comment $comment): bool
    {
        if ($comment->trashed() || ! $authUser->can('Delete:Comment')) {
            return false;
        }

        return ! $comment->ticket?->trashed()
            && $this->view($authUser, $comment)
            && ((int) $authUser->id === (int) $comment->user_id
                || $this->canModerateTicket($authUser, $comment));
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:Comment');
    }

    public function restore(User $authUser, Comment $comment): bool
    {
        return $authUser->can('Restore:Comment');
    }

    public function forceDelete(User $authUser, Comment $comment): bool
    {
        return $authUser->can('ForceDelete:Comment');
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Comment');
    }

    public function restoreAny(User $authUser): bool
    {
        return $authUser->can('RestoreAny:Comment');
    }

    public function replicate(User $authUser, Comment $comment): bool
    {
        return $authUser->can('Replicate:Comment');
    }

    public function reorder(User $authUser): bool
    {
        return $authUser->can('Reorder:Comment');
    }

    private function canModerateTicket(User $authUser, Comment $comment): bool
    {
        $ticket = $comment->ticket;

        return ! $comment->trashed()
            && $ticket !== null
            && $authUser->canAdministerUnit((int) $ticket->unit_id);
    }
}
