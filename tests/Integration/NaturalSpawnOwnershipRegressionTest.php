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
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityDespawnPolicy;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityWorldRuntime;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferResult;
use Bedriox\Server\Entity\Persistence\EntityPersistenceCodec;
use Bedriox\Server\Entity\Persistence\EntityPersistenceManager;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnClock;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnPlayer;
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
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class NaturalSpawnOwnershipRegressionTest extends TestCase
{
    public function testDistanceDespawnRemovesOnlyActorsOwnedByNaturalSpawning(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('world', 12345),
            new FlatWorldGenerator($palette),
            new ChunkRepository(226),
        );
        for ($chunkX = -7; $chunkX <= 7; ++$chunkX) {
            for ($chunkZ = -7; $chunkZ <= 7; ++$chunkZ) {
                $world->chunk(new ChunkPosition($chunkX, $chunkZ));
            }
        }
        $shapes = BlockCollisionRegistry::forGenerationPalette($states, $generation);
        $collisions = new BlockCollisionQuery(
            $world,
            $palette->air,
            [$generation->state('minecraft:water'), $generation->state('minecraft:lava')],
            $shapes,
        );
        $registry = new EntityRegistry();
        $definitions = EntityDefinitionRegistry::baseline();
        $entities = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService(
                $registry,
                $definitions,
                static fn(string $_world, int $chunkX, int $chunkZ): bool =>
                    $world->hasLoadedChunk(new ChunkPosition($chunkX, $chunkZ)),
                static function (EntityDefinition $definition, Position $position) use ($collisions): bool {
                    $halfWidth = $definition->width / 2.0;

                    return !$collisions->hasCollision(new AxisAlignedBox(
                        $position->x - $halfWidth,
                        $position->y,
                        $position->z - $halfWidth,
                        $position->x + $halfWidth,
                        $position->y + $definition->height,
                        $position->z + $halfWidth,
                    ));
                },
            ),
        );
        $natural = WorldNaturalSpawnRuntime::baseline(
            $world,
            $entities,
            $definitions,
            $states,
            $shapes,
            $collisions,
            $palette->air,
            $generation->state('minecraft:water'),
            $generation->state('minecraft:lava'),
            new EntityIntegrationFrozenNaturalSpawnClock(),
        );
        $near = [new NaturalSpawnPlayer('world', new Position(0.5, 64.0, 0.5))];
        $naturalEntities = $natural->tick(20, $near)->spawned();
        self::assertNotEmpty($naturalEntities);

        $explicit = [];
        foreach ([SpawnCause::COMMAND, SpawnCause::SPAWN_EGG, SpawnCause::PLUGIN] as $index => $cause) {
            $outcome = $entities->spawn(new EntitySpawnRequest(
                VanillaEntityType::COW,
                $cause,
                'world',
                new Position(32.5 + $index, 64.0, 32.5),
            ));
            self::assertNotNull($outcome->entity);
            $explicit[] = $outcome->entity;
        }
        foreach ($registry->all() as $entity) {
            for ($age = 0; $age < 600; ++$age) {
                $entity->advanceAge();
            }
        }

        $far = [new NaturalSpawnPlayer('world', new Position(10_000.5, 64.0, 10_000.5))];
        $removed = $natural->tick(40, $far)->removed();
        self::assertNotEmpty($removed);
        foreach ($naturalEntities as $entity) {
            self::assertNull($registry->getByRuntimeId($entity->getRuntimeId()));
        }
        foreach ($explicit as $entity) {
            self::assertSame($entity, $registry->getByRuntimeId($entity->getRuntimeId()));
        }
    }

    public function testNaturalOwnershipSurvivesPersistenceUnloadAndRestart(): void
    {
        [$world, $states, $palette, $generation, $shapes, $collisions, $registry, $definitions, $entities]
            = self::dependencies();
        $natural = WorldNaturalSpawnRuntime::baseline(
            $world,
            $entities,
            $definitions,
            $states,
            $shapes,
            $collisions,
            $palette->air,
            $generation->state('minecraft:water'),
            $generation->state('minecraft:lava'),
            new EntityIntegrationFrozenNaturalSpawnClock(),
        );
        $near = [new NaturalSpawnPlayer('world', new Position(0.5, 64.0, 0.5))];
        $naturallySpawned = $natural->tick(20, $near)->spawned();
        self::assertNotEmpty($naturallySpawned);
        $naturalUuids = array_map(static fn($entity): string => $entity->getUniqueId(), $naturallySpawned);

        $explicitUuids = [];
        foreach ([SpawnCause::COMMAND, SpawnCause::SPAWN_EGG, SpawnCause::PLUGIN] as $index => $cause) {
            $outcome = $entities->spawn(new EntitySpawnRequest(
                VanillaEntityType::COW,
                $cause,
                'world',
                new Position(32.5 + $index, 64.0, 32.5),
            ));
            self::assertNotNull($outcome->entity);
            $explicitUuids[$cause->value] = $outcome->entity->getUniqueId();
        }
        foreach ($registry->all() as $entity) {
            for ($age = 0; $age < 600; ++$age) {
                $entity->advanceAge();
            }
        }

        $store = new NaturalOwnershipPersistenceStore();
        $persistence = new EntityPersistenceManager('world', $registry, $definitions, $store);
        foreach ($registry->all() as $entity) {
            $persistence->registerSpawned($entity);
        }
        $flushed = $persistence->flushShutdown();
        self::assertSame(count($store->chunks()), $flushed->savedChunks);
        foreach ($store->chunks() as $chunk) {
            $persistence->unloadChunk($chunk);
        }
        self::assertSame(0, $registry->count());

        [$restartedWorld, $restartedStates, $restartedPalette, $restartedGeneration, $restartedShapes,
            $restartedCollisions, $restartedRegistry, $restartedDefinitions, $restartedEntities]
            = self::dependencies();
        $restartedNatural = WorldNaturalSpawnRuntime::baseline(
            $restartedWorld,
            $restartedEntities,
            $restartedDefinitions,
            $restartedStates,
            $restartedShapes,
            $restartedCollisions,
            $restartedPalette->air,
            $restartedGeneration->state('minecraft:water'),
            $restartedGeneration->state('minecraft:lava'),
            new EntityIntegrationFrozenNaturalSpawnClock(),
        );
        $restartedPersistence = new EntityPersistenceManager(
            'world',
            $restartedRegistry,
            $restartedDefinitions,
            $store,
            afterActivation: static function (
                \Bedriox\Server\Entity\Persistence\EntityPersistenceRecord $_record,
                \Bedriox\Server\Entity\AbstractEntity $entity,
            ) use ($restartedNatural): bool {
                if ($entity instanceof \Bedriox\Server\Entity\AbstractLivingEntity) {
                    $restartedNatural->restoreDespawnOwnership($entity);
                }

                return true;
            },
        );
        foreach ($store->chunks() as $chunk) {
            self::assertFalse($restartedPersistence->activateChunk($chunk)->corrupt);
        }
        self::assertSame(count($naturalUuids), $restartedNatural->trackedCount());
        foreach ($naturalUuids as $uuid) {
            $entity = $restartedRegistry->getByUniqueId($uuid);
            self::assertNotNull($entity);
            self::assertSame(SpawnCause::NATURAL, $entity->spawnOrigin());
            self::assertSame(EntityDespawnPolicy::NATURAL_DISTANCE, $entity->despawnPolicy());
        }
        foreach ($explicitUuids as $cause => $uuid) {
            $entity = $restartedRegistry->getByUniqueId($uuid);
            self::assertNotNull($entity);
            self::assertSame($cause, $entity->spawnOrigin()->value);
            self::assertSame(EntityDespawnPolicy::EXPLICIT_ONLY, $entity->despawnPolicy());
        }

        $far = [new NaturalSpawnPlayer('world', new Position(10_000.5, 64.0, 10_000.5))];
        $removed = $restartedNatural->tick(640, $far)->removed();
        self::assertCount(count($naturalUuids), $removed);
        foreach ($naturalUuids as $uuid) {
            self::assertNull($restartedRegistry->getByUniqueId($uuid));
        }
        foreach ($explicitUuids as $uuid) {
            self::assertNotNull($restartedRegistry->getByUniqueId($uuid));
        }
    }

    /** @return array{World, BlockStateRegistry, FixedFlatBlockPalette, GenerationBlockPalette, BlockCollisionRegistry, BlockCollisionQuery, EntityRegistry, EntityDefinitionRegistry, EntityWorldRuntime} */
    private static function dependencies(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('world', 12345),
            new FlatWorldGenerator($palette),
            new ChunkRepository(226),
        );
        for ($chunkX = -7; $chunkX <= 7; ++$chunkX) {
            for ($chunkZ = -7; $chunkZ <= 7; ++$chunkZ) {
                $world->chunk(new ChunkPosition($chunkX, $chunkZ));
            }
        }
        $shapes = BlockCollisionRegistry::forGenerationPalette($states, $generation);
        $collisions = new BlockCollisionQuery(
            $world,
            $palette->air,
            [$generation->state('minecraft:water'), $generation->state('minecraft:lava')],
            $shapes,
        );
        $registry = new EntityRegistry();
        $definitions = EntityDefinitionRegistry::baseline();
        $entities = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService(
                $registry,
                $definitions,
                static fn(string $_world, int $chunkX, int $chunkZ): bool =>
                    $world->hasLoadedChunk(new ChunkPosition($chunkX, $chunkZ)),
                static function (EntityDefinition $definition, Position $position) use ($collisions): bool {
                    $halfWidth = $definition->width / 2.0;

                    return !$collisions->hasCollision(new AxisAlignedBox(
                        $position->x - $halfWidth,
                        $position->y,
                        $position->z - $halfWidth,
                        $position->x + $halfWidth,
                        $position->y + $definition->height,
                        $position->z + $halfWidth,
                    ));
                },
            ),
        );

        return [$world, $states, $palette, $generation, $shapes, $collisions, $registry, $definitions, $entities];
    }
}

