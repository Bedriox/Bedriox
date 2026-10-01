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

/** Keeps three-dimensional velocity and actor rotation on the same heading. */
final class AquaticSteering
{
    public static function motion(
        AbstractMobEntity $entity,
        float $x,
        float $y,
        float $z,
        int $tick,
        bool $allowPitch = true,
    ): void {
        $length = sqrt(($x * $x) + ($y * $y) + ($z * $z));
        if ($length < 0.000_001 || !$entity->applyAiMotion(new EntityMotion($x, $y, $z), $tick)) {
            return;
        }
        $horizontal = hypot($x, $z);
        $entity->moveTo(
            $entity->getWorldName(),
            $entity->internalPosition(),
            $horizontal < 0.000_001 ? $entity->getYaw() : rad2deg(atan2(-$x, $z)),
            $allowPitch ? rad2deg(-atan2($y, max(0.000_001, $horizontal))) : 0.0,
        );
    }

    public static function stop(AbstractMobEntity $entity, int $tick): void
    {
        $entity->applyAiMotion(new EntityMotion(), $tick);
    }
}
