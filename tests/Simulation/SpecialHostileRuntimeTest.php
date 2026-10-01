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

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\CreeperEntity;
use Bedriox\Server\Entity\Vanilla\EndermanEntity;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\EntityExplosionPresented;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class SpecialHostileRuntimeTest extends TestCase
{
    public function testManuallyIgnitedCreeperCompletesItsBoundedFuse(): void
    {
        [$simulation] = self::simulation();
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::CREEPER,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(CreeperEntity::class, $spawn->entity);
        $creeper = $spawn->entity;
        $creeper->setIgnited(true);

        $events = [];
        for ($tick = 0; $tick < 30; ++$tick) {
            array_push($events, ...$simulation->tick()->events);
        }

        self::assertNull($simulation->entityRuntime()->registry()->getByRuntimeId($creeper->getRuntimeId()));
        self::assertNotEmpty(array_filter($events, static fn(object $event): bool => $event instanceof EntityActorRemoved));
        self::assertNotEmpty(array_filter($events, static fn(object $event): bool => $event instanceof EntityExplosionPresented));
    }

    public function testEndermanTakesBoundedWaterContactDamage(): void
    {
        [$simulation, $world, $generation] = self::simulation();
        $world->setBlockState(0, 64, 0, $generation->state('minecraft:water'));
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ENDERMAN,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(EndermanEntity::class, $spawn->entity);
        $enderman = $spawn->entity;

        for ($tick = 0; $tick < 20; ++$tick) {
            $simulation->tick();
        }

        self::assertSame(39.0, $enderman->getHealth());
    }

    /** @return array{WorldSimulation, World, GenerationBlockPalette} */
    private static function simulation(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(new WorldMetadata('world', 12345), new FlatWorldGenerator($palette), new ChunkRepository(4));
        $world->chunk(new ChunkPosition(0, 0));
        $shapes = BlockCollisionRegistry::forGenerationPalette($states, $generation);
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            waterState: $generation->state('minecraft:water'),
            lavaState: $generation->state('minecraft:lava'),
            blockStateRegistry: $states,
            blockCollisionRegistry: $shapes,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );

        return [$simulation, $world, $generation];
    }
}
