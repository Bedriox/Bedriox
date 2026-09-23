<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Scheduler;

use Bedriox\Api\Scheduler\AsyncTaskFailure;
use Bedriox\Api\Scheduler\AsyncTaskValue;

/** @internal */
final readonly class AsyncTaskOutcome
{
    private function __construct(
        public int $taskId,
        public string $owner,
        public int $ownerGeneration,
        public ?AsyncTaskValue $result,
        public ?AsyncTaskFailure $failure,
    ) {}

    public static function completed(
        int $taskId,
        string $owner,
        int $ownerGeneration,
        AsyncTaskValue $result,
    ): self {
        return new self($taskId, $owner, $ownerGeneration, $result, null);
    }

    public static function failed(
        int $taskId,
        string $owner,
        int $ownerGeneration,
        AsyncTaskFailure $failure,
    ): self {
        return new self($taskId, $owner, $ownerGeneration, null, $failure);
    }
}
