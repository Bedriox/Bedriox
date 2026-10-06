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

namespace Bedriox\Server\Tests\Integration;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\NetherWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class GeneratedNetherWorldSimulationTest extends TestCase
{
    public function testGeneratedNetherTerrainAdmitsRepresentativeNaturalSpawnsThroughWorldSimulation(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $generator = new NetherWorldGenerator(42, $states);
        $world = new World(
            new WorldMetadata('nether', 42),
            $generator,
            new ChunkRepository(64),
            dimension: WorldDimension::NETHER,
        );
        $spawnChunkX = 0;
        $spawnChunkZ = 0;
        for ($chunkX = $spawnChunkX - 3; $chunkX <= $spawnChunkX + 3; ++$chunkX) {
            for ($chunkZ = $spawnChunkZ - 3; $chunkZ <= $spawnChunkZ + 3; ++$chunkZ) {
                $world->retainChunk(new ChunkPosition($chunkX, $chunkZ));
            }
        }
        $origin = $world->chunk(new ChunkPosition(0, 0));
        $spawn = null;
        for ($y = 120; $y >= 33 && $spawn === null; --$y) {
            for ($z = 0; $z < 16 && $spawn === null; ++$z) {
                for ($x = 0; $x < 16; ++$x) {
                    $floor = $states->state($origin->blockStateAt($x, $y, $z))->identifier();
                    $feet = $states->state($origin->blockStateAt($x, $y + 1, $z))->identifier();
                    $head = $states->state($origin->blockStateAt($x, $y + 2, $z))->identifier();
                    if (!in_array($floor, ['minecraft:air', 'minecraft:lava', 'minecraft:magma'], true)
                        && $feet === 'minecraft:air' && $head === 'minecraft:air') {
                        $spawn = new Position($x + 0.5, $y + 1.0, $z + 0.5);
                        break;
                    }
                }
            }
        }
        self::assertNotNull($spawn);
        $world->setSpawn(new \Bedriox\Server\World\SpawnPosition(
            (int) floor($spawn->x),
            (int) floor($spawn->y),
            (int) floor($spawn->z),
        ));
        $simulation = new WorldSimulation(
            spawn: $spawn,
            blockWorld: $world,
            blockPalette: $palette,
            waterState: $generation->state('minecraft:water'),
            lavaState: $generation->state('minecraft:lava'),
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette($states, $generation),
            entityAiEnabled: false,
            spawnAnimals: true,
            spawnMonsters: true,
            worldId: 'nether',
            dimension: WorldDimension::NETHER,
            naturalSpawnClock: new class implements \Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnClock {
                public function nowNanoseconds(): int
                {
                    return 0;
                }
            },
        );
        $commands = new SimulationCommandFactory();
        $simulation->enqueue($commands->join('viewer', 'nether-viewer', 'NetherViewer'));
        $simulation->tick();
        self::assertCount(1, $simulation->snapshot()->players);

        $natural = [];
        for ($tick = 0; $tick < 400 && $natural === []; ++$tick) {
            $simulation->tick();
            $natural = array_values(array_filter(
                $simulation->entityRuntime()->registry()->all(),
                static fn($entity): bool => $entity->spawnOrigin() === SpawnCause::NATURAL,
            ));
        }

        self::assertNotEmpty($natural);
        self::assertContains($natural[0]->getType(), [
            VanillaEntityType::STRIDER,
            VanillaEntityType::GHAST,
            VanillaEntityType::BLAZE,
            VanillaEntityType::HOGLIN,
            VanillaEntityType::PIGLIN,
            VanillaEntityType::ZOMBIFIED_PIGLIN,
            VanillaEntityType::MAGMA_CUBE,
            VanillaEntityType::WITHER_SKELETON,
        ]);
        self::assertSame('nether', $natural[0]->getWorldName());
    }
}
