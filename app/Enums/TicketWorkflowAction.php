<?php

namespace App\Enums;

use App\Models\TicketStatus;

enum TicketWorkflowAction: string
{
    case PROCESS = 'process';
    case DONE = 'done';
    case CANCEL = 'cancel';

    public function targetStatus(): int
    {
        return match ($this) {
            self::PROCESS => TicketStatus::IN_PROGRESS,
            self::DONE => TicketStatus::CLOSED,
            self::CANCEL => TicketStatus::CANCEL,
        };
    }
}
