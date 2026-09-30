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

namespace Bedriox\Server\Tests\Gameplay\Projectile;

use Bedriox\Api\Potion\PotionType;
use Bedriox\Api\World\BlockFace;
use Bedriox\Server\Gameplay\Projectile\Projectile;
use Bedriox\Server\Gameplay\Projectile\ProjectileRegistry;
use Bedriox\Server\Gameplay\Projectile\ProjectileState;
use Bedriox\Server\Gameplay\Projectile\ProjectileType;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Projectile::class)]
#[CoversClass(ProjectileRegistry::class)]
final class ProjectileRegistryTest extends TestCase
{
    public function testSpawnUsesViewRotationAndTickAppliesDragAndGravity(): void
    {
        $registry = new ProjectileRegistry(firstEntityId: 900);
        $spawned = $registry->spawn('owner', PotionType::SWIFTNESS, false, new Position(1.0, 65.0, 2.0), 0.0, 0.0);

        self::assertSame(900, $spawned->runtimeEntityId);
        self::assertEqualsWithDelta(0.0, $spawned->motion->x, 0.000001);
        self::assertEqualsWithDelta(0.0, $spawned->motion->y, 0.000001);
        self::assertEqualsWithDelta(0.5, $spawned->motion->z, 0.000001);

        $result = $registry->tick();
        self::assertCount(1, $result->updated);
        self::assertEqualsWithDelta(-0.05, $result->updated[0]->motion->y, 0.000001);
        self::assertEqualsWithDelta(0.495, $result->updated[0]->motion->z, 0.000001);
        self::assertSame(1, $result->updated[0]->ageTicks);
    }

    public function testCapacityAndLifetimeStayBounded(): void
    {
        $registry = new ProjectileRegistry(1);
        $registry->spawn('owner', PotionType::WATER, true, new Position(0.0, 64.0, 0.0), 0.0, 0.0);

        $this->expectException(OverflowException::class);
        $registry->spawn('owner', PotionType::WATER, false, new Position(0.0, 64.0, 0.0), 0.0, 0.0);
    }

    public function testProjectileExpiresAtMaximumLifetime(): void
    {
        $registry = new ProjectileRegistry();
        $registry->spawn('owner', PotionType::WATER, false, new Position(0.0, 64.0, 0.0), 0.0, 0.0);

        $result = $registry->tick(Projectile::MAXIMUM_LIFETIME_TICKS);
        self::assertSame([], $result->updated);
        self::assertCount(1, $result->expired);
        self::assertSame([], $registry->all());
    }

    public function testCrossbowArrowCarriesBoundedPiercingState(): void
    {
        $registry = new ProjectileRegistry(firstEntityId: 1_000);
        $arrow = $registry->spawnTippedArrow(
            'owner',
            PotionType::WATER,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            3.15,
            piercingLevel: 4,
        );

        self::assertSame(ProjectileType::ARROW, $arrow->type);
        self::assertSame(4, $arrow->piercingRemaining);
        $pierced = $arrow->afterPiercing('entity:42');
        self::assertSame(3, $pierced->piercingRemaining);
        self::assertSame(['entity:42'], $pierced->hitActorKeys);
    }

    public function testNormalAndTippedArrowsRemainSemanticallyDistinct(): void
    {
        $registry = new ProjectileRegistry(firstEntityId: 1_500);
        $normal = $registry->spawnArrow(
            'owner',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            3.0,
        );
        $tipped = $registry->spawnTippedArrow(
            'owner',
            PotionType::POISON,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            3.0,
        );

        self::assertFalse($normal->tippedArrow);
        self::assertTrue($tipped->tippedArrow);
        self::assertSame(PotionType::POISON, $tipped->potionType);
    }

    public function testArrowRemainsStationaryAndKeepsItsImpactRotationWhenEmbedded(): void
    {
        $spawnRegistry = new ProjectileRegistry(firstEntityId: 1_750);
        $arrow = $spawnRegistry->spawnArrow(
            'owner',
            new Position(0.0, 64.0, 0.0),
            35.0,
            -12.0,
            3.0,
        )->embeddedAt(
            new Position(1.25, 64.4, 2.5),
            new BlockPosition(1, 64, 2),
            BlockFace::NORTH,
        );
        $registry = new ProjectileRegistry(firstEntityId: 1);
        $registry->restore($arrow);

        $result = $registry->tick();
        self::assertCount(1, $result->updated);
        $updated = $result->updated[0];
        self::assertSame(ProjectileState::EMBEDDED, $updated->state);
        self::assertSame(1, $updated->embeddedTicks);
        self::assertSame(0.0, $updated->motion->x);
        self::assertSame(0.0, $updated->motion->y);
        self::assertSame(0.0, $updated->motion->z);
        self::assertSame($arrow->position, $updated->position);
        self::assertSame($arrow->yaw, $updated->yaw);
        self::assertSame($arrow->pitch, $updated->pitch);
    }

    public function testLoyaltyTridentUsesBoundedReturningMotion(): void
    {
        $registry = new ProjectileRegistry(firstEntityId: 1_900);
        $trident = $registry->spawnTrident(
            'owner',
            PotionType::WATER,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            2.4,
            0.0,
            3,
            false,
            true,
            new InventoryStack('minecraft:trident', 1, 1),
        )->beginReturning()->returnToward(new Position(6.0, 64.0, 0.0));

        self::assertSame(ProjectileState::RETURNING, $trident->state);
        self::assertEqualsWithDelta(0.9, $trident->motion->x, 0.000001);
        self::assertEqualsWithDelta(0.0, $trident->motion->y, 0.000001);
        self::assertEqualsWithDelta(0.0, $trident->motion->z, 0.000001);
    }

    public function testTridentAndFishingHookUseTheirOwnPhysicsAndState(): void
    {
        $registry = new ProjectileRegistry(firstEntityId: 2_000);
        $trident = $registry->spawnTrident(
            'owner',
            PotionType::WATER,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            2.4,
            5.0,
            3,
            true,
            true,
            new InventoryStack('minecraft:trident', 1, 1),
        );
        self::assertSame(ProjectileType::TRIDENT, $trident->type);
        self::assertEqualsWithDelta(-0.1, $trident->tick()->motion->y, 0.000001);

        $hook = $registry->spawnFishingHook(
            'owner',
            99,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            2,
            3,
        )->beginBobbing(new Position(0.0, 63.9, 1.0));
        self::assertSame(ProjectileType::FISHING_HOOK, $hook->type);
        self::assertTrue($hook->fishingBobbing);
        self::assertGreaterThanOrEqual(20, $hook->fishingWaitTicks);
        self::assertLessThanOrEqual(300, $hook->fishingWaitTicks);
        self::assertSame(99, $hook->ownerRuntimeEntityId);
    }
}
