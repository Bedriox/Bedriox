<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\WorldMetadata;
use Bedriox\Server\World\WorldTimeRules;
use InvalidArgumentException;

/** Immutable provider boundary value containing format-independent world metadata. */
final readonly class WorldData
{
    public function __construct(
        public WorldMetadata $metadata,
        public string $generatorName,
        public SpawnPosition $spawn,
        public int $time = 0,
        public int $difficulty = 2,
        public int $generatorVersion = 1,
        public string $generatorOptions = '{}',
    ) {
        if (
            $generatorName === ''
            || strlen($generatorName) > 128
            || preg_match('/^[A-Za-z0-9._-]+(?::[A-Za-z0-9._\/-]+)?$/D', $generatorName) !== 1
        ) {
            throw new InvalidArgumentException('Generator name must be a bounded canonical identifier.');
        }
        if ($this->difficulty < 0 || $this->difficulty > 3) {
            throw new InvalidArgumentException('Difficulty must be a Bedrock value between 0 and 3.');
        }
        WorldTimeRules::validate($this->time);
        if ($this->generatorVersion < 1 || $this->generatorVersion > 2_147_483_647) {
            throw new InvalidArgumentException('Generator version must be a positive bounded integer.');
        }
        GeneratorOptions::fromJson($this->generatorOptions);
    }
}
