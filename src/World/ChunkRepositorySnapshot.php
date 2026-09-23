<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

final readonly class ChunkRepositorySnapshot
{
    public function __construct(
        public int $capacity,
        public int $loaded,
        public int $retainedChunks,
        public int $retentionReferences,
        public int $dirty,
        public int $hits,
        public int $misses,
        public int $evictions,
    ) {}

    public function hitRatio(): ?float
    {
        $lookups = $this->hits + $this->misses;

        return $lookups === 0 ? null : $this->hits / $lookups;
    }
}
