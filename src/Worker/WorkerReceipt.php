<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

final readonly class WorkerReceipt
{
    public function __construct(
        public string $epoch,
        public int $taskId,
        public int $taskTypeId,
        public string $owner,
        public int $deadlineNanoseconds,
    ) {}
}
