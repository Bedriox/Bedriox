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

namespace Bedriox\Server\Tests\Gameplay\Potion;

use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Gameplay\Potion\PotionProjectile;
use Bedriox\Server\Gameplay\Potion\PotionProjectileRegistry;
use Bedriox\Server\Simulation\Position;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PotionProjectile::class)]
#[CoversClass(PotionProjectileRegistry::class)]
final class PotionProjectileRegistryTest extends TestCase
{
    public function testSpawnUsesViewRotationAndTickAppliesDragAndGravity(): void
    {
        $registry = new PotionProjectileRegistry(firstEntityId: 900);
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
        $registry = new PotionProjectileRegistry(1);
        $registry->spawn('owner', PotionType::WATER, true, new Position(0.0, 64.0, 0.0), 0.0, 0.0);

        $this->expectException(OverflowException::class);
        $registry->spawn('owner', PotionType::WATER, false, new Position(0.0, 64.0, 0.0), 0.0, 0.0);
    }

    public function testProjectileExpiresAtMaximumLifetime(): void
    {
        $registry = new PotionProjectileRegistry();
        $registry->spawn('owner', PotionType::WATER, false, new Position(0.0, 64.0, 0.0), 0.0, 0.0);

        $result = $registry->tick(PotionProjectile::MAXIMUM_LIFETIME_TICKS);
        self::assertSame([], $result->updated);
        self::assertCount(1, $result->expired);
        self::assertSame([], $registry->all());
    }
}
