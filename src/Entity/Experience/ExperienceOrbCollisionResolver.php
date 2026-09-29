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

namespace Bedriox\Server\Entity\Experience;

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\CollisionBoxQuery;

/** Resolves an experience orb's quarter-block body against authoritative solids. */
final readonly class ExperienceOrbCollisionResolver
{
    private const float HALF_WIDTH = 0.125;
    private const float HEIGHT = 0.25;

    public function __construct(private CollisionBoxQuery $query) {}

    public function resolve(ExperienceOrbEntity $before, ExperienceOrbEntity $advanced): ExperienceOrbEntity
    {
        $from = $before->position;
        $motion = $advanced->motion;
        $box = new AxisAlignedBox(
            $from->x - self::HALF_WIDTH,
            $from->y,
            $from->z - self::HALF_WIDTH,
            $from->x + self::HALF_WIDTH,
            $from->y + self::HEIGHT,
            $from->z + self::HALF_WIDTH,
        );
        $obstacles = $this->query->boxesIntersecting($box->swept($motion->x, $motion->y, $motion->z));
        $y = $motion->y;
        foreach ($obstacles as $obstacle) {
            $y = $obstacle->resolveY($box, $y);
        }
        $box = $box->offset(0.0, $y, 0.0);
        $x = $motion->x;
        foreach ($obstacles as $obstacle) {
            $x = $obstacle->resolveX($box, $x);
        }
        $box = $box->offset($x, 0.0, 0.0);
        $z = $motion->z;
        foreach ($obstacles as $obstacle) {
            $z = $obstacle->resolveZ($box, $z);
        }
        $landed = $motion->y < 0.0 && $y !== $motion->y;
        $horizontalFriction = $landed ? 0.6 : 1.0;

        return $advanced->withPositionAndMotion(
            new Position($from->x + $x, $from->y + $y, $from->z + $z),
            new ExperienceOrbMotion(
                self::zeroSmall(($x === $motion->x ? $x : 0.0) * $horizontalFriction),
                $y === $motion->y ? $y : 0.0,
                self::zeroSmall(($z === $motion->z ? $z : 0.0) * $horizontalFriction),
            ),
        );
    }

    private static function zeroSmall(float $value): float
    {
        return abs($value) < 0.001 ? 0.0 : $value;
    }
}
