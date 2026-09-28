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

namespace Bedriox\Server\World\Collision;

use Bedriox\Server\Simulation\Position;

final class PlayerCollisionShape
{
    public const float WIDTH = 0.6;
    public const float HEIGHT = 1.8;
    public const float STEP_HEIGHT = 0.6;

    private function __construct() {}

    public static function at(Position $feet): AxisAlignedBox
    {
        $radius = self::WIDTH / 2.0;

        return new AxisAlignedBox(
            $feet->x - $radius,
            $feet->y,
            $feet->z - $radius,
            $feet->x + $radius,
            $feet->y + self::HEIGHT,
            $feet->z + $radius,
        );
    }
}
