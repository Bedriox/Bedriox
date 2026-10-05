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

use Bedriox\Api\Entity\Capability\Aquatic;
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\Goal\AquaticChasePlayerGoal;
use Bedriox\Server\Entity\Ai\Goal\AquaticWanderGoal;
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\Ai\IndexedAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\AquaticBucketRegistry;
use Bedriox\Server\Entity\AquaticRuntimeState;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\Vanilla\TurtleEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AquaticEntitySystemTest extends TestCase
{
    /** @return iterable<string, array{VanillaEntityType, EntityCategory}> */
    public static function aquaticTypes(): iterable
    {
        foreach ([
            VanillaEntityType::COD,
            VanillaEntityType::SALMON,
            VanillaEntityType::TROPICAL_FISH,
            VanillaEntityType::PUFFERFISH,
            VanillaEntityType::SQUID,
            VanillaEntityType::GLOW_SQUID,
            VanillaEntityType::DOLPHIN,
            VanillaEntityType::TURTLE,
            VanillaEntityType::AXOLOTL,
        ] as $type) {
            yield $type->name => [$type, EntityCategory::WATER];
        }
        yield 'DROWNED' => [VanillaEntityType::DROWNED, EntityCategory::MONSTER];
        yield 'GUARDIAN' => [VanillaEntityType::GUARDIAN, EntityCategory::MONSTER];
    }

    #[DataProvider('aquaticTypes')]
    public function testAquaticDefinitionsProduceTypedRuntimeEntities(
        VanillaEntityType $type,
        EntityCategory $category,
    ): void {
        $registration = EntityDefinitionRegistry::baseline()->require($type);
        $entity = ($registration->factory)('00000000-0000-4000-8000-000000000050', 50, 'world', new Position(0.5, 62.0, 0.5), 0.0, 0.0);

        self::assertSame($category, $registration->definition->category);
        self::assertInstanceOf(Aquatic::class, $entity);
        self::assertSame($type, $entity->getType());
    }

    public function testAquaticAirAndDryStateRemainBounded(): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::COD);
        $entity = ($registration->factory)('00000000-0000-4000-8000-000000000051', 51, 'world', new Position(0.5, 62.0, 0.5), 0.0, 0.0);
        self::assertInstanceOf(AquaticRuntimeState::class, $entity);

        for ($tick = 0; $tick < 10_000; ++$tick) {
            $entity->advanceAquaticState(false);
        }
        self::assertSame(10_000, $entity->getDryTicks());
        self::assertSame($entity->getMaximumAirSupplyTicks(), $entity->getAirSupplyTicks());

        for ($tick = 0; $tick < 10_000; ++$tick) {
            $entity->advanceAquaticState(true);
        }
        self::assertSame(0, $entity->getDryTicks());
        self::assertSame($entity->getMaximumAirSupplyTicks(), $entity->getAirSupplyTicks());
    }

    public function testAquaticBucketMappingsAreSymmetric(): void
    {
        self::assertSame(VanillaEntityType::COD, AquaticBucketRegistry::typeForBucket('minecraft:cod_bucket'));
        self::assertSame('minecraft:cod_bucket', AquaticBucketRegistry::bucketForType(VanillaEntityType::COD));
        self::assertNull(AquaticBucketRegistry::typeForBucket('minecraft:water_bucket'));
        self::assertNull(AquaticBucketRegistry::bucketForType(VanillaEntityType::DOLPHIN));
    }

    public function testAquaticBreedingStateSurvivesItsIntrinsicPersistencePayload(): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::TURTLE);
        $original = ($registration->factory)('00000000-0000-4000-8000-000000000052', 52, 'world', new Position(0.5, 62.0, 0.5), 0.0, 0.0);
        $restored = ($registration->factory)('00000000-0000-4000-8000-000000000053', 53, 'world', new Position(0.5, 62.0, 0.5), 0.0, 0.0);
        self::assertInstanceOf(TurtleEntity::class, $original);
        self::assertInstanceOf(TurtleEntity::class, $restored);
        self::assertInstanceOf(IntrinsicEntityPersistence::class, $original);
        self::assertInstanceOf(IntrinsicEntityPersistence::class, $restored);
        $original->setBaby(true);
        $restored->restorePersistenceState(
            $original->persistenceVariant(),
            $original->persistenceSchemaVersion(),
            $original->persistenceData(),
        );
        self::assertTrue($restored->isBaby());
    }

    public function testDenseAquaticStateProgressionRemainsPerEntityAndBounded(): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::SALMON);
        $entities = [];
        for ($index = 0; $index < 256; ++$index) {
            $entity = ($registration->factory)(
                sprintf('00000000-0000-4000-8000-%012d', 100 + $index),
                100 + $index,
                'world',
                new Position(0.5, 62.0, 0.5),
                0.0,
                0.0,
            );
            self::assertInstanceOf(AquaticRuntimeState::class, $entity);
            $entity->advanceAquaticState(false);
            $entities[] = $entity;
        }
        self::assertSame(1, $entities[0]->getDryTicks());
        self::assertSame(1, $entities[255]->getDryTicks());
    }

    public function testAquaticWanderFacesItsActualThreeDimensionalMotion(): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::SALMON);
        $entity = ($registration->factory)(
            '00000000-0000-4000-8000-000000000550',
            550,
            'world',
            new Position(0.5, 62.0, 0.5),
            180.0,
            0.0,
        );
        self::assertInstanceOf(AbstractMobEntity::class, $entity);
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [],
            waterAt: static fn(string $worldName, Position $position): bool => $worldName === 'world'
                && $position->y >= 50.0
                && $position->y <= 80.0,
            touchingWater: static fn(AbstractMobEntity $_entity): bool => true,
        );
        $goal = new AquaticWanderGoal('test:swim', speed: 0.1);
        $memory = new AiMemoryStore();
        $context = new AiTickContext(10, $view);

        $goal->start($entity, $memory, $context);
        $goal->tick($entity, $memory, $context);

        $motion = $entity->getMotion();
        self::assertGreaterThan(0.0, hypot($motion->x, $motion->z));
        $yaw = deg2rad($entity->getYaw());
        self::assertGreaterThan(0.0, ((-$motion->x) * sin($yaw)) + ($motion->z * cos($yaw)));
        self::assertNotSame(0.0, $entity->getPitch());
    }

    public function testDrownedUsesHorizontalNavigationWhenItIsOutOfWater(): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::DROWNED);
        $entity = ($registration->factory)(
            '00000000-0000-4000-8000-000000000551',
            551,
            'world',
            new Position(0.5, 62.0, 0.5),
            0.0,
            20.0,
        );
        self::assertInstanceOf(AbstractMobEntity::class, $entity);
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [],
            waterAt: static fn(string $_worldName, Position $_position): bool => false,
            touchingWater: static fn(AbstractMobEntity $_entity): bool => false,
        );
        $goal = new AquaticWanderGoal('test:drowned_walk', speed: 0.1);
        $memory = new AiMemoryStore();
        $context = new AiTickContext(10, $view);

        $goal->start($entity, $memory, $context);
        $goal->tick($entity, $memory, $context);

        self::assertSame(0.0, $entity->getMotion()->y);
        self::assertSame(0.0, $entity->getPitch());
        self::assertGreaterThan(0.0, hypot($entity->getMotion()->x, $entity->getMotion()->z));
    }

    public function testGuardianChaseUsesSmoothThreeDimensionalFacing(): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::GUARDIAN);
        $entity = ($registration->factory)(
            '00000000-0000-4000-8000-000000000552',
            552,
            'world',
            new Position(0.5, 55.0, 0.5),
            180.0,
            0.0,
        );
        self::assertInstanceOf(AbstractMobEntity::class, $entity);
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [],
            waterAt: static fn(string $_worldName, Position $_position): bool => true,
            touchingWater: static fn(AbstractMobEntity $_entity): bool => true,
        );
        $memory = new AiMemoryStore();
        $memory->put(
            VanillaAiMemories::nearestPlayer(),
            new AiPlayerSnapshot('target', 'world', new Position(4.5, 58.0, 6.5)),
        );
        $goal = new AquaticChasePlayerGoal('test:guardian_chase', 80, 2.0, 0.12);
        $context = new AiTickContext(20, $view);

        $goal->start($entity, $memory, $context);

        $motion = $entity->getMotion();
        self::assertGreaterThan(0.0, sqrt(($motion->x ** 2) + ($motion->y ** 2) + ($motion->z ** 2)));
        self::assertLessThan(0.0, $entity->getPitch());
        $yaw = deg2rad($entity->getYaw());
        self::assertGreaterThan(0.0, ((-$motion->x) * sin($yaw)) + ($motion->z * cos($yaw)));
    }

    /** @return iterable<string, array{VanillaEntityType}> */
    public static function aquaticHostileTypes(): iterable
    {
        yield 'drowned' => [VanillaEntityType::DROWNED];
        yield 'guardian' => [VanillaEntityType::GUARDIAN];
    }

    #[DataProvider('aquaticHostileTypes')]
    public function testAquaticHostilesKeepSteeringWhileAPlayerIsInsideAttackReach(VanillaEntityType $type): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require($type);
        $entity = ($registration->factory)(
            '00000000-0000-4000-8000-000000000553',
            553,
            'world',
            new Position(0.5, 55.0, 0.5),
            0.0,
            0.0,
        );
        self::assertInstanceOf(AbstractMobEntity::class, $entity);
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [new AiPlayerSnapshot(
                'close-target',
                'world',
                new Position(1.5, 55.0, 0.5),
            )],
            waterAt: static fn(string $_worldName, Position $_position): bool => true,
            touchingWater: static fn(AbstractMobEntity $_entity): bool => true,
        );

        $moved = false;
        for ($tick = 1; $tick <= 30; ++$tick) {
            $entity->tickAi(new AiTickContext($tick, $view), true);
            $motion = $entity->getMotion();
            $moved = $moved || hypot($motion->x, $motion->z) > 0.000_001;
        }

        self::assertTrue($moved, $type->value . ' remained frozen while its attack goal was active.');
    }

    #[DataProvider('aquaticHostileTypes')]
    public function testAquaticHostilesSteerDownFromTheWaterSurface(VanillaEntityType $type): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require($type);
        $entity = ($registration->factory)(
            '00000000-0000-4000-8000-000000000554',
            554,
            'world',
            new Position(0.5, 55.0, 0.5),
            0.0,
            0.0,
        );
        self::assertInstanceOf(AbstractMobEntity::class, $entity);
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [],
            waterAt: static fn(string $_worldName, Position $position): bool => $position->y < 55.2,
            touchingWater: static fn(AbstractMobEntity $_entity): bool => true,
        );
        $goal = new AquaticWanderGoal('test:shallow_water', speed: 0.1);
        $memory = new AiMemoryStore();
        $context = new AiTickContext(10, $view);

        $goal->start($entity, $memory, $context);
        $goal->tick($entity, $memory, $context);

        self::assertLessThan(0.0, $entity->getMotion()->y);
    }

    public function testWaterOnlyFishStopsPropellingItselfWhenDisplacedOntoLand(): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::SALMON);
        $entity = ($registration->factory)(
            '00000000-0000-4000-8000-000000000556',
            556,
            'world',
            new Position(0.5, 64.0, 0.5),
            0.0,
            0.0,
        );
        self::assertInstanceOf(AbstractMobEntity::class, $entity);
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [],
            waterAt: static fn(string $_worldName, Position $_position): bool => false,
            touchingWater: static fn(AbstractMobEntity $_entity): bool => false,
        );
        $goal = new AquaticWanderGoal('test:dry_fish', speed: 0.1);
        $memory = new AiMemoryStore();
        $context = new AiTickContext(10, $view);

        $goal->start($entity, $memory, $context);
        $entity->setMotion(new EntityMotion(0.1, 0.0, 0.1));
        $goal->tick($entity, $memory, $context);

        self::assertSame(0.0, $entity->getMotion()->x);
        self::assertSame(0.0, $entity->getMotion()->z);
    }

    public function testLandMobSteeringStopsBeforeWaterAndSubmersionConsumesAir(): void
    {
        $registration = EntityDefinitionRegistry::baseline()->require(VanillaEntityType::SHEEP);
        $entity = ($registration->factory)(
            '00000000-0000-4000-8000-000000000555',
            555,
            'world',
            new Position(0.5, 64.0, 0.5),
            0.0,
            0.0,
        );
        self::assertInstanceOf(AbstractMobEntity::class, $entity);
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [],
            waterAt: static fn(string $_worldName, Position $position): bool => $position->x > 0.75,
            touchingWater: static fn(AbstractMobEntity $_entity): bool => false,
        );

        HorizontalSteering::toward($entity, new Position(5.0, 64.0, 0.5), 0.1, 1, $view);
        self::assertSame(0.0, $entity->getMotion()->x);
        self::assertSame(0.0, $entity->getMotion()->z);

        for ($tick = 0; $tick < AbstractLivingEntity::MAXIMUM_AIR_SUPPLY_TICKS; ++$tick) {
            $entity->advanceBreathingState(true, false);
        }
        self::assertSame(0, $entity->getBreathingAirSupplyTicks());
        $entity->advanceBreathingState(false, false);
        self::assertSame(4, $entity->getBreathingAirSupplyTicks());
    }

}
