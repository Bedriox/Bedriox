<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

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
