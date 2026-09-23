<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence;

final readonly class PersistenceQueueSnapshot
{
    public function __construct(
        public int $queued,
        public int $inFlight,
        public int $completions,
        public int $requestBytes,
        public int $coalesced,
        public int $saturated,
        public int $failed,
    ) {}
}
