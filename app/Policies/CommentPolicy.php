<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CommentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Comment');
    }

    public function view(AuthUser $authUser, Comment $comment): bool
    {
        return $authUser->can('View:Comment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Comment');
    }

    public function update(AuthUser $authUser, Comment $comment): bool
    {
        if (! $authUser->can('Update:Comment')) {
            return false;
        }

        return (int) $authUser->id === (int) $comment->user_id
            || $authUser->hasAnyRole(['Super Admin', 'Master Admin', 'Admin Unit', 'Staff Unit', 'Staf Unit']);
    }

    public function delete(AuthUser $authUser, Comment $comment): bool
    {
        if (! $authUser->can('Delete:Comment')) {
            return false;
        }

        return (int) $authUser->id === (int) $comment->user_id
            || $authUser->hasAnyRole(['Super Admin', 'Master Admin', 'Admin Unit', 'Staff Unit', 'Staf Unit']);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Comment');
    }

    public function restore(AuthUser $authUser, Comment $comment): bool
    {
        return $authUser->can('Restore:Comment');
    }

    public function forceDelete(AuthUser $authUser, Comment $comment): bool
    {
        return $authUser->can('ForceDelete:Comment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Comment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Comment');
    }

    public function replicate(AuthUser $authUser, Comment $comment): bool
    {
        return $authUser->can('Replicate:Comment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Comment');
    }
}

