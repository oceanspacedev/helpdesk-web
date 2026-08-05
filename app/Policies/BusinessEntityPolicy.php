<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\BusinessEntity;
use Illuminate\Auth\Access\HandlesAuthorization;

class BusinessEntityPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:BusinessEntity');
    }

    public function view(AuthUser $authUser, BusinessEntity $businessEntity): bool
    {
        return $authUser->can('View:BusinessEntity');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:BusinessEntity');
    }

    public function update(AuthUser $authUser, BusinessEntity $businessEntity): bool
    {
        return $authUser->can('Update:BusinessEntity');
    }

    public function delete(AuthUser $authUser, BusinessEntity $businessEntity): bool
    {
        return $authUser->can('Delete:BusinessEntity');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:BusinessEntity');
    }

    public function restore(AuthUser $authUser, BusinessEntity $businessEntity): bool
    {
        return $authUser->can('Restore:BusinessEntity');
    }

    public function forceDelete(AuthUser $authUser, BusinessEntity $businessEntity): bool
    {
        return $authUser->can('ForceDelete:BusinessEntity');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:BusinessEntity');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:BusinessEntity');
    }

    public function replicate(AuthUser $authUser, BusinessEntity $businessEntity): bool
    {
        return $authUser->can('Replicate:BusinessEntity');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:BusinessEntity');
    }

}