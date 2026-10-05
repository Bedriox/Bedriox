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

use Bedriox\Api\Entity\Capability\Aquatic;
use Bedriox\Api\Entity\Capability\Climbing;
use Bedriox\Server\Entity\Vanilla\Nether\GhastEntity;
use Bedriox\Server\Entity\Vanilla\Nether\HappyGhastEntity;
use Bedriox\Server\Entity\Vanilla\Nether\StriderEntity;
use Bedriox\Server\Entity\Vehicle\BoatEntity;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\LoadedCollisionBoxQuery;

/** Resolves authoritative gravity and motion without invoking AI. */
final readonly class EntityPhysicsResolver
{
    private const float MOB_JUMP_VELOCITY = 0.42;
    private const float MOB_CLIMB_HEIGHT = 1.0;

    public function __construct(
        private LoadedCollisionBoxQuery $collisions,
        private ?WorldEntityEnvironment $environment = null,
    ) {}

    public function tick(AbstractEntity $entity, int $tick): EntityPhysicsResult
    {
        if ($entity instanceof BoatEntity) {
            $entity->advanceDamageAnimation();
        }
        if ($entity->isImmobile()) {
            $beforeMotion = $entity->getMotion();
            $entity->setMotion(new EntityMotion());
            $entity->advanceAge();

            return new EntityPhysicsResult(false, $beforeMotion != new EntityMotion(), false);
        }
        $definition = $entity->definition();
        $beforePosition = $entity->internalPosition();
        $beforeMotion = $entity->getMotion();
        $halfWidth = $entity->collisionWidth() / 2.0;
        $box = new AxisAlignedBox(
            $beforePosition->x - $halfWidth,
            $beforePosition->y,
            $beforePosition->z - $halfWidth,
            $beforePosition->x + $halfWidth,
            $beforePosition->y + $entity->collisionHeight(),
            $beforePosition->z + $halfWidth,
        );
        $inWater = $entity instanceof AbstractLivingEntity
            && $entity instanceof Aquatic
            && $this->environment?->isTouchingWater($entity) === true;
        $boatWaterSurface = $entity instanceof BoatEntity
            ? $this->environment?->waterSurfaceY($entity)
            : null;
        $boatOnWater = $boatWaterSurface !== null;
        $striderLavaSurface = $entity instanceof StriderEntity
            ? $this->environment?->lavaSurfaceY($entity)
            : null;
        $striderOnLava = $striderLavaSurface !== null;
        $fluidSupported = $inWater || $boatOnWater || $striderOnLava;
        $friction = $fluidSupported ? 0.90 : 1.0 - $definition->drag;
        $gravity = $fluidSupported ? 0.0 : ($entity->isGravityEnabled() ? $definition->gravity : 0.0);
        $vertical = $entity instanceof BoatEntity && $boatWaterSurface !== null
            ? $entity->waterlineCorrection($boatWaterSurface)
            : ($striderLavaSurface !== null
                ? max(-0.08, min(0.08, ($striderLavaSurface - $beforePosition->y) * 0.25))
                : ($beforeMotion->y - $gravity) * $friction);
        if (($entity instanceof GhastEntity || $entity instanceof HappyGhastEntity)
            && $vertical <= 0.0 && $this->hasGroundWithin($box, 5.0)) {
            $vertical = 0.10;
        }
        $requested = new EntityMotion(
            $beforeMotion->x * $friction,
            max(-$entity->maximumDownwardVelocity(), $vertical),
            $beforeMotion->z * $friction,
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
            $boatOnWater ? 0.0 : ($y === $requested->y ? $y : 0.0),
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

    private function hasGroundWithin(AxisAlignedBox $box, float $distance): bool
    {
        $probe = new AxisAlignedBox(
            $box->minX,
            $box->minY - $distance,
            $box->minZ,
            $box->maxX,
            $box->minY,
            $box->maxZ,
        );
        $obstacles = $this->collisions->boxesIntersectingLoaded($probe);

        return $obstacles !== null && $obstacles !== [];
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
        if (!$entity instanceof AbstractMobEntity || $entity instanceof BoatEntity || !$entity->isOnGround()
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
