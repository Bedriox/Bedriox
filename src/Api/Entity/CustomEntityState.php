<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

use InvalidArgumentException;

/** Bounded, opaque custom state. Bedriox never deserializes plugin payloads. */
final readonly class CustomEntityState
{
    public const int MAXIMUM_BYTES = 65_536;

    public function __construct(
        public int $schemaVersion,
        private string $payload,
    ) {
        if ($schemaVersion < 1 || $schemaVersion > 65_535) {
            throw new InvalidArgumentException('Custom entity state schema version is outside its supported bounds.');
        }
        if (strlen($payload) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Custom entity state exceeds its supported byte limit.');
        }
    }

    public function bytes(): string
    {
        return $this->payload;
    }

    public function size(): int
    {
        return strlen($this->payload);
    }
}
