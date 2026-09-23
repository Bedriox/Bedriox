<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerSubmission;
use Closure;

/** Narrow submission boundary used by the per-session compression coordinator. */
interface CompressionWorkerDispatcher
{
    public function workerCount(): int;

    /** @param Closure(WorkerResult): void $completion */
    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission;

    public function cancel(WorkerReceipt $receipt): bool;
}
