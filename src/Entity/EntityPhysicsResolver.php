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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\Capability\Climbing;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\LoadedCollisionBoxQuery;

/** Resolves authoritative gravity and motion without invoking AI. */
final readonly class EntityPhysicsResolver
{
    private const float MOB_JUMP_VELOCITY = 0.42;
    private const float MOB_CLIMB_HEIGHT = 1.0;

    public function __construct(private LoadedCollisionBoxQuery $collisions) {}

    public function tick(AbstractEntity $entity, int $tick): EntityPhysicsResult
    {
        if ($entity->isImmobile()) {
            $beforeMotion = $entity->getMotion();
            $entity->setMotion(new EntityMotion());
            $entity->advanceAge();

            return new EntityPhysicsResult(false, $beforeMotion != new EntityMotion(), false);
        }
        $definition = $entity->definition();
        $beforePosition = $entity->internalPosition();
        $beforeMotion = $entity->getMotion();
        $friction = 1.0 - $definition->drag;
        $requested = new EntityMotion(
            $beforeMotion->x * $friction,
            max(-$entity->maximumDownwardVelocity(), ($beforeMotion->y - ($entity->isGravityEnabled() ? $definition->gravity : 0.0)) * $friction),
            $beforeMotion->z * $friction,
        );
        $halfWidth = $entity->collisionWidth() / 2.0;
        $box = new AxisAlignedBox(
            $beforePosition->x - $halfWidth,
            $beforePosition->y,
            $beforePosition->z - $halfWidth,
            $beforePosition->x + $halfWidth,
            $beforePosition->y + $entity->collisionHeight(),
            $beforePosition->z + $halfWidth,
        );
        $resolved = $this->resolve($box, $requested);
        if ($resolved === null) {
            $motion = new EntityMotion();
            $entity->setMotion($motion);
            $entity->advanceAge();

            return new EntityPhysicsResult(false, $motion != $beforeMotion, false);
        }
        [$x, $y, $z] = $resolved;
        if ($entity instanceof Climbing
            && ($requested->x !== 0.0 || $requested->z !== 0.0)
            && ($x !== $requested->x || $z !== $requested->z)) {
            $climb = new EntityMotion($requested->x, max(0.2, $requested->y), $requested->z);
            $climbResolved = $this->resolve($box, $climb);
            if ($climbResolved !== null) {
                $requested = $climb;
                [$x, $y, $z] = $climbResolved;
            }
        }
        if ($this->shouldJump($entity, $tick, $box, $requested, $x, $z)) {
            $jump = new EntityMotion($requested->x, self::MOB_JUMP_VELOCITY, $requested->z);
            $jumpResolved = $this->resolve($box, $jump);
            if ($jumpResolved !== null) {
                $requested = $jump;
                [$x, $y, $z] = $jumpResolved;
            }
        }

        $landed = $requested->y < 0.0 && $y !== $requested->y;
        $motion = new EntityMotion(
            self::zeroSmall($x === $requested->x ? $x : 0.0),
            $y === $requested->y ? $y : 0.0,
            self::zeroSmall($z === $requested->z ? $z : 0.0),
        );
        $position = new Position(
            $beforePosition->x + $x,
            $beforePosition->y + $y,
            $beforePosition->z + $z,
        );
        $entity->moveTo($entity->getWorldName(), $position, $entity->getYaw(), $entity->getPitch());
        $entity->setMotion($motion);
        $entity->setOnGround($landed || ($entity->isOnGround() && $requested->y === 0.0));
        $entity->advanceAge();

        return new EntityPhysicsResult(
            $position != $beforePosition,
            $motion != $beforeMotion,
            $landed,
        );
    }

    private static function zeroSmall(float $value): float
    {
        return abs($value) < 0.001 ? 0.0 : $value;
    }

    /** @return null|array{float, float, float} */
    private function resolve(AxisAlignedBox $box, EntityMotion $requested): ?array
    {
        $obstacles = $this->collisions->boxesIntersectingLoaded(
            $box->swept($requested->x, $requested->y, $requested->z),
        );
        if ($obstacles === null) {
            return null;
        }
        $y = $requested->y;
        foreach ($obstacles as $obstacle) {
            $y = $obstacle->resolveY($box, $y);
        }
        $moved = $box->offset(0.0, $y, 0.0);
        $x = $requested->x;
        foreach ($obstacles as $obstacle) {
            $x = $obstacle->resolveX($moved, $x);
        }
        $moved = $moved->offset($x, 0.0, 0.0);
        $z = $requested->z;
        foreach ($obstacles as $obstacle) {
            $z = $obstacle->resolveZ($moved, $z);
        }

        return [$x, $y, $z];
    }

    private function shouldJump(
        AbstractEntity $entity,
        int $tick,
        AxisAlignedBox $box,
        EntityMotion $requested,
        float $resolvedX,
        float $resolvedZ,
    ): bool {
        if (!$entity instanceof AbstractMobEntity || !$entity->isOnGround()
            || !$entity->hasAiMovementIntentAt($tick)
            || ($requested->x === 0.0 && $requested->z === 0.0)
            || ($resolvedX === $requested->x && $resolvedZ === $requested->z)) {
            return false;
        }
        $raisedPath = $box->offset(0.0, self::MOB_CLIMB_HEIGHT, 0.0)->swept(
            $requested->x,
            0.0,
            $requested->z,
        );
        $headroom = $this->collisions->boxesIntersectingLoaded($raisedPath);

        return $headroom !== null && $headroom === [];
    }
}
