<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

use Bedriox\Server\Worker\ManagedWorkerDispatcher;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerSubmission;
use Closure;

final readonly class ManagedCompressionWorkerDispatcher implements CompressionWorkerDispatcher
{
    public function __construct(private ManagedWorkerDispatcher $dispatcher) {}

    public function workerCount(): int
    {
        return $this->dispatcher->snapshot()->workerCount;
    }

    /** @param Closure(WorkerResult): void $completion */
    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission {
        return $this->dispatcher->submit($taskTypeId, $payload, $completion, $deadlineNanoseconds);
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return $this->dispatcher->cancel($receipt);
    }
}
