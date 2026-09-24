<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability\Memory;

use InvalidArgumentException;

/** A bounded allocation held exclusively for critical-pressure diagnostics and controlled shutdown. */
final class EmergencyMemoryReserve implements MemoryReserve
{
    public const DEFAULT_BYTES = 8 * 1024 * 1024;
    public const MAXIMUM_BYTES = 64 * 1024 * 1024;

    private ?string $buffer;

    public function __construct(private readonly int $bytes = self::DEFAULT_BYTES)
    {
        if ($bytes < 1 || $bytes > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Emergency memory reserve size is out of range.');
        }
        $this->buffer = str_repeat("\0", $bytes);
    }

    public function reservedBytes(): int
    {
        return $this->buffer === null ? 0 : $this->bytes;
    }

    public function isAvailable(): bool
    {
        return $this->buffer !== null;
    }

    public function release(): int
    {
        if ($this->buffer === null) {
            return 0;
        }
        $this->buffer = null;

        return $this->bytes;
    }
}
