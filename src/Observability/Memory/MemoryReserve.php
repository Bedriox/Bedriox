<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability\Memory;

interface MemoryReserve
{
    public function reservedBytes(): int;

    public function isAvailable(): bool;

    /** Releases the reserve once and returns the number of bytes made available. */
    public function release(): int;
}
