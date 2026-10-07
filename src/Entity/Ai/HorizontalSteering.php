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
    private const float SEPARATION_RADIUS_PADDING = 0.35;
    private const int SEPARATION_NEIGHBOR_LIMIT = 8;
    private const float SEPARATION_WEIGHT = 0.35;

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
        if ($world instanceof NavigatingAiWorldView && $aiTick !== null) {
            $target = $world->navigationWaypoint($entity, $target, $aiTick);
        }
        $position = $entity->internalPosition();
        $dx = $target->x - $position->x;
        $dz = $target->z - $position->z;
        $length = hypot($dx, $dz);
        if ($length < 0.000_001) {
            [$separationX, $separationZ] = self::separation($entity, $world);
            if ($separationX === 0.0 && $separationZ === 0.0) {
                self::stop($entity, $aiTick);
                return;
            }
            self::motion($entity, $separationX * abs($speed), $separationZ * abs($speed), $aiTick, $world);

            return;
        }
        [$separationX, $separationZ] = self::separation($entity, $world);
        $directionX = ($dx / $length) + ($separationX * self::SEPARATION_WEIGHT);
        $directionZ = ($dz / $length) + ($separationZ * self::SEPARATION_WEIGHT);
        $directionLength = hypot($directionX, $directionZ);
        if ($directionLength < 0.000_001) {
            self::stop($entity, $aiTick);
            return;
        }
        self::motion(
            $entity,
            ($directionX / $directionLength) * $speed,
            ($directionZ / $directionLength) * $speed,
            $aiTick,
            $world,
        );
    }

    /** @return array{float, float} */
    private static function separation(AbstractMobEntity $entity, ?AiWorldView $world): array
    {
        if ($world === null) {
            return [0.0, 0.0];
        }
        $position = $entity->internalPosition();
        $radius = $entity->collisionWidth() + self::SEPARATION_RADIUS_PADDING;
        $x = $z = 0.0;
        foreach ($world->nearbyEntities($entity, $radius, self::SEPARATION_NEIGHBOR_LIMIT) as $neighbor) {
            if ($neighbor === $entity || $neighbor->isRemoved()
                || abs($neighbor->internalPosition()->y - $position->y)
                    >= max($entity->collisionHeight(), $neighbor->collisionHeight())) {
                continue;
            }
            $dx = $position->x - $neighbor->internalPosition()->x;
            $dz = $position->z - $neighbor->internalPosition()->z;
            $distance = hypot($dx, $dz);
            if ($distance < 0.000_001) {
                $dx = (($entity->getRuntimeId() ^ $neighbor->getRuntimeId()) & 1) === 0 ? 1.0 : 0.0;
                $dz = $dx === 0.0 ? 1.0 : 0.0;
                $distance = 1.0;
            }
            $weight = max(0.0, ($radius - $distance) / $radius);
            $x += ($dx / $distance) * $weight;
            $z += ($dz / $distance) * $weight;
        }
        $length = hypot($x, $z);

        return $length < 0.000_001 ? [0.0, 0.0] : [$x / $length, $z / $length];
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
