<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

use InvalidArgumentException;

final readonly class WorkerLimits
{
    public function __construct(
        public int $maximumQueuedTasks = 1_024,
        public int $maximumQueuedBytes = 134_217_728,
        public int $maximumTaskBytes = 16_777_216,
        public int $maximumResultBytes = 33_554_432,
        public int $maximumFramesPerPoll = 256,
        public int $maximumBufferedIpcBytes = 134_217_728,
        public int $shutdownGraceMilliseconds = 2_000,
        public int $maximumReadyResults = 1_024,
        public int $maximumReadyBytes = 134_217_728,
    ) {
        if ($maximumQueuedTasks < 1 || $maximumQueuedTasks > 1_024
            || $maximumQueuedBytes < 1 || $maximumQueuedBytes > 134_217_728
            || $maximumTaskBytes < 1 || $maximumTaskBytes > 16_777_216
            || $maximumResultBytes < 1 || $maximumResultBytes > 33_554_432
            || $maximumFramesPerPoll < 1 || $maximumFramesPerPoll > 256
            || $maximumBufferedIpcBytes < 1 || $maximumBufferedIpcBytes > 134_217_728
            || $shutdownGraceMilliseconds < 1 || $shutdownGraceMilliseconds > 30_000
            || $maximumReadyResults < 1 || $maximumReadyResults > $maximumQueuedTasks
            || $maximumReadyBytes < $maximumResultBytes
            || $maximumReadyBytes > 134_217_728) {
            throw new InvalidArgumentException('Worker limit exceeds its accepted safety boundary.');
        }
    }
}
