<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

final readonly class BackgroundLogWriterSnapshot
{
    public function __construct(
        public bool $available,
        public bool $inFlight,
        public LogQueueSnapshot $queue,
        public int $acknowledged,
        public int $serviceFailures,
    ) {}
}
