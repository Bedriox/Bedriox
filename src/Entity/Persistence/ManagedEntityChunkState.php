<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\World\ChunkPosition;

/** @internal */
final class ManagedEntityChunkState
{
    /** @var array<string, PersistentEntityRecord> */
    public array $records;

    /**
     * @param array<string, PersistentEntityRecord> $records
     */
    public function __construct(
        public readonly ChunkPosition $chunk,
        public int $revision,
        array $records,
        public bool $dirty = false,
        public int $mutationRevision = 0,
    ) {
        $this->records = $records;
    }
}
