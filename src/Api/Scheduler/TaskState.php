<?php

declare(strict_types=1);

namespace Bedriox\Api\Scheduler;

enum TaskState: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';
    case OWNER_DISABLED = 'owner_disabled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED, self::REJECTED, self::OWNER_DISABLED => true,
            self::QUEUED, self::RUNNING => false,
        };
    }
}
