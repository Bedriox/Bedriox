<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence;

use InvalidArgumentException;

/** Immutable, canonical payload submitted to one ordered persistence owner. */
final readonly class PersistenceWriteRequest
{
    public function __construct(
        public int $id,
        public int $sequence,
        public string $key,
        public int $revision,
        public string $payload,
    ) {
        if ($id < 1 || $sequence < 1 || $revision < 0) {
            throw new InvalidArgumentException('Persistence request identity and revision are invalid.');
        }
        if ($key === '' || strlen($key) > 256 || str_contains($key, "\0")) {
            throw new InvalidArgumentException('Persistence request key is invalid.');
        }
    }

    public function bytes(): int
    {
        return strlen($this->key) + strlen($this->payload);
    }
}
