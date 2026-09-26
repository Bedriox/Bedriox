<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\World\ChunkPosition;

final readonly class EntityPersistenceFlushResult
{
    /** @var list<ChunkPosition> */
    private array $failedChunks;

    /** @param array<int, ChunkPosition> $failedChunks */
    public function __construct(
        public int $attemptedChunks,
        public int $savedChunks,
        array $failedChunks,
    ) {
        $this->failedChunks = array_values($failedChunks);
    }

    /** @return list<ChunkPosition> */
    public function failedChunks(): array
    {
        return $this->failedChunks;
    }

    public function failedChunksCount(): int
    {
        return count($this->failedChunks);
    }
}
