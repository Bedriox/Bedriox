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

namespace Bedriox\Server\Entity\Vehicle\Minecart;

use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use Bedriox\Api\World\Rail\RailSegment;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityPhysicsResult;
use Bedriox\Server\Gameplay\Block\Rail\RailBlockStateResolver;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\World;
use Closure;

/** Deterministic loaded-chunk-only track integration for minecarts. */
final readonly class MinecartRailPhysics
{
    /** Bedrock positions minecarts from their vertical centre while the rail occupies the block below it. */
    private const float RAIL_BASE_OFFSET_Y = 0.5;
    private const float SLOPE_ACCELERATION = 0.0078125;
    private const float OCCUPIED_DRAG = 0.997;
    private const float EMPTY_DRAG = 0.96;
    private const float MINIMUM_SPEED = 0.001;

    /** @var Closure(AbstractMinecartEntity): bool */
    private Closure $occupied;

    /** @var null|Closure(AbstractMinecartEntity, Position, Position, EntityMotion): bool */
    private ?Closure $allowMove;

    /** @var null|Closure(AbstractMinecartEntity, Position, Position, EntityMotion): void */
    private ?Closure $moved;

    /** @var null|Closure(AbstractMinecartEntity, ?RailSegment, RailSegment, float): bool */
    private ?Closure $allowRailChange;

    /** @var null|Closure(AbstractMinecartEntity, ?RailSegment, RailSegment, float): void */
    private ?Closure $railChanged;

    public function __construct(
        private World $world,
        private BlockStateRegistry $states,
        private RailBlockStateResolver $rails,
        Closure $occupied,
        ?Closure $allowMove = null,
        ?Closure $moved = null,
        ?Closure $allowRailChange = null,
        ?Closure $railChanged = null,
    ) {
        $this->occupied = $occupied;
        $this->allowMove = $allowMove;
        $this->moved = $moved;
        $this->allowRailChange = $allowRailChange;
        $this->railChanged = $railChanged;
    }

    public function tick(AbstractMinecartEntity $minecart): ?EntityPhysicsResult
    {
        $before = $minecart->internalPosition();
        $beforeMotion = $minecart->getMotion();
        $located = $this->railAt($before);
        if ($located === null) {
            $minecart->setCurrentRail(null);

            return null;
        }
        [$railY, $segment] = $located;
        $previousRail = $minecart->getCurrentRail();
        $speed = $this->signedSpeed($minecart, $segment, $previousRail);
        if (!self::sameRail($previousRail, $segment)) {
            if ($this->allowRailChange !== null
                && !($this->allowRailChange)($minecart, $previousRail, $segment, $speed)) {
                $minecart->stopTrackMotion();
                $minecart->advanceDamageAnimation();
                $minecart->advanceAge();

                return new EntityPhysicsResult(false, $beforeMotion != $minecart->getMotion(), false);
            }
            $minecart->setCurrentRail($segment);
        }

        [$start, $end] = self::worldEndpoints($segment, $railY);
        $dx = $end->x - $start->x;
        $dy = $end->y - $start->y;
        $dz = $end->z - $start->z;
        $lengthSquared = ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
        $length = sqrt($lengthSquared);
        if ($length <= 0.0) {
            $minecart->stopTrackMotion();
            $minecart->advanceDamageAnimation();
            $minecart->advanceAge();

            return new EntityPhysicsResult(false, $beforeMotion != $minecart->getMotion(), false);
        }
        $t = (($before->x - $start->x) * $dx
            + ($before->y - $start->y) * $dy
            + ($before->z - $start->z) * $dz) / $lengthSquared;
        $t = max(0.0, min(1.0, $t));
        if ($dy !== 0.0 && $speed !== 0.0) {
            $speed -= ($dy / $length) * self::SLOPE_ACCELERATION;
        }
        $speed = max(-AbstractMinecartEntity::MAXIMUM_TRACK_SPEED, min(AbstractMinecartEntity::MAXIMUM_TRACK_SPEED, $speed));
        // A tiny bounded overstep lets the next tick resolve the adjacent rail cell in either direction.
        // Clamping exactly to the negative endpoint would keep floor() in the old cell forever.
        $nextT = max(-0.001, min(1.001, $t + ($speed / $length)));
        $to = new Position(
            $start->x + ($dx * $nextT),
            $start->y + ($dy * $nextT),
            $start->z + ($dz * $nextT),
        );
        $motion = new EntityMotion(
            ($dx / $length) * $speed,
            ($dy / $length) * $speed,
            ($dz / $length) * $speed,
        );
        $crossesRailCell = (int) floor($before->x) !== (int) floor($to->x)
            || (int) floor($before->z) !== (int) floor($to->z);
        if ($crossesRailCell && $this->railAt($to) === null) {
            $minecart->stopTrackMotion();
            $minecart->advanceDamageAnimation();
            $minecart->advanceAge();

            return new EntityPhysicsResult(false, $beforeMotion != $minecart->getMotion(), false);
        }
        if ($this->allowMove !== null && !($this->allowMove)($minecart, $before, $to, $motion)) {
            $minecart->stopTrackMotion();
            $minecart->advanceDamageAnimation();
            $minecart->advanceAge();

            return new EntityPhysicsResult(false, $beforeMotion != $minecart->getMotion(), false);
        }
        $yaw = self::travelYaw($motion, $dx, $dz, $speed, $minecart->getYaw());
        $minecart->moveTo($minecart->getWorldName(), $to, $yaw, 0.0);
        $drag = ($this->occupied)($minecart) ? self::OCCUPIED_DRAG : self::EMPTY_DRAG;
        $nextSpeed = $speed * $drag;
        if (abs($nextSpeed) < self::MINIMUM_SPEED) {
            $nextSpeed = 0.0;
        }
        $minecart->setTrackSpeed($nextSpeed);
        $minecart->applyMotion($motion);
        $minecart->setOnGround(true);
        $minecart->advanceDamageAnimation();
        $minecart->advanceAge();
        if (!self::sameRail($previousRail, $segment)) {
            ($this->railChanged)?->__invoke($minecart, $previousRail, $segment, $speed);
        }
        ($this->moved)?->__invoke($minecart, $before, $to, $motion);

        return new EntityPhysicsResult($to != $before, $motion != $beforeMotion, false);
    }

    /** @return null|array{int, RailSegment} */
    private function railAt(Position $position): ?array
    {
        $x = (int) floor($position->x);
        $z = (int) floor($position->z);
        $baseY = (int) floor($position->y);
        foreach ([$baseY, $baseY - 1, $baseY + 1] as $y) {
            $stateId = $this->world->loadedBlockStateAt($x, $y, $z);
            if ($stateId === null) {
                continue;
            }
            $state = $this->states->state($stateId);
            if (!BlockTagRegistry::contains(BlockTag::RAIL, $state->identifier())) {
                continue;
            }
            $block = new BlockPosition($x, $y, $z);

            return [$y, $this->rails->segment($block, $state)];
        }

        return null;
    }

    private function signedSpeed(
        AbstractMinecartEntity $minecart,
        RailSegment $segment,
        ?RailSegment $previousRail,
    ): float {
        [$first, $second] = $segment->endpoints();
        $dx = (float) ($second->x - $first->x);
        $dz = (float) ($second->z - $first->z);
        $horizontalLength = sqrt(($dx * $dx) + ($dz * $dz));
        $speed = $minecart->getTrackSpeed();
        $motion = $minecart->getMotion();
        $dot = $horizontalLength <= 0.0 ? 0.0 : (($motion->x * $dx) + ($motion->z * $dz)) / $horizontalLength;
        if ($previousRail !== null && !self::sameRail($previousRail, $segment)
            && abs($speed) > self::MINIMUM_SPEED && abs($dot) > self::MINIMUM_SPEED) {
            return $dot < 0.0 ? -abs($speed) : abs($speed);
        }
        if (abs($speed) > self::MINIMUM_SPEED) {
            return $speed;
        }
        if (abs($dot) > self::MINIMUM_SPEED) {
            return max(-AbstractMinecartEntity::MAXIMUM_TRACK_SPEED, min(AbstractMinecartEntity::MAXIMUM_TRACK_SPEED, $dot));
        }
        $radians = deg2rad($minecart->getYaw());
        $facingDot = ((-sin($radians)) * $dx) + (cos($radians) * $dz);

        return $facingDot < 0.0 ? -0.0 : 0.0;
    }

    /** @return array{Position, Position} */
    private static function worldEndpoints(RailSegment $segment, int $railY): array
    {
        [$first, $second] = $segment->endpoints();
        $centerX = $segment->position->x + 0.5;
        $centerZ = $segment->position->z + 0.5;
        $baseY = $railY + self::RAIL_BASE_OFFSET_Y;

        return [
            new Position($centerX + ($first->x * 0.5), $baseY + $first->y, $centerZ + ($first->z * 0.5)),
            new Position($centerX + ($second->x * 0.5), $baseY + $second->y, $centerZ + ($second->z * 0.5)),
        ];
    }

    private static function travelYaw(
        EntityMotion $motion,
        float $railX,
        float $railZ,
        float $speed,
        float $fallback,
    ): float {
        $x = $motion->x;
        $z = $motion->z;
        if (($x * $x) + ($z * $z) < 0.000001) {
            $x = $railX * ($speed < 0.0 ? -1.0 : 1.0);
            $z = $railZ * ($speed < 0.0 ? -1.0 : 1.0);
        }
        if (($x * $x) + ($z * $z) < 0.000001) {
            return $fallback;
        }
        // Minecart models are authored along their local X axis, unlike ordinary living actors.
        $yaw = rad2deg(atan2($z, $x));

        return $yaw < 0.0 ? $yaw + 360.0 : $yaw;
    }

    private static function sameRail(?RailSegment $left, RailSegment $right): bool
    {
        return $left !== null
            && $left->position->x === $right->position->x
            && $left->position->y === $right->position->y
            && $left->position->z === $right->position->z
            && $left->identifier === $right->identifier
            && $left->shape === $right->shape;
    }
}