final class EntityIntegrationFrozenNaturalSpawnClock implements NaturalSpawnClock
{
    public function nowNanoseconds(): int
    {
        return 0;
    }
}

final class NaturalOwnershipPersistenceStore implements EntityPersistenceStore
{
    /** @var array<string, string> */
    private array $documents = [];

    /** @var array<string, ChunkPosition> */
    private array $chunks = [];

    private readonly EntityPersistenceCodec $codec;

    public function __construct()
    {
        $this->codec = EntityPersistenceCodec::vanilla();
    }

    public function loadEntityChunk(
        ChunkPosition $position,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): ?EntityChunkSnapshot {
        $document = $this->documents[$dimension->value . ':' . $position->key()] ?? null;

        return $document === null ? null : $this->codec->decode($document);
    }

    public function saveEntityChunk(
        EntityChunkSnapshot $snapshot,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): void {
        $key = $dimension->value . ':' . $snapshot->chunk->key();
        $this->documents[$key] = $this->codec->encode($snapshot);
        $this->chunks[$key] = $snapshot->chunk;
    }

    public function transferEntityOwnership(
        EntityOwnershipTransfer $transfer,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): EntityOwnershipTransferResult {
        $this->saveEntityChunk($transfer->sourceAfter, $dimension);
        $this->saveEntityChunk($transfer->destinationAfter, $dimension);

        return new EntityOwnershipTransferResult($transfer->sourceAfter, $transfer->destinationAfter);
    }

    /** @return list<ChunkPosition> */
    public function chunks(): array
    {
        return array_values($this->chunks);
    }
}
