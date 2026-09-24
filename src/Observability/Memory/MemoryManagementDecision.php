<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability\Memory;

use InvalidArgumentException;

final readonly class MemoryManagementDecision
{
    public function __construct(
        public MemorySnapshot $snapshot,
        public MemoryPressure $previousPressure,
        public MemoryPressure $pressure,
        public bool $collectCycles,
        public bool $releaseAllocatorCaches,
        public bool $accelerateChunkUnloading,
        public int $maximumChunkUnloads,
        public bool $trimDisposableCaches,
        public bool $suspendPrefetch,
        public bool $prioritizePersistence,
        public bool $pauseOptionalGeneration,
        public int $emergencyReserveBytesReleased,
    ) {
        if ($maximumChunkUnloads < 0 || $emergencyReserveBytesReleased < 0) {
            throw new InvalidArgumentException('Memory management decision limits cannot be negative.');
        }
    }
}
