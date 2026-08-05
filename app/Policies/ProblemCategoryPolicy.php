<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ProblemCategory;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProblemCategoryPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProblemCategory');
    }

    public function view(AuthUser $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('View:ProblemCategory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProblemCategory');
    }

    public function update(AuthUser $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('Update:ProblemCategory');
    }

    public function delete(AuthUser $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('Delete:ProblemCategory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProblemCategory');
    }

    public function restore(AuthUser $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('Restore:ProblemCategory');
    }

    public function forceDelete(AuthUser $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('ForceDelete:ProblemCategory');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProblemCategory');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProblemCategory');
    }

    public function replicate(AuthUser $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('Replicate:ProblemCategory');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProblemCategory');
    }

}