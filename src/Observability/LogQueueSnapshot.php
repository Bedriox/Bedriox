<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

final readonly class LogQueueSnapshot
{
    public function __construct(
        public int $queued,
        public int $queuedBytes,
        public int $droppedRoutine,
        public int $droppedHighSeverity,
        public int $writeFailures,
        public ?int $oldestSequence,
    ) {}
}
