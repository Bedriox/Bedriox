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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Collision\AxisAlignedBox;

/** Deterministic swept-projectile broad-phase intersection math. */
final class ProjectileCollisionMath
{
    public static function segmentAabbEntryFraction(Position $from, Position $to, AxisAlignedBox $box): ?float
    {
        $minimum = 0.0;
        $maximum = 1.0;
        foreach ([
            [$from->x, $to->x - $from->x, $box->minX, $box->maxX],
            [$from->y, $to->y - $from->y, $box->minY, $box->maxY],
            [$from->z, $to->z - $from->z, $box->minZ, $box->maxZ],
        ] as [$origin, $delta, $lower, $upper]) {
            if (abs($delta) < 1.0e-12) {
                if ($origin < $lower || $origin > $upper) {
                    return null;
                }
                continue;
            }
            $near = ($lower - $origin) / $delta;
            $far = ($upper - $origin) / $delta;
            if ($near > $far) {
                [$near, $far] = [$far, $near];
            }
            $minimum = max($minimum, $near);
            $maximum = min($maximum, $far);
            if ($minimum > $maximum) {
                return null;
            }
        }

        return $maximum >= 0.0 && $minimum <= 1.0 ? max(0.0, $minimum) : null;
    }
}
