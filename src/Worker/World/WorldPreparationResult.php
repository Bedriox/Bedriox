<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\World;

use Bedriox\Server\World\Generator\GeneratorIdentifier;
use Bedriox\Server\World\SpawnPosition;

/** Validated immutable metadata returned before storage creation begins. */
final readonly class WorldPreparationResult
{
    public function __construct(
        public string $generatorIdentifier,
        public int $generatorVersion,
        public SpawnPosition $defaultSpawn,
    ) {
        new GeneratorIdentifier($generatorIdentifier);
        if ($generatorVersion < 1 || $generatorVersion > 65_535) {
            throw new \InvalidArgumentException('Prepared generator version is invalid.');
        }
    }
}
