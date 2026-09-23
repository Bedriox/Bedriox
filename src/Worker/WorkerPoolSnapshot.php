<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

final readonly class WorkerPoolSnapshot
{
    public function __construct(
        public string $epoch,
        public int $workerCount,
        public int $busyWorkers,
        public int $pendingTasks,
        public int $pendingBytes,
        public int $readyResults,
        public int $submitted,
        public int $completed,
        public int $rejected,
        public int $cancelled,
        public int $timedOut,
        public int $failed,
        public int $restarts,
        public bool $available,
        public int $readyBytes = 0,
        public int $brokerMemoryBytes = 0,
        public int $workerMemoryBytes = 0,
    ) {}
}
