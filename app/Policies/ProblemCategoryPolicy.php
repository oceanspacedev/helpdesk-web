<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProblemCategory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProblemCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:ProblemCategory');
    }

    public function view(User $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('View:ProblemCategory')
            && ($authUser->hasGlobalTicketAccess()
                || $authUser->isAssignedToUnit((int) $problemCategory->unit_id));
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:ProblemCategory')
            && ($authUser->hasGlobalTicketAccess()
                || ($authUser->hasRole('Admin Unit') && $authUser->assignedUnitIds() !== []));
    }

    public function update(User $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('Update:ProblemCategory')
            && $authUser->canAdministerUnit((int) $problemCategory->unit_id);
    }

    public function delete(User $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('Delete:ProblemCategory')
            && $authUser->canAdministerUnit((int) $problemCategory->unit_id);
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:ProblemCategory');
    }

    public function restore(User $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('Restore:ProblemCategory')
            && $authUser->canAdministerUnit((int) $problemCategory->unit_id);
    }

    public function forceDelete(User $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('ForceDelete:ProblemCategory')
            && $authUser->canAdministerUnit((int) $problemCategory->unit_id);
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProblemCategory');
    }

    public function restoreAny(User $authUser): bool
    {
        return $authUser->can('RestoreAny:ProblemCategory');
    }

    public function replicate(User $authUser, ProblemCategory $problemCategory): bool
    {
        return $authUser->can('Replicate:ProblemCategory')
            && $authUser->canAdministerUnit((int) $problemCategory->unit_id);
    }

    public function reorder(User $authUser): bool
    {
        return $authUser->can('Reorder:ProblemCategory');
    }
}
