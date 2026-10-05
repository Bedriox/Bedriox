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

namespace Bedriox\Server\Tests\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiWorldView;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityWorldRuntime;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnClock;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnPlayer;
use Bedriox\Server\Entity\Spawn\Natural\WorldNaturalSpawnEnvironment;
use Bedriox\Server\Entity\Spawn\Natural\WorldNaturalSpawnRuntime;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\BlockCollisionQuery;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\NetherWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class WorldNaturalSpawnRuntimeTest extends TestCase
{
    public function testEnvironmentUsesLoadedFinalizedTerrainBiomeMediumAndCollision(): void
    {
        [$world, $states, $palette, $shapes, $query, $runtime, $definitions] = self::dependencies(loadRadius: 0);
        $environment = new WorldNaturalSpawnEnvironment(
            $world,
            $runtime->registry(),
            $definitions,
            $states,
            $shapes,
            $query,
            $palette->air,
            GenerationBlockPalette::fromRegistry($states)->state('minecraft:water'),
            GenerationBlockPalette::fromRegistry($states)->state('minecraft:lava'),
        );
        $environment->updateContext([
            new NaturalSpawnPlayer('world', new Position(64.0, 64.0, 64.0)),
        ]);

        self::assertEquals(
            new Position(0.5, 64.0, 0.5),
            $environment->candidatePosition('world', NaturalSpawnMedium::GROUND, 0.5, 0.5, 0.5),
        );
        self::assertSame('minecraft:plains', $environment->biome('world', new Position(0.5, 64.0, 0.5)));
        self::assertSame(
            NaturalSpawnMedium::GROUND,
            $environment->medium('world', new Position(0.5, 64.0, 0.5)),
        );
        self::assertSame(15, $environment->lightLevel('world', new Position(0.5, 64.0, 0.5)));
        self::assertTrue($environment->isCollisionFree(
            'world',
            VanillaEntityType::COW,
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertFalse($environment->isCollisionFree(
            'world',
            VanillaEntityType::COW,
            new Position(0.5, 63.0, 0.5),
        ));
        self::assertNull($environment->candidatePosition(
            'world',
            NaturalSpawnMedium::GROUND,
            16.5,
            0.5,
            0.5,
        ));
    }

    public function testBaselineSpawnsOnlyLoadedCowOrZombieCandidatesOnCadence(): void
    {
        [$world, $states, $palette, $shapes, $query, $entities, $definitions] = self::dependencies();
        $runtime = WorldNaturalSpawnRuntime::baseline(
            $world,
            $entities,
            $definitions,
            $states,
            $shapes,
            $query,
            $palette->air,
            GenerationBlockPalette::fromRegistry($states)->state('minecraft:water'),
            GenerationBlockPalette::fromRegistry($states)->state('minecraft:lava'),
            new FrozenNaturalSpawnClock(),
        );
        $players = [new NaturalSpawnPlayer('world', new Position(0.5, 64.0, 0.5))];

        self::assertSame([], $runtime->tick(19, $players)->spawned());
        $daySpawned = [];
        for ($tick = 20; $tick <= 200 && $daySpawned === []; $tick += 20) {
            $daySpawned = $runtime->tick($tick, $players)->spawned();
        }
        self::assertNotEmpty($daySpawned);
        foreach ($daySpawned as $entity) {
            self::assertContains($entity->getType(), [
                VanillaEntityType::CHICKEN,
                VanillaEntityType::COW,
                VanillaEntityType::PIG,
                VanillaEntityType::RABBIT,
                VanillaEntityType::SHEEP,
            ]);
            self::assertTrue($world->hasLoadedChunk(new ChunkPosition(
                (int) floor($entity->internalPosition()->x / 16.0),
                (int) floor($entity->internalPosition()->z / 16.0),
            )));
        }
        self::assertGreaterThanOrEqual(count($daySpawned), $runtime->trackedCount());

        $world->setTime(14_000);
        $nightSpawned = [];
        for ($tick = 220; $tick <= 600 && $nightSpawned === []; $tick += 20) {
            $nightSpawned = $runtime->tick($tick, $players)->spawned();
        }
        self::assertNotEmpty($nightSpawned);
        foreach ($nightSpawned as $entity) {
            self::assertContains($entity->getType(), [
                VanillaEntityType::CREEPER,
                VanillaEntityType::ENDERMAN,
                VanillaEntityType::SKELETON,
                VanillaEntityType::SPIDER,
                VanillaEntityType::WITCH,
                VanillaEntityType::ZOMBIE,
            ]);
        }
    }

    public function testRuntimeUsesLogicalWorldIdentityInsteadOfDisplayNameAfterTransfer(): void
    {
        [$world, $states, $palette, $shapes, $query, $entities, $definitions] = self::dependencies(
            metadataName: 'Display Name',
            dimension: WorldDimension::NETHER,
        );
        $generation = GenerationBlockPalette::fromRegistry($states);
        $runtime = WorldNaturalSpawnRuntime::baseline(
            $world,
            $entities,
            $definitions,
            $states,
            $shapes,
            $query,
            $palette->air,
            $generation->state('minecraft:water'),
            $generation->state('minecraft:lava'),
            new FrozenNaturalSpawnClock(),
            worldName: 'world-default',
        );

        $result = $runtime->tick(20, [
            new NaturalSpawnPlayer('world-default', new Position(0.5, 64.0, 0.5)),
        ]);

        self::assertSame(WorldDimension::NETHER, $world->dimension());
        self::assertSame([], $result->removed());
    }

    public function testGeneratedNetherProducesVisibleNaturalNetherMobs(): void
    {
        [$world, $states, $palette, $shapes, $query, $entities, $definitions] = self::dependencies(
            loadRadius: 4,
            dimension: WorldDimension::NETHER,
            useDimensionGenerator: true,
        );
        $generation = GenerationBlockPalette::fromRegistry($states);
        $runtime = WorldNaturalSpawnRuntime::baseline(
            $world,
            $entities,
            $definitions,
            $states,
            $shapes,
            $query,
            $palette->air,
            $generation->state('minecraft:water'),
            $generation->state('minecraft:lava'),
            new FrozenNaturalSpawnClock(),
        );
        $spawn = $world->spawn();
        $players = [new NaturalSpawnPlayer('world', new Position($spawn->x + 0.5, $spawn->y, $spawn->z + 0.5))];
        $spawned = [];
        for ($tick = 20; $tick <= 2_000 && $spawned === []; $tick += 20) {
            $spawned = $runtime->tick($tick, $players)->spawned();
        }

        self::assertNotEmpty($spawned);
        foreach ($spawned as $entity) {
            self::assertContains($entity->getType(), [
                VanillaEntityType::BLAZE,
                VanillaEntityType::ENDERMAN,
                VanillaEntityType::GHAST,
                VanillaEntityType::HOGLIN,
                VanillaEntityType::MAGMA_CUBE,
                VanillaEntityType::PIGLIN,
                VanillaEntityType::PIGLIN_BRUTE,
                VanillaEntityType::SKELETON,
                VanillaEntityType::STRIDER,
                VanillaEntityType::WITHER_SKELETON,
                VanillaEntityType::ZOMBIFIED_PIGLIN,
            ]);
            self::assertLessThanOrEqual(127.0, $entity->internalPosition()->y);
        }
    }

    public function testHardDistanceDespawnCannotRemoveCommandEntities(): void
    {
        [$world, $states, $palette, $shapes, $query, $entities, $definitions] = self::dependencies();
        $runtime = WorldNaturalSpawnRuntime::baseline(
            $world,
            $entities,
            $definitions,
            $states,
            $shapes,
            $query,
            $palette->air,
            GenerationBlockPalette::fromRegistry($states)->state('minecraft:water'),
            GenerationBlockPalette::fromRegistry($states)->state('minecraft:lava'),
            new FrozenNaturalSpawnClock(),
        );
        $near = [new NaturalSpawnPlayer('world', new Position(0.5, 64.0, 0.5))];
        $natural = $runtime->tick(20, $near)->spawned();
        self::assertNotEmpty($natural);
        $explicit = [];
        foreach ([SpawnCause::COMMAND, SpawnCause::SPAWN_EGG, SpawnCause::PLUGIN] as $index => $cause) {
            $entity = $entities->spawn(new EntitySpawnRequest(
                VanillaEntityType::COW,
                $cause,
                'world',
                new Position(32.5 + $index, 64.0, 32.5),
            ))->entity;
            self::assertNotNull($entity);
            $explicit[] = $entity;
        }

        $view = new EmptyNaturalAiWorldView();
        for ($tick = 21; $tick <= 620; ++$tick) {
            $entities->tick($tick, $view, false);
        }
        $far = [new NaturalSpawnPlayer('world', new Position(10_000.5, 64.0, 10_000.5))];
        $despawned = $runtime->tick(640, $far)->removed();

        self::assertNotEmpty($despawned);
        foreach ($explicit as $entity) {
            self::assertSame($entity, $entities->registry()->getByRuntimeId($entity->getRuntimeId()));
            foreach ($despawned as $removed) {
                self::assertNotSame($entity->getRuntimeId(), $removed->getRuntimeId());
            }
        }
    }

    public function testGameplaySwitchesDisableNaturalAnimalAndMonsterSpawns(): void
    {
        [$world, $states, $palette, $shapes, $query, $entities, $definitions] = self::dependencies();
        $generation = GenerationBlockPalette::fromRegistry($states);
        $runtime = WorldNaturalSpawnRuntime::baseline(
            $world,
            $entities,
            $definitions,
            $states,
            $shapes,
            $query,
            $palette->air,
            $generation->state('minecraft:water'),
            $generation->state('minecraft:lava'),
            new FrozenNaturalSpawnClock(),
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $players = [new NaturalSpawnPlayer('world', new Position(0.5, 64.0, 0.5))];

        self::assertSame([], $runtime->tick(20, $players)->spawned());
        self::assertSame([], $runtime->tick(14_000, $players)->spawned());
        self::assertSame(0, $runtime->trackedCount());
    }

    public function testRuntimeAcceptsTheSupportedMaximumAlivePlayerCount(): void
    {
        [$world, $states, $palette, $shapes, $query, $entities, $definitions] = self::dependencies();
        $generation = GenerationBlockPalette::fromRegistry($states);
        $runtime = WorldNaturalSpawnRuntime::baseline(
            $world,
            $entities,
            $definitions,
            $states,
            $shapes,
            $query,
            $palette->air,
            $generation->state('minecraft:water'),
            $generation->state('minecraft:lava'),
            new FrozenNaturalSpawnClock(),
        );
        $players = array_fill(
            0,
            1_024,
            new NaturalSpawnPlayer('world', new Position(0.5, 64.0, 0.5)),
        );

        self::assertNotEmpty($runtime->tick(20, $players)->spawned());
    }

    /**
     * @return array{
     *     World,
     *     BlockStateRegistry,
     *     FixedFlatBlockPalette,
     *     BlockCollisionRegistry,
     *     BlockCollisionQuery,
     *     EntityWorldRuntime,
     *     EntityDefinitionRegistry
     * }
     */
    private static function dependencies(
        int $loadRadius = 7,
        string $metadataName = 'world',
        WorldDimension $dimension = WorldDimension::OVERWORLD,
        bool $useDimensionGenerator = false,
    ): array {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $generator = $useDimensionGenerator && $dimension === WorldDimension::NETHER
            ? new NetherWorldGenerator(12345, $states)
            : new FlatWorldGenerator($palette);
        $world = new World(
            new WorldMetadata($metadataName, 12345),
            $generator,
            new ChunkRepository(($loadRadius * 2 + 1) ** 2 + 1),
            dimension: $dimension,
        );
        for ($x = -$loadRadius; $x <= $loadRadius; ++$x) {
            for ($z = -$loadRadius; $z <= $loadRadius; ++$z) {
                $world->chunk(new ChunkPosition($x, $z));
            }
        }
        $shapes = BlockCollisionRegistry::forGenerationPalette($states, $generation);
        $query = new BlockCollisionQuery(
            $world,
            $palette->air,
            [$generation->state('minecraft:water'), $generation->state('minecraft:lava')],
            $shapes,
        );
        $registry = new EntityRegistry();
        $definitions = EntityDefinitionRegistry::baseline();
        $spawns = new EntitySpawnService(
            $registry,
            $definitions,
            static fn(string $_world, int $chunkX, int $chunkZ): bool =>
                $world->hasLoadedChunk(new ChunkPosition($chunkX, $chunkZ)),
            static function (EntityDefinition $definition, Position $position) use ($query): bool {
                $halfWidth = $definition->width / 2.0;

                return !$query->hasCollision(new AxisAlignedBox(
                    $position->x - $halfWidth,
                    $position->y,
                    $position->z - $halfWidth,
                    $position->x + $halfWidth,
                    $position->y + $definition->height,
                    $position->z + $halfWidth,
                ));
            },
        );

        return [$world, $states, $palette, $shapes, $query, new EntityWorldRuntime($registry, $spawns), $definitions];
    }
}

final class FrozenNaturalSpawnClock implements NaturalSpawnClock
{
    public function nowNanoseconds(): int
    {
        return 0;
    }
}

final class EmptyNaturalAiWorldView implements AiWorldView
{
    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return [];
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        return null;
    }
}
