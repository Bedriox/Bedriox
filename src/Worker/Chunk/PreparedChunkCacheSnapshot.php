<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Chunk;

final readonly class PreparedChunkCacheSnapshot
{
    public function __construct(
        public int $entries,
        public int $bytes,
        public int $pending,
        public int $pendingBytes,
        public int $hits,
        public int $misses,
        public int $evictions,
        public int $invalidations,
        public int $failures,
    ) {}

    public function hitRatio(): ?float
    {
        $lookups = $this->hits + $this->misses;

        return $lookups === 0 ? null : $this->hits / $lookups;
    }
}
