<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

final readonly class ChunkUnloadResult
{
    public function __construct(
        public int $examined,
        public int $evicted,
        public int $saveSubmissions,
        public bool $persistenceSaturated,
        public int $remainingQueued,
    ) {}
}
