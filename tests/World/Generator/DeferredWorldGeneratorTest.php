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

namespace Bedriox\Server\Tests\World\Generator;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\Generator\DeferredWorldGenerator;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldGeneratorFactory;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class DeferredWorldGeneratorTest extends TestCase
{
    public function testVillagePopulationIntentSurvivesProductionGeneratorDecoration(): void
    {
        $seed = -3_429_003_960_657_720_253;
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $generator = new DefaultWorldGenerator($seed, $states);
        $position = new ChunkPosition(29, 10);
        $expected = $generator->villageMarkersInChunk($position);
        self::assertNotEmpty($expected);

        $productionGenerator = WorldGeneratorFactory::create('default', $seed, $states);
        $deferred = new DeferredWorldGenerator(
            $productionGenerator->name(),
            $productionGenerator->version(),
            new SpawnPosition(-256, 72, 256),
            static fn() => WorldGeneratorFactory::create('default', $seed, $states),
        );
        $world = new World(
            new WorldMetadata('world', $seed),
            $deferred,
            new ChunkRepository(16),
        );

        self::assertEquals($expected, $world->villageMarkersInChunk($position));
    }
}
