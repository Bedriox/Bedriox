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

use Bedriox\Api\Entity\Capability\Aquatic;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;

final class HorizontalSteering
{
    public static function toward(
        AbstractMobEntity $entity,
        Position $target,
        float $speed,
        ?int $aiTick = null,
        ?AiWorldView $world = null,
    ): void {
        self::apply($entity, $target, $speed, $aiTick, $world);
    }

    public static function away(
        AbstractMobEntity $entity,
        Position $target,
        float $speed,
        ?int $aiTick = null,
        ?AiWorldView $world = null,
    ): void {
        self::apply($entity, $target, -$speed, $aiTick, $world);
    }

    public static function stop(AbstractMobEntity $entity, ?int $aiTick = null): void
    {
        self::setMotion($entity, new EntityMotion(0.0, $entity->getMotion()->y, 0.0), $aiTick);
    }

    public static function motion(
        AbstractMobEntity $entity,
        float $x,
        float $z,
        ?int $aiTick = null,
        ?AiWorldView $world = null,
    ): void {
        if (self::wouldEnterWater($entity, $x, $z, $world)) {
            self::stop($entity, $aiTick);
            return;
        }
        if (!self::setMotion($entity, new EntityMotion($x, $entity->getMotion()->y, $z), $aiTick)) {
            return;
        }
        if (hypot($x, $z) < 0.000_001) {
            return;
        }
        $entity->moveTo(
            $entity->getWorldName(),
            $entity->internalPosition(),
            rad2deg(atan2(-$x, $z)),
            $entity->getPitch(),
        );
    }

    private static function apply(
        AbstractMobEntity $entity,
        Position $target,
        float $speed,
        ?int $aiTick,
        ?AiWorldView $world,
    ): void {
        $position = $entity->internalPosition();
        $dx = $target->x - $position->x;
        $dz = $target->z - $position->z;
        $length = hypot($dx, $dz);
        if ($length < 0.000_001) {
            self::stop($entity, $aiTick);

            return;
        }
        self::motion(
            $entity,
            ($dx / $length) * $speed,
            ($dz / $length) * $speed,
            $aiTick,
            $world,
        );
    }

    private static function wouldEnterWater(
        AbstractMobEntity $entity,
        float $x,
        float $z,
        ?AiWorldView $world,
    ): bool {
        if ($entity instanceof Aquatic || !$world instanceof AquaticAiWorldView) {
            return false;
        }
        $length = hypot($x, $z);
        if ($length < 0.000_001) {
            return false;
        }
        $position = $entity->internalPosition();
        $next = new Position(
            $position->x + (($x / $length) * 0.8),
            $position->y + 0.05,
            $position->z + (($z / $length) * 0.8),
        );

        return $world->isWaterAt($entity->getWorldName(), $next)
            || $world->isWaterAt(
                $entity->getWorldName(),
                new Position($next->x, $next->y - 0.5, $next->z),
            );
    }

    private static function setMotion(AbstractMobEntity $entity, EntityMotion $motion, ?int $aiTick): bool
    {
        if ($aiTick !== null) {
            return $entity->applyAiMotion($motion, $aiTick);
        }
        $entity->setMotion($motion);

        return true;
    }
}
