<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Fixtures;

use Bedriox\Api\World\Generator\BlockState;
use Bedriox\Api\World\Generator\ChunkPosition;
use Bedriox\Api\World\Generator\GeneratedChunk;
use Bedriox\Api\World\Generator\GeneratedSection;
use Bedriox\Api\World\Generator\Generator;
use Bedriox\Api\World\Generator\GeneratorContext;
use Bedriox\Api\World\Generator\GeneratorSpawn;

final class WorkerPluginGenerator implements Generator
{
    public function generate(GeneratorContext $context, ChunkPosition $position): GeneratedChunk
    {
        $air = new BlockState('minecraft:air');

        return new GeneratedChunk([
            GeneratedSection::uniform(3, $air),
            GeneratedSection::layered(4, [
                new BlockState('minecraft:grass_block'),
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
                $air,
            ]),
        ]);
    }

    public function defaultSpawn(GeneratorContext $context): GeneratorSpawn
    {
        return new GeneratorSpawn(0, 65, 0);
    }
}
