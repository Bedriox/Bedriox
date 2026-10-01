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

/** Water-volume queries shared by idle and targeted aquatic steering. */
final class AquaticNavigation
{
    public static function hasNavigableWaterAt(
        AquaticAiWorldView $world,
        AbstractMobEntity $entity,
        Position $position,
    ): bool {
        return $world->isWaterAt(
            $entity->getWorldName(),
            new Position(
                $position->x,
                $position->y + min(0.4, $entity->collisionHeight() * 0.5),
                $position->z,
            ),
        );
    }

    public static function recoveryMotion(
        AquaticAiWorldView $world,
        AbstractMobEntity $entity,
        float $speed,
        int $seed,
    ): EntityMotion {
        $position = $entity->internalPosition();
        $angle = ($seed % 6_283) / 1_000.0;
        $directions = [
            [0.0, -0.5, 0.0],
            [0.0, -1.0, 0.0],
            [cos($angle) * 0.75, -0.35, sin($angle) * 0.75],
            [cos($angle + M_PI_2) * 0.75, -0.35, sin($angle + M_PI_2) * 0.75],
            [cos($angle + M_PI) * 0.75, -0.35, sin($angle + M_PI) * 0.75],
            [cos($angle - M_PI_2) * 0.75, -0.35, sin($angle - M_PI_2) * 0.75],
            [0.0, 0.5, 0.0],
        ];
        foreach ($directions as [$dx, $dy, $dz]) {
            $target = new Position($position->x + $dx, $position->y + $dy, $position->z + $dz);
            if (!self::hasNavigableWaterAt($world, $entity, $target)) {
                continue;
            }
            $length = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz));
            if ($length < 0.000_001) {
                continue;
            }

            return new EntityMotion(
                ($dx / $length) * $speed * 0.65,
                ($dy / $length) * $speed * 0.65,
                ($dz / $length) * $speed * 0.65,
            );
        }

        return new EntityMotion();
    }
}
