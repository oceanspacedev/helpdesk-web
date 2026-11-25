<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Master Integrasi menyimpan token dan rahasia webhook sistem lain, jadi
 * hanya admin global (Super Admin / Master Admin) yang boleh mengelolanya,
 * selain harus memiliki permission Shield-nya.
 */
class IntegrationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $this->isGlobalAdmin($authUser) && $authUser->can('ViewAny:Integration');
    }

    public function view(User $authUser, Integration $integration): bool
    {
        return $this->isGlobalAdmin($authUser) && $authUser->can('View:Integration');
    }

    public function create(User $authUser): bool
    {
        return $this->isGlobalAdmin($authUser) && $authUser->can('Create:Integration');
    }

    public function update(User $authUser, Integration $integration): bool
    {
        return $this->isGlobalAdmin($authUser) && $authUser->can('Update:Integration');
    }

    /**
     * Integrasi yang masih dipakai unit atau tiket tidak bisa dihapus;
     * nonaktifkan saja.
     */
    public function delete(User $authUser, Integration $integration): bool
    {
        return $this->isGlobalAdmin($authUser)
            && $authUser->can('Delete:Integration')
            && ! $integration->isInUse();
    }

    public function deleteAny(User $authUser): bool
    {
        return false;
    }

    public function forceDelete(User $authUser, Integration $integration): bool
    {
        return false;
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return false;
    }

    public function restore(User $authUser, Integration $integration): bool
    {
        return false;
    }

    public function restoreAny(User $authUser): bool
    {
        return false;
    }

    public function replicate(User $authUser, Integration $integration): bool
    {
        return false;
    }

    public function reorder(User $authUser): bool
    {
        return false;
    }

    private function isGlobalAdmin(User $authUser): bool
    {
        return (bool) $authUser->is_active && $authUser->hasGlobalTicketAccess();
    }
}
