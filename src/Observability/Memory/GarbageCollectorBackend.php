<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability\Memory;

interface GarbageCollectorBackend
{
    public function rootCount(): int;

    public function collectCycles(): int;

    public function releaseAllocatorCaches(): int;

    public function monotonicNanoseconds(): int;
}
