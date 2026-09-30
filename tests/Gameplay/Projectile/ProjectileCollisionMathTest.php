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

use Bedriox\Server\Gameplay\Projectile\ProjectileCollisionMath;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectileCollisionMath::class)]
final class ProjectileCollisionMathTest extends TestCase
{
    public function testSweptSegmentDetectsThinTargetSkippedByItsEndpoint(): void
    {
        $fraction = ProjectileCollisionMath::segmentAabbEntryFraction(
            new Position(0.0, 65.0, 0.0),
            new Position(1.5, 65.0, 0.0),
            new AxisAlignedBox(0.45, 64.5, -0.25, 0.55, 65.5, 0.25),
        );

        self::assertNotNull($fraction);
        self::assertEqualsWithDelta(0.3, $fraction, 0.000001);
    }

    public function testNearestEntryFractionWinsAndMissReturnsNull(): void
    {
        $from = new Position(0.0, 0.0, 0.0);
        $to = new Position(10.0, 0.0, 0.0);
        $near = ProjectileCollisionMath::segmentAabbEntryFraction(
            $from,
            $to,
            new AxisAlignedBox(2.0, -1.0, -1.0, 3.0, 1.0, 1.0),
        );
        $far = ProjectileCollisionMath::segmentAabbEntryFraction(
            $from,
            $to,
            new AxisAlignedBox(7.0, -1.0, -1.0, 8.0, 1.0, 1.0),
        );

        self::assertNotNull($near);
        self::assertNotNull($far);
        self::assertLessThan($far, $near);
        self::assertNull(ProjectileCollisionMath::segmentAabbEntryFraction(
            $from,
            $to,
            new AxisAlignedBox(2.0, 2.0, -1.0, 3.0, 3.0, 1.0),
        ));
    }
}
