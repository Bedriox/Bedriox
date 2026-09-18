<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WorldMetadata;
use InvalidArgumentException;

/** Immutable provider boundary value containing format-independent world metadata. */
final readonly class WorldData
{
    public function __construct(
        public WorldMetadata $metadata,
        public string $generatorName,
        public SpawnPosition $spawn,
        public int $time = 0,
    ) {
        if (
            $generatorName === ''
            || strlen($generatorName) > 64
            || preg_match('/^[A-Za-z0-9._-]+$/D', $generatorName) !== 1
        ) {
            throw new InvalidArgumentException('Generator name must contain 1-64 bounded identifier characters.');
        }
    }
}
