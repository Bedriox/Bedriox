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

namespace Bedriox\Server\Entity\Item;

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\CollisionBoxQuery;

/** Resolves a dropped item's quarter-block body against authoritative solid blocks. */
final readonly class DroppedItemCollisionResolver
{
    private const float HALF_WIDTH = 0.125;
    private const float HEIGHT = 0.25;

    public function __construct(private CollisionBoxQuery $query) {}

    public function resolve(DroppedItemEntity $before, DroppedItemEntity $advanced): DroppedItemEntity
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
        $xMotion = $x === $motion->x ? $x : 0.0;
        $zMotion = $z === $motion->z ? $z : 0.0;
        if ($landed) {
            $xMotion *= 0.6;
            $zMotion *= 0.6;
        }
        if (abs($xMotion) < 0.001) {
            $xMotion = 0.0;
        }
        if (abs($zMotion) < 0.001) {
            $zMotion = 0.0;
        }

        return $advanced->withPositionAndMotion(
            new Position($from->x + $x, $from->y + $y, $from->z + $z),
            new ItemEntityMotion($xMotion, $y === $motion->y ? $y : 0.0, $zMotion),
        );
    }
}
