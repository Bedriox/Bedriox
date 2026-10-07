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

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiWorldView;
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityPhysicsResolver;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\EntityWorkBudget;
use Bedriox\Server\Entity\EntityWorldRuntime;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\HorseEntity;
use Bedriox\Server\Entity\Vehicle\BoatEntity;
use Bedriox\Server\Entity\WorldEntityEnvironment;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\BlockCollisionQuery;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\Collision\LoadedCollisionBoxQuery;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class EntityWorldRuntimeTest extends TestCase
{
    public function testGroundFrictionStopsContactImpulseFromSlidingLikeIce(): void
    {
        $entity = new CowEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.5, 64.0, 0.5),
            motion: new EntityMotion(0.05, 0.0, 0.0),
        );
        $entity->setOnGround(true);
        $resolver = new EntityPhysicsResolver(new class implements LoadedCollisionBoxQuery {
            public function boxesIntersecting(AxisAlignedBox $area): array
            {
                return $this->boxesIntersectingLoaded($area);
            }

            public function hasCollision(AxisAlignedBox $area): bool
            {
                return $this->boxesIntersectingLoaded($area) !== [];
            }

            public function boxesIntersectingLoaded(AxisAlignedBox $area): array
            {
                $floor = new AxisAlignedBox(-10.0, 63.0, -10.0, 10.0, 64.0, 10.0);

                return $floor->intersects($area) ? [$floor] : [];
            }
        });

        $resolver->tick($entity, 1);

        self::assertEqualsWithDelta(0.0294, $entity->getMotion()->x, 0.000_001);
        self::assertEqualsWithDelta(0.5294, $entity->getPosition()->x, 0.000_001);
    }

    public function testGroundFrictionComesFromTheSupportingBlockDefinition(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('friction-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world->chunk(new ChunkPosition(0, 0));
        $support = $generation->state('minecraft:grass_block');
        $world->setBlockState(0, 63, 0, $support);
        $shapes = BlockCollisionRegistry::forGenerationPalette($states, $generation);
        $environment = new WorldEntityEnvironment(
            $world,
            $states,
            $shapes,
            $palette->air,
            $generation->state('minecraft:water'),
            $data->blockPropertyRegistry(),
        );
        $entity = new CowEntity(
            EntityUuid::random(),
            1,
            'friction-test',
            new Position(0.5, 64.0, 0.5),
            motion: new EntityMotion(0.05, 0.0, 0.0),
        );
        $entity->setOnGround(true);
        $friction = $data->blockPropertyRegistry()->propertiesForState($states->state($support))->friction();
        self::assertSame($friction, $environment->groundFriction($entity));

        (new EntityPhysicsResolver(
            new BlockCollisionQuery($world, $palette->air, [], $shapes),
            $environment,
        ))->tick($entity, 1);

        self::assertEqualsWithDelta(0.05 * (1.0 - $entity->definition()->drag) * $friction, $entity->getMotion()->x, 0.000_001);
    }

    public function testControlledHorseFallsImmediatelyAfterLeavingALoadedLedge(): void
    {
        $platform = new AxisAlignedBox(-10.0, 63.0, -10.0, 1.0, 64.0, 10.0);
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
            new EntityPhysicsResolver(new class ($platform) implements LoadedCollisionBoxQuery {
                public function __construct(private readonly AxisAlignedBox $platform) {}

                public function boxesIntersecting(AxisAlignedBox $area): array
                {
                    return $this->boxesIntersectingLoaded($area);
                }

                public function hasCollision(AxisAlignedBox $area): bool
                {
                    return $this->boxesIntersectingLoaded($area) !== [];
                }

                public function boxesIntersectingLoaded(AxisAlignedBox $area): array
                {
                    return $this->platform->intersects($area) ? [$this->platform] : [];
                }
            }),
        );
        $entity = $runtime->spawn(new EntitySpawnRequest(
            VanillaEntityType::HORSE,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(HorseEntity::class, $entity);
        $entity->setOnGround(true);

        for ($tick = 1; $tick <= 15; ++$tick) {
            $entity->suppressAiMovementUntil($tick + 2);
            $entity->applyControlledMotion(new EntityMotion(0.22, $entity->getMotion()->y, 0.0), $tick);
            $runtime->tick($tick, self::emptyAiWorld(), false);
        }

        self::assertGreaterThan(1.7, $entity->getPosition()->x);
        self::assertLessThan(64.0, $entity->getPosition()->y);
        self::assertFalse($entity->isOnGround());
        self::assertLessThan(0.0, $entity->getMotion()->y);
    }

    public function testPhysicsFailsClosedAtAnUnloadedChunkEdgeWithoutGeneratingTerrain(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $chunks = new ChunkRepository(4);
        $world = new World(
            new WorldMetadata('entity-edge-test', 0),
            new FlatWorldGenerator($palette),
            $chunks,
        );
        $world->chunk(new ChunkPosition(0, 0));
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
            new EntityPhysicsResolver(new BlockCollisionQuery($world, $palette->air)),
        );
        $spawn = $runtime->spawn(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'entity-edge-test',
            new Position(15.8, 64.0, 0.5),
        ));
        self::assertNotNull($spawn->entity);

        $before = $spawn->entity->getPosition();
        $tick = $runtime->tick(1, new class implements AiWorldView {
            public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
            {
                return [];
            }

            public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
            {
                return null;
            }
        }, false);

        self::assertSame(1, $chunks->count());
        self::assertEquals($before, $spawn->entity->getPosition());
        self::assertSame([], $tick->moved);
        self::assertSame(1, $spawn->entity->ageTicks());
        self::assertSame(1, $tick->runtime->physicsTicked);
    }

    public function testPhysicsUsesCollisionAndDeathIsReportedExactlyOnce(): void
    {
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
            new EntityPhysicsResolver(new class implements LoadedCollisionBoxQuery {
                public function boxesIntersecting(AxisAlignedBox $area): array
                {
                    $floor = new AxisAlignedBox(-100.0, 63.0, -100.0, 100.0, 64.0, 100.0);

                    return $floor->intersects($area) ? [$floor] : [];
                }

                public function hasCollision(AxisAlignedBox $area): bool
                {
                    return $this->boxesIntersecting($area) !== [];
                }

                public function boxesIntersectingLoaded(AxisAlignedBox $area): array
                {
                    return $this->boxesIntersecting($area);
                }
            }),
        );
        $spawn = $runtime->spawn(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 66.0, 0.5),
        ));
        self::assertTrue($spawn->succeeded());
        $entity = $spawn->entity;
        self::assertNotNull($entity);

        $world = new class implements AiWorldView {
            public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
            {
                return [];
            }

            public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
            {
                return null;
            }
        };
        $firstTick = $runtime->tick(1, $world, false);
        self::assertTrue($firstTick->motionChangedFor($entity->getRuntimeId()));
        $sawMovement = $firstTick->moved !== [];
        for ($tick = 2; $tick <= 40; ++$tick) {
            $sawMovement = $runtime->tick($tick, $world, false)->moved !== [] || $sawMovement;
        }
        self::assertTrue($sawMovement);
        self::assertTrue($entity->isOnGround());
        self::assertEqualsWithDelta(64.0, $entity->getPosition()->y, 0.001);

        $damage = $runtime->damage(1, 100.0);
        self::assertTrue($damage?->died);
        self::assertCount(1, $runtime->tick(41, $world, false)->died);
        self::assertCount(0, $runtime->tick(42, $world, false)->died);
    }

    public function testSleepingGroundedMobUsesReducedPhysicsCadence(): void
    {
        $counter = new EntityCollisionQueryCounter();
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
            new EntityPhysicsResolver(new class ($counter) implements LoadedCollisionBoxQuery {
                public function __construct(private readonly EntityCollisionQueryCounter $counter) {}

                public function boxesIntersecting(AxisAlignedBox $area): array
                {
                    return $this->boxesIntersectingLoaded($area);
                }

                public function hasCollision(AxisAlignedBox $area): bool
                {
                    return $this->boxesIntersectingLoaded($area) !== [];
                }

                public function boxesIntersectingLoaded(AxisAlignedBox $area): array
                {
                    ++$this->counter->queries;
                    $floor = new AxisAlignedBox(-100.0, 63.0, -100.0, 100.0, 64.0, 100.0);

                    return $floor->intersects($area) ? [$floor] : [];
                }
            }),
        );
        $spawn = $runtime->spawn(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertNotNull($spawn->entity);
        $world = new class implements AiWorldView {
            public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
            {
                return [];
            }

            public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
            {
                return null;
            }
        };
        $runtime->tick(1, $world, false);
        self::assertTrue($spawn->entity->isOnGround());
        $before = $counter->queries;

        $skipped = $runtime->tick(2, $world, false);
        self::assertSame($before, $counter->queries);
        self::assertSame(1, $skipped->runtime->cadenceSkipped);

        $dueTick = 20 - ($spawn->entity->getRuntimeId() % 20);
        $due = $runtime->tick($dueTick, $world, false);
        self::assertSame($before + 1, $counter->queries);
        self::assertSame(1, $due->runtime->physicsTicked);
    }

    public function testPhysicsBudgetDefersSafeEntitiesButNotFallingEntities(): void
    {
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
            new EntityPhysicsResolver(new class implements LoadedCollisionBoxQuery {
                public function boxesIntersecting(AxisAlignedBox $area): array
                {
                    return [];
                }

                public function hasCollision(AxisAlignedBox $area): bool
                {
                    return false;
                }

                public function boxesIntersectingLoaded(AxisAlignedBox $area): array
                {
                    return [];
                }
            }),
        );
        foreach ([0.5, 2.5] as $x) {
            self::assertTrue($runtime->spawn(new EntitySpawnRequest(
                VanillaEntityType::COW,
                SpawnCause::COMMAND,
                'world',
                new Position($x, 70.0, 0.5),
            ))->succeeded());
        }
        $world = new class implements AiWorldView {
            public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
            {
                return [];
            }

            public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
            {
                return null;
            }
        };

        $tick = $runtime->tick(1, $world, false, entityBudget: new EntityWorkBudget(1, 50_000_000));
        self::assertSame(2, $tick->runtime->physicsTicked);
        self::assertSame(1, $tick->runtime->continuousBeyondBudget);
        self::assertTrue($tick->runtime->budgetExhausted);
    }

    public function testDeferredPhysicsRotatesFairlyAcrossSafeEntities(): void
    {
        $counter = new EntityCollisionQueryCounter();
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
            new EntityPhysicsResolver(new class ($counter) implements LoadedCollisionBoxQuery {
                public function __construct(private readonly EntityCollisionQueryCounter $counter) {}

                public function boxesIntersecting(AxisAlignedBox $area): array
                {
                    return $this->boxesIntersectingLoaded($area);
                }

                public function hasCollision(AxisAlignedBox $area): bool
                {
                    return $this->boxesIntersectingLoaded($area) !== [];
                }

                public function boxesIntersectingLoaded(AxisAlignedBox $area): array
                {
                    $this->counter->minimumXs[] = $area->minX;
                    $floor = new AxisAlignedBox(-100.0, 63.0, -100.0, 100.0, 64.0, 100.0);

                    return $floor->intersects($area) ? [$floor] : [];
                }
            }),
        );
        foreach ([0.5, 10.5] as $x) {
            self::assertTrue($runtime->spawn(new EntitySpawnRequest(
                VanillaEntityType::COW,
                SpawnCause::COMMAND,
                'world',
                new Position($x, 64.0, 0.5),
            ))->succeeded());
        }
        $world = new class implements AiWorldView {
            public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
            {
                return [];
            }

            public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): float
            {
                return 0.0;
            }
        };
        $budget = new EntityWorkBudget(1, 50_000_000);
        $runtime->tick(1, $world, false, entityBudget: $budget);

        $counter->minimumXs = [];
        $runtime->tick(2, $world, false, entityBudget: $budget);
        self::assertCount(1, $counter->minimumXs);
        $first = $counter->firstMinimumX();

        $counter->minimumXs = [];
        $runtime->tick(3, $world, false, entityBudget: $budget);
        self::assertCount(1, $counter->minimumXs);
        self::assertNotSame($first, $counter->firstMinimumX());
    }

    public function testAiSteeringCannotEraseAnActiveExternalImpulse(): void
    {
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime($registry, new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()));
        $spawn = $runtime->spawn(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(AbstractMobEntity::class, $spawn->entity);
        $entity = $spawn->entity;
        $impulse = new \Bedriox\Server\Entity\EntityMotion(0.4, 0.4, 0.0);
        $entity->setMotion($impulse);
        $entity->suppressAiMovementUntil(10);

        HorizontalSteering::toward($entity, new Position(-10.0, 64.0, 0.5), 0.11, 5);
        self::assertEquals($impulse, $entity->getMotion());

        HorizontalSteering::toward($entity, new Position(-10.0, 64.0, 0.5), 0.11, 10);
        self::assertLessThan(0.0, $entity->getMotion()->x);
    }

    public function testGroundMobJumpsOntoOneBlockRiseButNotThroughTwoBlockWall(): void
    {
        $oneBlock = self::obstacleRuntime(new AxisAlignedBox(1.0, 64.0, -1.0, 10.0, 65.0, 1.0));
        $climber = $oneBlock[1];
        $highestY = $climber->getPosition()->y;
        for ($tick = 1; $tick <= 30; ++$tick) {
            HorizontalSteering::motion($climber, 0.2, 0.0, $tick);
            $oneBlock[0]->tick($tick, self::emptyAiWorld(), false);
            $highestY = max($highestY, $climber->getPosition()->y);
        }
        self::assertGreaterThan(64.5, $highestY);
        self::assertGreaterThan(1.0, $climber->getPosition()->x);

        $twoBlocks = self::obstacleRuntime(new AxisAlignedBox(1.0, 64.0, -1.0, 10.0, 66.0, 1.0));
        $blocked = $twoBlocks[1];
        for ($tick = 1; $tick <= 15; ++$tick) {
            HorizontalSteering::motion($blocked, 0.2, 0.0, $tick);
            $twoBlocks[0]->tick($tick, self::emptyAiWorld(), false);
        }
        self::assertEqualsWithDelta(64.0, $blocked->getPosition()->y, 0.001);
        self::assertLessThan(1.0, $blocked->getPosition()->x);
    }

    public function testRiderControlledMobUsesTheSameObstacleAwareJumpPath(): void
    {
        [$runtime, $pig] = self::obstacleRuntime(new AxisAlignedBox(1.0, 64.0, -1.0, 10.0, 65.0, 1.0));
        $highestY = $pig->getPosition()->y;
        for ($tick = 1; $tick <= 30; ++$tick) {
            $pig->suppressAiMovementUntil($tick + 2);
            $pig->applyControlledMotion(new EntityMotion(0.2, $pig->getMotion()->y, 0.0), $tick);
            $runtime->tick($tick, self::emptyAiWorld(), false);
            $highestY = max($highestY, $pig->getPosition()->y);
        }

        self::assertGreaterThan(64.5, $highestY);
        self::assertGreaterThan(1.0, $pig->getPosition()->x);
    }

    public function testControlledBoatCannotUseMobJumpingToClimbAFullBlock(): void
    {
        [$runtime, $boat] = self::obstacleRuntime(
            new AxisAlignedBox(2.0, 64.0, -1.0, 10.0, 65.0, 1.0),
            VanillaEntityType::BOAT,
        );
        for ($tick = 1; $tick <= 30; ++$tick) {
            $boat->suppressAiMovementUntil($tick + 2);
            $boat->applyControlledMotion(new EntityMotion(0.2, $boat->getMotion()->y, 0.0), $tick);
            $runtime->tick($tick, self::emptyAiWorld(), false);
        }

        self::assertEqualsWithDelta(64.0, $boat->getPosition()->y, 0.001);
        self::assertLessThan(1.31, $boat->getPosition()->x);
    }

    public function testControlledBoatStaysOnTopOfADeepLoadedWaterColumn(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $water = $generation->state('minecraft:water');
        $world = new World(
            new WorldMetadata('boat-water-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world->chunk(new ChunkPosition(0, 0));
        for ($z = 0; $z <= 15; ++$z) {
            for ($x = 0; $x <= 15; ++$x) {
                for ($y = 61; $y <= 64; ++$y) {
                    $world->setBlockState($x, $y, $z, $water);
                }
            }
        }
        $shapes = BlockCollisionRegistry::forGenerationPalette($states, $generation);
        $environment = new WorldEntityEnvironment($world, $states, $shapes, $palette->air, $water);
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
            new EntityPhysicsResolver(
                new BlockCollisionQuery($world, $palette->air, [$water], $shapes),
                $environment,
            ),
        );
        $spawn = $runtime->spawn(new EntitySpawnRequest(
            VanillaEntityType::BOAT,
            SpawnCause::COMMAND,
            'boat-water-test',
            new Position(1.5, 62.1, 1.5),
        ));
        self::assertInstanceOf(BoatEntity::class, $spawn->entity);
        $surface = $environment->waterSurfaceY($spawn->entity);
        self::assertNotNull($surface);

        $expectedY = BoatEntity::floatingPositionY($surface);
        for ($tick = 1; $tick <= 40; ++$tick) {
            $spawn->entity->suppressAiMovementUntil($tick + 2);
            $spawn->entity->applyControlledMotion(new EntityMotion(0.2, -0.4, 0.0), $tick);
            $runtime->tick($tick, self::emptyAiWorld(), false);
            self::assertEqualsWithDelta($expectedY, $spawn->entity->internalPosition()->y, 0.000_001);
        }

        self::assertSame(0.0, $spawn->entity->getMotion()->y);
        self::assertGreaterThan(5.0, $spawn->entity->internalPosition()->x);
    }

    public function testGhastRecoversAltitudeBeforeReachingNearbyGround(): void
    {
        $floor = new AxisAlignedBox(-100.0, 60.0, -100.0, 100.0, 61.0, 100.0);
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
            new EntityPhysicsResolver(new class ($floor) implements LoadedCollisionBoxQuery {
                public function __construct(private readonly AxisAlignedBox $floor) {}

                public function boxesIntersecting(AxisAlignedBox $area): array
                {
                    return $this->boxesIntersectingLoaded($area);
                }

                public function hasCollision(AxisAlignedBox $area): bool
                {
                    return $this->boxesIntersectingLoaded($area) !== [];
                }

                public function boxesIntersectingLoaded(AxisAlignedBox $area): array
                {
                    return $this->floor->intersects($area) ? [$this->floor] : [];
                }
            }),
        );
        $spawn = $runtime->spawn(new EntitySpawnRequest(
            VanillaEntityType::GHAST,
            SpawnCause::COMMAND,
            'nether',
            new Position(0.0, 64.0, 0.0),
        ));
        self::assertInstanceOf(AbstractMobEntity::class, $spawn->entity);
        $spawn->entity->setMotion(new EntityMotion(0.0, -0.2, 0.0));

        $runtime->tick(1, self::emptyAiWorld(), false);

        self::assertGreaterThan(64.0, $spawn->entity->internalPosition()->y);
        self::assertGreaterThan(0.0, $spawn->entity->getMotion()->y);
    }

    /** @return array{EntityWorldRuntime, AbstractMobEntity} */
    private static function obstacleRuntime(
        AxisAlignedBox $obstacle,
        VanillaEntityType $type = VanillaEntityType::ZOMBIE,
    ): array {
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
            new EntityPhysicsResolver(new class ($obstacle) implements LoadedCollisionBoxQuery {
                public function __construct(private readonly AxisAlignedBox $obstacle) {}

                public function boxesIntersecting(AxisAlignedBox $area): array
                {
                    return $this->boxesIntersectingLoaded($area);
                }

                public function hasCollision(AxisAlignedBox $area): bool
                {
                    return $this->boxesIntersecting($area) !== [];
                }

                public function boxesIntersectingLoaded(AxisAlignedBox $area): array
                {
                    $boxes = [];
                    foreach ([
                        new AxisAlignedBox(-100.0, 63.0, -100.0, 100.0, 64.0, 100.0),
                        $this->obstacle,
                    ] as $box) {
                        if ($box->intersects($area)) {
                            $boxes[] = $box;
                        }
                    }

                    return $boxes;
                }
            }),
        );
        $spawn = $runtime->spawn(new EntitySpawnRequest(
            $type,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.0),
        ));
        self::assertInstanceOf(AbstractMobEntity::class, $spawn->entity);
        $spawn->entity->setOnGround(true);

        return [$runtime, $spawn->entity];
    }

    public function testOverlappingEntitiesReceiveBoundedSymmetricContactMotion(): void
    {
        $registry = new EntityRegistry();
        $runtime = new EntityWorldRuntime(
            $registry,
            new EntitySpawnService($registry, EntityDefinitionRegistry::baseline()),
        );
        $first = $runtime->spawn(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ))->entity;
        $second = $runtime->spawn(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ))->entity;
        self::assertNotNull($first);
        self::assertNotNull($second);

        $tick = $runtime->tick(1, self::emptyAiWorld(), false);

        self::assertSame(1, $tick->runtime->contactsResolved);
        self::assertGreaterThan(0.0, $first->getMotion()->lengthSquared());
        self::assertEqualsWithDelta(-$first->getMotion()->x, $second->getMotion()->x, 0.000_001);
        self::assertEqualsWithDelta(-$first->getMotion()->z, $second->getMotion()->z, 0.000_001);
        self::assertSame(0.0, $first->getMotion()->y);
        self::assertLessThanOrEqual(0.05 ** 2, $first->getMotion()->lengthSquared());
    }

    private static function emptyAiWorld(): AiWorldView
    {
        return new class implements AiWorldView {
            public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
            {
                return [];
            }

            public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
            {
                return null;
            }
        };
    }
}

final class EntityCollisionQueryCounter
{
    public int $queries = 0;

    /** @var list<float> */
    public array $minimumXs = [];

    public function firstMinimumX(): float
    {
        return $this->minimumXs[0] ?? throw new \LogicException('No collision query was recorded.');
    }
}
