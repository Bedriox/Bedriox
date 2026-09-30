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

namespace Bedriox\Server\Tests\Gameplay\Combat;

use Bedriox\Server\Gameplay\Combat\KnockbackMotion;
use Bedriox\Server\Gameplay\Combat\KnockbackResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class KnockbackResolverTest extends TestCase
{
    public function testGroundedImpulseIsNormalizedAndAppliedOnce(): void
    {
        $motion = (new KnockbackResolver())->resolve(
            new KnockbackMotion(0.1, 0.0, 0.0),
            3.0,
            4.0,
            0.6,
            0.4,
            0.0,
            true,
            0.4,
        );

        self::assertEqualsWithDelta(0.41, $motion->x, 0.000_001);
        self::assertEqualsWithDelta(0.4, $motion->y, 0.000_001);
        self::assertEqualsWithDelta(0.48, $motion->z, 0.000_001);
    }

    public function testResistanceScalesTheImpulseWithoutRepeatedDamping(): void
    {
        $motion = (new KnockbackResolver())->resolve(
            new KnockbackMotion(0.2, 0.0, 0.0),
            1.0,
            0.0,
            0.6,
            0.4,
            0.25,
            true,
            0.4,
        );

        self::assertEqualsWithDelta(0.55, $motion->x, 0.000_001);
        self::assertEqualsWithDelta(0.3, $motion->y, 0.000_001);
        self::assertEqualsWithDelta(0.0, $motion->z, 0.000_001);
    }

    public function testAirborneImpulsePreservesVerticalMotion(): void
    {
        $motion = (new KnockbackResolver())->resolve(
            new KnockbackMotion(0.0, -0.2, 0.0),
            0.0,
            1.0,
            0.4,
            0.4,
            0.0,
            false,
            0.4,
        );

        self::assertEqualsWithDelta(0.0, $motion->x, 0.000_001);
        self::assertEqualsWithDelta(-0.2, $motion->y, 0.000_001);
        self::assertEqualsWithDelta(0.4, $motion->z, 0.000_001);
    }

    public function testZeroDirectionLeavesMotionUnchanged(): void
    {
        $current = new KnockbackMotion(0.1, 0.2, 0.3);
        $motion = (new KnockbackResolver())->resolve(
            $current,
            0.0,
            0.0,
            0.6,
            0.4,
            0.0,
            true,
            0.4,
        );

        self::assertSame($current, $motion);
    }

    public function testInvalidResistanceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new KnockbackResolver())->resolve(
            new KnockbackMotion(0.0, 0.0, 0.0),
            1.0,
            0.0,
            0.4,
            0.4,
            1.1,
            true,
            0.4,
        );
    }
}
