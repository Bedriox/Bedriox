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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class GeneratedVillagePopulationTest extends TestCase
{
    public function testFinalizedGeneratedVillageChunksPopulateThroughWorldSimulation(): void
    {
        $seed = -3_429_003_960_657_720_253;
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $generator = new DefaultWorldGenerator($seed, $states);
        $world = new World(
            new WorldMetadata('village-population-regression', $seed),
            $generator,
            new ChunkRepository(256),
        );

        $markerChunks = [];
        for ($chunkZ = 6; $chunkZ <= 14; ++$chunkZ) {
            for ($chunkX = 25; $chunkX <= 37; ++$chunkX) {
                $position = new ChunkPosition($chunkX, $chunkZ);
                if ($generator->villageMarkersInChunk($position) !== []) {
                    $markerChunks[] = $position;
                    $world->retainChunk($position);
                }
            }
        }
        self::assertNotEmpty($markerChunks);

        $generation = GenerationBlockPalette::fromRegistry($states);
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: FixedFlatBlockPalette::fromRegistry($states),
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette(
                $states,
                $generation,
                $data->voxelShapeRegistry(),
            ),
            spawnAnimals: false,
            spawnMonsters: false,
            entityTypes: $data->entityTypeRegistry(),
            entityDefinitions: EntityDefinitionRegistry::fromData($data->entityTypeRegistry()),
            worldId: 'village-population-regression',
        );

        for ($tick = 0; $tick < 4; ++$tick) {
            $simulation->tick();
        }

        self::assertSame(
            8,
            $simulation->entityRuntime()->registry()->countByType(VanillaEntityType::VILLAGER),
        );
        self::assertSame(
            1,
            $simulation->entityRuntime()->registry()->countByType(VanillaEntityType::IRON_GOLEM),
        );
    }
}
