<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\UnitSla;
use Illuminate\Auth\Access\HandlesAuthorization;

class UnitSlaPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:UnitSla');
    }

    public function view(AuthUser $authUser, UnitSla $unitSla): bool
    {
        return $authUser->can('View:UnitSla');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:UnitSla');
    }

    public function update(AuthUser $authUser, UnitSla $unitSla): bool
    {
        return $authUser->can('Update:UnitSla');
    }

    public function delete(AuthUser $authUser, UnitSla $unitSla): bool
    {
        return $authUser->can('Delete:UnitSla');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:UnitSla');
    }

    public function restore(AuthUser $authUser, UnitSla $unitSla): bool
    {
        return $authUser->can('Restore:UnitSla');
    }

    public function forceDelete(AuthUser $authUser, UnitSla $unitSla): bool
    {
        return $authUser->can('ForceDelete:UnitSla');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:UnitSla');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:UnitSla');
    }

    public function replicate(AuthUser $authUser, UnitSla $unitSla): bool
    {
        return $authUser->can('Replicate:UnitSla');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:UnitSla');
    }

}