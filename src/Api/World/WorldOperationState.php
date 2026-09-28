<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

enum WorldOperationState: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::SUCCEEDED, self::FAILED, self::CANCELLED, self::REJECTED => true,
            self::QUEUED, self::RUNNING => false,
        };
    }
}
