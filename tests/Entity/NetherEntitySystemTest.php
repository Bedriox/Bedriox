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

use Bedriox\Api\Entity\Capability\FireImmune;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\Goal\FlyingWanderGoal;
use Bedriox\Server\Entity\Ai\Sensor\PiglinNearestPlayerSensor;
use Bedriox\Server\Entity\Ai\TargetAwareAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Entity\Vanilla\Nether\BlazeEntity;
use Bedriox\Server\Entity\Vanilla\Nether\GhastEntity;
use Bedriox\Server\Entity\Vanilla\Nether\HappyGhastEntity;
use Bedriox\Server\Entity\Vanilla\Nether\HoglinEntity;
use Bedriox\Server\Entity\Vanilla\Nether\PiglinEntity;
use Bedriox\Server\Entity\Vanilla\Nether\StriderEntity;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Gameplay\Projectile\Projectile;
use Bedriox\Server\Gameplay\Projectile\ProjectileOwnerType;
use Bedriox\Server\Gameplay\Projectile\ProjectileType;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class NetherEntitySystemTest extends TestCase
{
    public function testHappyGhastSeatsAreAboveAndDistributedAroundItsHarness(): void
    {
        $ghast = new HappyGhastEntity(self::uuid(1), 1, 'world', new Position(0.0, 64.0, 0.0));
        $ghast->setHarnessed(true);

        $driver = $ghast->mountedPassengerOffset(MountSeat::DRIVER, 1.8, true);
        $right = $ghast->mountedPassengerOffset(MountSeat::PASSENGER_1, 1.8, true);
        $rear = $ghast->mountedPassengerOffset(MountSeat::PASSENGER_2, 1.8, true);
        $left = $ghast->mountedPassengerOffset(MountSeat::PASSENGER_3, 1.8, true);

        self::assertGreaterThan($ghast->collisionHeight(), $driver->y);
        self::assertEqualsWithDelta(4.92, $driver->y, 0.01);
        self::assertSame([0.0, -1.35], [$driver->x, $driver->z]);
        self::assertSame([1.35, 0.0], [$right->x, $right->z]);
        self::assertSame([0.0, 1.35], [$rear->x, $rear->z]);
        self::assertSame([-1.35, 0.0], [$left->x, $left->z]);
    }

    public function testNetherCatalogUsesCanonicalFactoriesAndFlightPhysics(): void
    {
        $registry = EntityDefinitionRegistry::baseline();
        foreach ([
            VanillaEntityType::BLAZE,
            VanillaEntityType::GHAST,
            VanillaEntityType::HAPPY_GHAST,
            VanillaEntityType::HOGLIN,
            VanillaEntityType::PIGLIN,
            VanillaEntityType::PIGLIN_BRUTE,
            VanillaEntityType::STRIDER,
            VanillaEntityType::ZOGLIN,
            VanillaEntityType::ZOMBIFIED_PIGLIN,
        ] as $type) {
            self::assertSame($type, $registry->require($type)->definition->type);
        }
        self::assertSame(0.0, VanillaEntityDefinitions::blaze()->gravity);
        self::assertSame(0.0, VanillaEntityDefinitions::ghast()->gravity);
        self::assertInstanceOf(FireImmune::class, new BlazeEntity(self::uuid(1), 1, 'nether', new Position(0.0, 64.0, 0.0)));
        self::assertInstanceOf(FireImmune::class, new GhastEntity(self::uuid(2), 2, 'nether', new Position(0.0, 64.0, 0.0)));
    }

    public function testExplicitSpawnsUseTheCanonicalNetherDefinitions(): void
    {
        $entities = new EntityRegistry();
        $spawns = new EntitySpawnService($entities, EntityDefinitionRegistry::baseline());

        foreach ([VanillaEntityType::GHAST, VanillaEntityType::STRIDER, VanillaEntityType::HAPPY_GHAST] as $type) {
            $outcome = $spawns->spawn(new EntitySpawnRequest(
                $type,
                SpawnCause::COMMAND,
                'nether',
                new Position(0.5, 80.0, 0.5),
            ));

            self::assertTrue($outcome->succeeded(), $type->value . ': ' . ($outcome->failure ?? 'unknown'));
        }
    }

    public function testStatefulNetherEntitiesRoundTripTheirIntrinsicState(): void
    {
        $piglin = new PiglinEntity(self::uuid(3), 3, 'nether', new Position(0.0, 64.0, 0.0));
        $piglin->beginAdmiring();
        $piglin->setAngerTargetUniqueId(self::uuid(4), 500);
        $copy = new PiglinEntity(self::uuid(5), 5, 'nether', new Position(0.0, 64.0, 0.0));
        $copy->restorePersistenceState(null, $piglin->persistenceSchemaVersion(), $piglin->persistenceData());
        self::assertTrue($copy->isAdmiring());
        self::assertSame(self::uuid(4), $copy->getAngerTargetUniqueId());

        $strider = new StriderEntity(self::uuid(6), 6, 'nether', new Position(0.0, 32.0, 0.0));
        $strider->setSaddled(true);
        self::assertSame(1, $strider->getSeatCapacity());

        $happyGhast = new HappyGhastEntity(self::uuid(7), 7, 'nether', new Position(0.0, 64.0, 0.0));
        $happyGhast->setHarnessed(true);
        self::assertSame(4, $happyGhast->getSeatCapacity());

        $hoglin = new HoglinEntity(self::uuid(8), 8, 'nether', new Position(0.0, 64.0, 0.0));
        for ($tick = 1; $tick < HoglinEntity::ZOMBIFICATION_TICKS; ++$tick) {
            self::assertFalse($hoglin->advanceZombification(true));
        }
        self::assertTrue($hoglin->advanceZombification(true));
    }

    public function testFlyingWanderPreservesItsConfiguredSpeed(): void
    {
        $ghast = new HappyGhastEntity(self::uuid(20), 20, 'nether', new Position(0.0, 64.0, 0.0));
        $memory = new AiMemoryStore();
        $context = new AiTickContext(20, new NetherTargetWorldView(null));
        $goal = new FlyingWanderGoal('bedriox:test_flying_wander', speed: 0.06);

        $goal->start($ghast, $memory, $context);
        $goal->tick($ghast, $memory, $context);

        $motion = $ghast->getMotion();
        self::assertEqualsWithDelta(0.06, sqrt(
            ($motion->x ** 2) + ($motion->y ** 2) + ($motion->z ** 2),
        ), 0.000_001);
    }

    public function testReflectedFireballChangesAuthorityAndDirection(): void
    {
        $projectile = new Projectile(
            1,
            2,
            self::uuid(9),
            PotionType::WATER,
            false,
            new Position(0.0, 64.0, 0.0),
            new EntityMotion(0.0, 0.0, 1.0),
            type: ProjectileType::FIREBALL,
            ownerRuntimeEntityId: 9,
            ownerType: ProjectileOwnerType::ENTITY,
        );
        $reflected = $projectile->reflectedBy(self::uuid(10), 10, new EntityMotion(0.0, 0.1, -1.0));

        self::assertSame(self::uuid(10), $reflected->ownerUuid);
        self::assertSame(10, $reflected->ownerRuntimeEntityId);
        self::assertSame(ProjectileOwnerType::PLAYER, $reflected->ownerType);
        self::assertLessThan(0.0, $reflected->motion->z);
        self::assertSame([], $reflected->hitActorKeys);
    }

    public function testPiglinSensorRespectsGoldArmorNeutrality(): void
    {
        $piglin = new PiglinEntity(self::uuid(11), 11, 'nether', new Position(0.0, 64.0, 0.0));
        $memory = new AiMemoryStore();
        $sensor = new PiglinNearestPlayerSensor();
        $world = new NetherTargetWorldView(new AiPlayerSnapshot(
            self::uuid(12),
            'nether',
            new Position(2.0, 64.0, 0.0),
            wearingGoldArmor: true,
        ));
        $sensor->sense($piglin, $memory, new AiTickContext(10, $world));
        self::assertNull($memory->get(VanillaAiMemories::nearestPlayer(), 10));

        $world->target = new AiPlayerSnapshot(self::uuid(12), 'nether', new Position(2.0, 64.0, 0.0));
        $sensor->sense($piglin, $memory, new AiTickContext(20, $world));
        self::assertInstanceOf(
            AiPlayerSnapshot::class,
            $memory->get(VanillaAiMemories::nearestPlayer(), 20),
        );

        $piglin->aiRuntime()->memory()->put(VanillaAiMemories::nearestPlayer(), $world->target, 40);
        $piglin->setMotion(new EntityMotion(0.2, 0.0, 0.1));
        $piglin->beginAdmiring();
        self::assertNull($piglin->aiRuntime()->memory()->get(VanillaAiMemories::nearestPlayer(), 20));
        self::assertEquals(new EntityMotion(), $piglin->getMotion());

        $memory->put(VanillaAiMemories::nearestPlayer(), $world->target, 40);
        $sensor->sense($piglin, $memory, new AiTickContext(30, $world));
        self::assertNull($memory->get(VanillaAiMemories::nearestPlayer(), 30));
    }

    private static function uuid(int $suffix): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $suffix);
    }
}

final class NetherTargetWorldView implements TargetAwareAiWorldView
{
    public function __construct(public ?AiPlayerSnapshot $target) {}

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return [];
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        return $this->target?->distanceSquaredTo($entity->internalPosition());
    }

    public function nearestPlayer(AbstractMobEntity $entity, float $radius): ?AiPlayerSnapshot
    {
        if ($this->target === null || $this->target->worldName !== $entity->getWorldName()
            || $this->target->distanceSquaredTo($entity->internalPosition()) > $radius ** 2) {
            return null;
        }

        return $this->target;
    }

    public function nearestPlayerHolding(
        AbstractMobEntity $entity,
        float $radius,
        array $itemIdentifiers,
    ): ?AiPlayerSnapshot {
        $target = $this->nearestPlayer($entity, $radius);
        return $target !== null && in_array($target->heldItemIdentifier, $itemIdentifiers, true)
            ? $target
            : null;
    }
}
