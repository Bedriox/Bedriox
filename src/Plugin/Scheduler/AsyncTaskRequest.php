<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Scheduler;

use Bedriox\Api\Scheduler\AsyncTaskValue;
use Bedriox\Server\Plugin\PluginArchiveIdentity;

/** @internal */
final readonly class AsyncTaskRequest
{
    /** @param non-empty-string $taskClass */
    public function __construct(
        public int $taskId,
        public string $owner,
        public string $ownerVersion,
        public int $ownerGeneration,
        public PluginArchiveIdentity $packageIdentity,
        public string $taskClass,
        public AsyncTaskValue $input,
        public int $deadlineNanoseconds,
    ) {}
}
