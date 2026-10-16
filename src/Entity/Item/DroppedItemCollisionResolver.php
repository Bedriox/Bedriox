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
use Bedriox\Server\World\Collision\LoadedCollisionBoxQuery;
use Bedriox\Server\World\Environment\Fluid\FluidCurrentResolver;

/** Resolves a dropped item's quarter-block body against authoritative solid blocks. */
final readonly class DroppedItemCollisionResolver
{
    private const float HALF_WIDTH = 0.125;
    private const float HEIGHT = 0.25;
    private const float CURRENT_ACCELERATION = 0.014;

    public function __construct(
        private CollisionBoxQuery $query,
        private ?FluidCurrentResolver $fluidCurrents = null,
    ) {}

    public function resolve(DroppedItemEntity $before, DroppedItemEntity $advanced): DroppedItemEntity
    {
        $from = $before->position;
        $motion = $advanced->motion;
        $current = $this->fluidCurrents?->currentAt($from, self::HEIGHT);
        if ($current !== null) {
            $motion = new ItemEntityMotion(
                $motion->x + ($current->x * self::CURRENT_ACCELERATION),
                $motion->y + ($current->y * self::CURRENT_ACCELERATION),
                $motion->z + ($current->z * self::CURRENT_ACCELERATION),
            );
        }
        $box = new AxisAlignedBox(
            $from->x - self::HALF_WIDTH,
            $from->y,
            $from->z - self::HALF_WIDTH,
            $from->x + self::HALF_WIDTH,
            $from->y + self::HEIGHT,
            $from->z + self::HALF_WIDTH,
        );
        $escaped = $this->escapeEmbeddedBlock($box);
        if ($escaped === null) {
            return $this->suspendUntilTerrainIsLoaded($advanced, $from);
        }
        [$box, $escapeX, $escapeY, $escapeZ] = $escaped;
        $obstacles = $this->intersections($box->swept($motion->x, $motion->y, $motion->z));
        if ($obstacles === null) {
            return $this->suspendUntilTerrainIsLoaded($advanced, $from);
        }
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
            new Position(
                $from->x + $escapeX + $x,
                $from->y + $escapeY + $y,
                $from->z + $escapeZ + $z,
            ),
            new ItemEntityMotion($xMotion, $y === $motion->y ? $y : 0.0, $zMotion),
        );
    }

    /** @return array{AxisAlignedBox, float, float, float}|null */
    private function escapeEmbeddedBlock(AxisAlignedBox $box): ?array
    {
        $overlaps = $this->intersections($box);
        if ($overlaps === null) {
            return null;
        }
        if ($overlaps === []) {
            return [$box, 0.0, 0.0, 0.0];
        }
        $highestSurface = $box->minY;
        foreach ($overlaps as $obstacle) {
            $highestSurface = max($highestSurface, $obstacle->maxY);
        }
        $up = $highestSurface - $box->minY;
        $raised = $box->offset(0.0, $up, 0.0);
        $raisedOverlaps = $this->intersections($raised);
        if ($raisedOverlaps === null) {
            return null;
        }
        if ($raisedOverlaps === []) {
            return [$raised, 0.0, $up, 0.0];
        }

        $candidates = [];
        foreach ($overlaps as $obstacle) {
            $candidates[] = [$obstacle->minX - $box->maxX, 0.0, 0.0];
            $candidates[] = [$obstacle->maxX - $box->minX, 0.0, 0.0];
            $candidates[] = [0.0, 0.0, $obstacle->minZ - $box->maxZ];
            $candidates[] = [0.0, 0.0, $obstacle->maxZ - $box->minZ];
        }
        usort($candidates, static fn(array $left, array $right): int =>
            (abs($left[0]) + abs($left[2])) <=> (abs($right[0]) + abs($right[2])));
        foreach ($candidates as [$x, $y, $z]) {
            $escaped = $box->offset($x, $y, $z);
            $escapedOverlaps = $this->intersections($escaped);
            if ($escapedOverlaps === null) {
                return null;
            }
            if ($escapedOverlaps === []) {
                return [$escaped, $x, $y, $z];
            }
        }

        return [$box, 0.0, 0.0, 0.0];
    }

    /** @return list<AxisAlignedBox>|null */
    private function intersections(AxisAlignedBox $area): ?array
    {
        return $this->query instanceof LoadedCollisionBoxQuery
            ? $this->query->boxesIntersectingLoaded($area)
            : $this->query->boxesIntersecting($area);
    }

    private function suspendUntilTerrainIsLoaded(DroppedItemEntity $advanced, Position $position): DroppedItemEntity
    {
        return $advanced->withPositionAndMotion($position, new ItemEntityMotion(0.0, 0.0, 0.0));
    }
}
