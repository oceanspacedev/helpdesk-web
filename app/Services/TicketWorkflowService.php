<?php

namespace App\Services;

use App\Enums\TicketWorkflowAction;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Support\HelpdeskTicketNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TicketWorkflowService
{
    /**
     * @return array{
     *     ok: bool,
     *     code: string,
     *     action: string,
     *     ticket: Ticket|null,
     *     ticket_number: string,
     *     previous_status: int|null,
     *     current_status: int|null
     * }
     */
    public function transition(
        string $ticketNumber,
        User $actor,
        TicketWorkflowAction $action,
    ): array {
        $reference = HelpdeskTicketNumber::parse($ticketNumber);
        if ($reference === null) {
            return $this->result(false, 'invalid_ticket_number', $action, null, $ticketNumber);
        }

        return DB::transaction(function () use ($reference, $actor, $action): array {
            $ticket = Ticket::query()
                ->withTrashed()
                ->lockForUpdate()
                ->find($reference['id']);

            if (! $ticket
                || $ticket->trashed()
                || ! hash_equals($reference['canonical'], HelpdeskTicketNumber::format($ticket))) {
                return $this->result(
                    false,
                    'not_available',
                    $action,
                    null,
                    $reference['canonical'],
                );
            }

            $previousStatus = (int) $ticket->ticket_statuses_id;
            if (! $actor->isActiveTicketProcessorForUnit((int) $ticket->unit_id)) {
                return $this->result(
                    false,
                    'not_allowed',
                    $action,
                    null,
                    $reference['canonical'],
                    $previousStatus,
                    $previousStatus,
                );
            }

            if ($this->alreadyApplied($ticket, $actor, $action)) {
                return $this->result(
                    true,
                    'already_applied',
                    $action,
                    $ticket,
                    $reference['canonical'],
                    $previousStatus,
                    $previousStatus,
                );
            }

            if (! Gate::forUser($actor)->allows('process', $ticket)
                || ! $this->transitionIsAllowed($ticket, $actor, $action)) {
                return $this->result(
                    false,
                    'not_allowed',
                    $action,
                    null,
                    $reference['canonical'],
                    $previousStatus,
                    $previousStatus,
                );
            }

            $this->runAs($actor, function () use ($ticket, $actor, $action): void {
                $ticket->responsible_id = (int) $actor->getKey();
                $ticket->ticket_statuses_id = $action->targetStatus();
                $ticket->save();
            });
            $ticket->refresh();

            if ((int) $ticket->responsible_id !== (int) $actor->getKey()
                || (int) $ticket->ticket_statuses_id !== $action->targetStatus()) {
                throw new \RuntimeException('Ticket workflow invariant was not persisted.');
            }

            return $this->result(
                true,
                'transitioned',
                $action,
                $ticket,
                $reference['canonical'],
                $previousStatus,
                (int) $ticket->ticket_statuses_id,
            );
        }, 3);
    }

    private function alreadyApplied(
        Ticket $ticket,
        User $actor,
        TicketWorkflowAction $action,
    ): bool {
        return match ($action) {
            TicketWorkflowAction::PROCESS => (int) $ticket->ticket_statuses_id === TicketStatus::IN_PROGRESS
                && (int) $ticket->responsible_id === (int) $actor->getKey(),
            TicketWorkflowAction::DONE => (int) $ticket->ticket_statuses_id === TicketStatus::CLOSED
                && (int) $ticket->responsible_id === (int) $actor->getKey(),
            TicketWorkflowAction::CANCEL => (int) $ticket->ticket_statuses_id === TicketStatus::CANCEL
                && (int) $ticket->responsible_id === (int) $actor->getKey(),
        };
    }

    private function transitionIsAllowed(
        Ticket $ticket,
        User $actor,
        TicketWorkflowAction $action,
    ): bool {
        $actorId = (int) $actor->getKey();
        $isGlobalProcessor = $actor->hasGlobalTicketAccess();
        $responsible = $ticket->eligibleResponsible();
        $isAvailableToActor = $responsible === null
            || (int) $responsible->getKey() === $actorId
            || $isGlobalProcessor;

        return match ($action) {
            TicketWorkflowAction::PROCESS => ((int) $ticket->ticket_statuses_id === TicketStatus::OPEN
                && $isAvailableToActor)
                || ((int) $ticket->ticket_statuses_id === TicketStatus::IN_PROGRESS
                    && $responsible === null),
            TicketWorkflowAction::DONE => (int) $ticket->ticket_statuses_id === TicketStatus::IN_PROGRESS
                && ((int) $responsible?->getKey() === $actorId || $isGlobalProcessor),
            TicketWorkflowAction::CANCEL => ((int) $ticket->ticket_statuses_id === TicketStatus::OPEN
                && $isAvailableToActor)
                || ((int) $ticket->ticket_statuses_id === TicketStatus::IN_PROGRESS
                    && ((int) $responsible?->getKey() === $actorId || $isGlobalProcessor)),
        };
    }

    private function runAs(User $actor, callable $callback): mixed
    {
        $guard = Auth::guard();
        $previousUser = $guard->user();
        $guard->setUser($actor);

        try {
            return $callback();
        } finally {
            if ($previousUser) {
                $guard->setUser($previousUser);
            } else {
                $guard->forgetUser();
            }
        }
    }

    /**
     * @return array{
     *     ok: bool,
     *     code: string,
     *     action: string,
     *     ticket: Ticket|null,
     *     ticket_number: string,
     *     previous_status: int|null,
     *     current_status: int|null
     * }
     */
    private function result(
        bool $ok,
        string $code,
        TicketWorkflowAction $action,
        ?Ticket $ticket,
        string $ticketNumber,
        ?int $previousStatus = null,
        ?int $currentStatus = null,
    ): array {
        return [
            'ok' => $ok,
            'code' => $code,
            'action' => $action->value,
            'ticket' => $ticket,
            'ticket_number' => strtoupper(trim($ticketNumber)),
            'previous_status' => $previousStatus,
            'current_status' => $currentStatus,
        ];
    }
}
