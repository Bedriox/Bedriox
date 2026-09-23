<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Chunk;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\WorldGeneratorType;

final readonly class ChunkGenerationRequest
{
    public function __construct(
        public string $generator,
        public int $generatorVersion,
        public int $seed,
        public string $dimension,
        public ChunkPosition $position,
    ) {
        if (WorldGeneratorType::tryFrom($generator) === null || $generatorVersion < 1 || $generatorVersion > 65_535
            || $dimension !== 'minecraft:overworld') {
            throw new \InvalidArgumentException('Chunk generation request has unsupported generator metadata.');
        }
    }
}
