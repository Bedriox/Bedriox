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

namespace Bedriox\Server\Tests\World\Collision;

use Bedriox\Server\World\Collision\AxisAlignedBox;
use PHPUnit\Framework\TestCase;

final class AxisAlignedBoxTest extends TestCase
{
    public function testFacesMayTouchWithoutIntersecting(): void
    {
        $left = AxisAlignedBox::unitAt(0, 0, 0);
        self::assertFalse($left->intersects(AxisAlignedBox::unitAt(1, 0, 0)));
        self::assertTrue($left->intersects(new AxisAlignedBox(0.99, 0.0, 0.0, 2.0, 1.0, 1.0)));
    }

    public function testSweptVolumeCoversPositiveAndNegativeMotion(): void
    {
        $swept = AxisAlignedBox::unitAt(0, 0, 0)->swept(2.0, -3.0, 4.0);
        self::assertSame([0.0, -3.0, 0.0, 3.0, 1.0, 5.0], [
            $swept->minX, $swept->minY, $swept->minZ, $swept->maxX, $swept->maxY, $swept->maxZ,
        ]);
    }

    public function testAxisOffsetsStopAtObstacleFaces(): void
    {
        $moving = new AxisAlignedBox(0.0, 1.2, 0.0, 0.6, 3.0, 0.6);
        $wall = AxisAlignedBox::unitAt(1, 1, 0);
        self::assertEqualsWithDelta(0.4, $wall->resolveX($moving, 1.0), 0.000001);
        self::assertSame(-1.0, $wall->resolveX($moving, -1.0));

        $floor = AxisAlignedBox::unitAt(0, 0, 0);
        self::assertEqualsWithDelta(-0.2, $floor->resolveY($moving, -1.0), 0.000001);
    }
}
