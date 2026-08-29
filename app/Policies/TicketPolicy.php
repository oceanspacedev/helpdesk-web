<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Ticket');
    }

    public function view(User $authUser, Ticket $ticket): bool
    {
        if (! $authUser->can('View:Ticket')) {
            return false;
        }

        return $authUser->hasGlobalTicketAccess()
            || (int) $ticket->owner_id === (int) $authUser->getKey()
            || $authUser->canProcessTicketsForUnit((int) $ticket->unit_id);
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Ticket');
    }

    public function update(User $authUser, Ticket $ticket): bool
    {
        return ! $ticket->trashed()
            && $authUser->can('Update:Ticket')
            && ($authUser->hasGlobalTicketAccess()
                || ((int) $ticket->owner_id === (int) $authUser->getKey()
                    && (int) $ticket->ticket_statuses_id === TicketStatus::OPEN));
    }

    public function process(User $authUser, Ticket $ticket): bool
    {
        if ($ticket->trashed()
            || ! $authUser->can('Update:Ticket')
            || ! $authUser->canProcessTicketsForUnit((int) $ticket->unit_id)) {
            return false;
        }

        if ($authUser->hasGlobalTicketAccess()) {
            return in_array(
                (int) $ticket->ticket_statuses_id,
                [TicketStatus::OPEN, TicketStatus::IN_PROGRESS],
                true,
            );
        }

        $responsible = $ticket->eligibleResponsible();

        return match ((int) $ticket->ticket_statuses_id) {
            TicketStatus::OPEN => $responsible === null
                || (int) $responsible->getKey() === (int) $authUser->getKey(),
            TicketStatus::IN_PROGRESS => $responsible === null
                || (int) $responsible->getKey() === (int) $authUser->getKey(),
            default => false,
        };
    }

    public function delete(User $authUser, Ticket $ticket): bool
    {
        return $authUser->can('Delete:Ticket')
            && $authUser->canProcessTicketsForUnit((int) $ticket->unit_id);
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:Ticket');
    }

    public function restore(User $authUser, Ticket $ticket): bool
    {
        return $authUser->can('Restore:Ticket')
            && $authUser->canProcessTicketsForUnit((int) $ticket->unit_id);
    }

    public function forceDelete(User $authUser, Ticket $ticket): bool
    {
        return $authUser->can('ForceDelete:Ticket')
            && $authUser->canProcessTicketsForUnit((int) $ticket->unit_id);
    }

    public function forceDeleteAny(User $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Ticket');
    }

    public function restoreAny(User $authUser): bool
    {
        return $authUser->can('RestoreAny:Ticket');
    }

    public function replicate(User $authUser, Ticket $ticket): bool
    {
        return $authUser->can('Replicate:Ticket')
            && $authUser->canProcessTicketsForUnit((int) $ticket->unit_id);
    }

    public function reorder(User $authUser): bool
    {
        return $authUser->can('Reorder:Ticket');
    }
}
