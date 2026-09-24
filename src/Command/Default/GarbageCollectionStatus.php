<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Server\Observability\Memory\GarbageCollectionReport;
use Bedriox\Server\Observability\Memory\MemoryPressure;
use Bedriox\Server\Observability\Memory\MemorySnapshot;
use InvalidArgumentException;

/** Immutable command-facing view of memory and chunk cleanup state. */
final readonly class GarbageCollectionStatus
{
    public function __construct(
        public MemorySnapshot $memory,
        public MemoryPressure $pressure,
        public int $collectorRuns,
        public int $collectorThreshold,
        public ?GarbageCollectionReport $lastCollection,
        public int $loadedChunks,
        public int $retainedChunks,
        public int $dirtyChunks,
        public int $queuedChunkUnloads,
        public int $chunksUnloaded,
    ) {
        if ($collectorRuns < 0 || $collectorThreshold < 1 || $loadedChunks < 0 || $retainedChunks < 0
            || $dirtyChunks < 0 || $queuedChunkUnloads < 0 || $chunksUnloaded < 0) {
            throw new InvalidArgumentException('Garbage collection status values are invalid.');
        }
    }
}
