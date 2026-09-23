<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

use Closure;

interface WorkerDispatcher
{
    /** @param Closure(WorkerResult): void $completion */
    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission;

    public function cancel(WorkerReceipt $receipt): bool;

    public function poll(int $maximumCompletions = 256): void;

    public function snapshot(): WorkerPoolSnapshot;

    public function shutdown(): void;
}
