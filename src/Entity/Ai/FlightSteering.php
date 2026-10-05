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

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;

/** Applies bounded three-dimensional AI steering and keeps actor rotation aligned. */
final class FlightSteering
{
    public static function toward(AbstractMobEntity $entity, Position $target, float $speed, int $tick): void
    {
        $position = $entity->internalPosition();
        self::motion(
            $entity,
            $target->x - $position->x,
            $target->y - ($position->y + ($entity->collisionHeight() / 2.0)),
            $target->z - $position->z,
            $speed,
            $tick,
        );
    }

    public static function away(AbstractMobEntity $entity, Position $target, float $speed, int $tick): void
    {
        $position = $entity->internalPosition();
        self::motion(
            $entity,
            $position->x - $target->x,
            ($position->y + ($entity->collisionHeight() / 2.0)) - $target->y,
            $position->z - $target->z,
            $speed,
            $tick,
        );
    }

    public static function motion(
        AbstractMobEntity $entity,
        float $x,
        float $y,
        float $z,
        float $speed,
        int $tick,
    ): void {
        $length = sqrt(($x * $x) + ($y * $y) + ($z * $z));
        if ($length < 0.000_001) {
            self::stop($entity, $tick);
            return;
        }
        $x = ($x / $length) * $speed;
        $y = ($y / $length) * $speed;
        $z = ($z / $length) * $speed;
        if (!$entity->applyAiMotion(new EntityMotion($x, $y, $z), $tick)) {
            return;
        }
        $horizontal = hypot($x, $z);
        $entity->moveTo(
            $entity->getWorldName(),
            $entity->internalPosition(),
            $horizontal < 0.000_001 ? $entity->getYaw() : rad2deg(atan2(-$x, $z)),
            rad2deg(-atan2($y, max(0.000_001, $horizontal))),
        );
    }

    public static function stop(AbstractMobEntity $entity, int $tick): void
    {
        $entity->applyAiMotion(new EntityMotion(), $tick);
    }
}
