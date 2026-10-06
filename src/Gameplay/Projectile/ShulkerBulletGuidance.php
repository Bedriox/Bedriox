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

namespace Bedriox\Server\Gameplay\Projectile;

use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;

/** Deterministic bounded steering used by authoritative Shulker Bullet ticks. */
final class ShulkerBulletGuidance
{
    private const float SPEED = 0.30;
    private const float TURN_BLEND = 0.20;

    public static function initialMotion(Position $origin, Position $target): EntityMotion
    {
        return self::direction($origin, $target, self::SPEED);
    }

    public static function steer(Projectile $bullet, Position $target): Projectile
    {
        $desired = self::direction($bullet->position, $target, self::SPEED);
        $x = ($bullet->motion->x * (1.0 - self::TURN_BLEND)) + ($desired->x * self::TURN_BLEND);
        $y = ($bullet->motion->y * (1.0 - self::TURN_BLEND)) + ($desired->y * self::TURN_BLEND);
        $z = ($bullet->motion->z * (1.0 - self::TURN_BLEND)) + ($desired->z * self::TURN_BLEND);

        return $bullet->withMotion(new EntityMotion($x, $y, $z));
    }

    public static function yaw(EntityMotion $motion): float
    {
        return rad2deg(atan2(-$motion->x, $motion->z));
    }

    public static function pitch(EntityMotion $motion): float
    {
        $length = hypot(hypot($motion->x, $motion->z), $motion->y);

        return $length < 0.000001 ? 0.0 : rad2deg(-asin(max(-1.0, min(1.0, $motion->y / $length))));
    }

    private static function direction(Position $origin, Position $target, float $speed): EntityMotion
    {
        $x = $target->x - $origin->x;
        $y = $target->y - $origin->y;
        $z = $target->z - $origin->z;
        $length = hypot(hypot($x, $z), $y);
        if ($length < 0.000001 || !is_finite($length)) {
            return new EntityMotion(0.0, 0.0, $speed);
        }

        return new EntityMotion($x / $length * $speed, $y / $length * $speed, $z / $length * $speed);
    }
}
