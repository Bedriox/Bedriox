<?php

declare(strict_types=1);

namespace Bedriox\Api\Scheduler;

final readonly class AsyncTaskFailure
{
    public function __construct(
        public string $type,
        public string $message,
    ) {
        if ($type === '' || strlen($type) > 256) {
            throw new \InvalidArgumentException('Async task failure type must contain between 1 and 256 bytes.');
        }
        if (strlen($message) > 1024) {
            throw new \InvalidArgumentException('Async task failure message cannot exceed 1024 bytes.');
        }
    }
}
