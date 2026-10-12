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

use Bedriox\Api\Entity\Capability\Vehicle;
use Bedriox\Api\Entity\MobActivationState;
use Bedriox\Api\Event\Vehicle\VehicleCollisionKind;
use Bedriox\Server\Entity\Ai\AiClock;
use Bedriox\Server\Entity\Ai\SystemAiClock;
use Bedriox\Server\Entity\Block\FallingBlockEntity;
use Bedriox\Server\Entity\Collision\SkipsEntityContact;
use Bedriox\Server\Entity\Mount\MountLink;
use Bedriox\Server\Entity\Mount\MountRegistry;
use Bedriox\Server\Entity\Vanilla\Misc\LeashKnotEntity;
use Closure;

/** Applies a bounded, deterministic horizontal soft push between overlapping actors. */
final readonly class EntityContactResolver
{
    private const float QUERY_RADIUS_PADDING = 0.25;
    private const float MAXIMUM_PUSH = 0.05;
    private const float MINIMUM_DISTANCE = 0.000_001;

    public function __construct(
        private EntityRegistry $entities,
        private ?MountRegistry $mounts = null,
        private AiClock $clock = new SystemAiClock(),
        private ?Closure $allowVehicleCollision = null,
        private ?Closure $vehicleCollided = null,
    ) {}

    public function resolve(EntityWorkBudget $budget): EntityContactMetrics
    {
        $start = $this->clock->nanoseconds();
        $candidates = $pairs = $contacts = 0;
        $seen = [];
        $exhausted = false;

        foreach ($this->entities->all() as $entity) {
            if ($entity->isRemoved() || $entity instanceof FallingBlockEntity || $entity instanceof SkipsEntityContact) {
                continue;
            }
            $radius = $entity->collisionWidth() + self::QUERY_RADIUS_PADDING;
            foreach ($this->entities->nearby(
                $entity->getWorldName(),
                $entity->internalPosition(),
                $radius,
                $budget->maximumContactCandidates,
            ) as $other) {
                ++$candidates;
                if ($entity === $other || $other->isRemoved() || $other instanceof FallingBlockEntity
                    || $other instanceof SkipsEntityContact) {
                    continue;
                }
                $low = min($entity->getRuntimeId(), $other->getRuntimeId());
                $high = max($entity->getRuntimeId(), $other->getRuntimeId());
                $key = $low . ':' . $high;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if ($pairs >= $budget->maximumContactPairs
                    || ($pairs % 16 === 0
                        && $this->clock->nanoseconds() - $start >= $budget->maximumContactNanoseconds)) {
                    $exhausted = true;
                    break 2;
                }
                ++$pairs;
                if ($entity instanceof LeashKnotEntity || $other instanceof LeashKnotEntity
                    || $this->sameMountAssembly($entity, $other) || !$this->overlapsVertically($entity, $other)) {
                    continue;
                }
                $left = $entity->internalPosition();
                $right = $other->internalPosition();
                $dx = $right->x - $left->x;
                $dz = $right->z - $left->z;
                $distance = hypot($dx, $dz);
                $required = ($entity->collisionWidth() + $other->collisionWidth()) / 2.0;
                if ($distance >= $required) {
                    continue;
                }
                $penetration = $required - $distance;
                if ($distance < self::MINIMUM_DISTANCE) {
                    $dx = (($low ^ $high) & 1) === 0 ? 1.0 : 0.0;
                    $dz = $dx === 0.0 ? 1.0 : 0.0;
                    $distance = 1.0;
                }
                $push = min(self::MAXIMUM_PUSH, max(0.0, $penetration * 0.25));
                $nx = $dx / $distance;
                $nz = $dz / $distance;
                $relativeSpeed = hypot(
                    $entity->getMotion()->x - $other->getMotion()->x,
                    $entity->getMotion()->z - $other->getMotion()->z,
                );
                $vehicleContacts = $this->vehicleContacts($entity, $other);
                if (!$this->allowsVehicleContacts($vehicleContacts, $relativeSpeed)) {
                    continue;
                }
                $this->push($entity, -$nx * $push, -$nz * $push);
                $this->push($other, $nx * $push, $nz * $push);
                $this->notifyVehicleContacts($vehicleContacts, $relativeSpeed);
                ++$contacts;
            }
        }

        return new EntityContactMetrics(
            $candidates,
            $pairs,
            $contacts,
            max(0, $this->clock->nanoseconds() - $start),
            $exhausted,
        );
    }

    /**
     * @return list<array{Vehicle, VehicleCollisionKind, AbstractEntity}>
     */
    private function vehicleContacts(AbstractEntity $left, AbstractEntity $right): array
    {
        $contacts = [];
        if ($left instanceof Vehicle) {
            $contacts[] = [$left, $right instanceof Vehicle
                ? VehicleCollisionKind::VEHICLE
                : VehicleCollisionKind::ENTITY, $right];
        }
        if ($right instanceof Vehicle) {
            $contacts[] = [$right, $left instanceof Vehicle
                ? VehicleCollisionKind::VEHICLE
                : VehicleCollisionKind::ENTITY, $left];
        }

        return $contacts;
    }

    /**
     * @param list<array{Vehicle, VehicleCollisionKind, AbstractEntity}> $contacts
     */
    private function allowsVehicleContacts(array $contacts, float $relativeSpeed): bool
    {
        if ($this->allowVehicleCollision === null) {
            return true;
        }
        foreach ($contacts as [$vehicle, $kind, $other]) {
            if (!(($this->allowVehicleCollision)($vehicle, $kind, $other, $relativeSpeed))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{Vehicle, VehicleCollisionKind, AbstractEntity}> $contacts
     */
    private function notifyVehicleContacts(array $contacts, float $relativeSpeed): void
    {
        if ($this->vehicleCollided === null) {
            return;
        }
        foreach ($contacts as [$vehicle, $kind, $other]) {
            ($this->vehicleCollided)($vehicle, $kind, $other, $relativeSpeed);
        }
    }

    private function push(AbstractEntity $entity, float $x, float $z): void
    {
        $motion = $entity->getMotion();
        $combinedX = $motion->x + $x;
        $combinedZ = $motion->z + $z;
        $combinedLength = hypot($combinedX, $combinedZ);
        $maximumLength = max(hypot($motion->x, $motion->z), self::MAXIMUM_PUSH);
        if ($combinedLength > $maximumLength) {
            $scale = $maximumLength / $combinedLength;
            $combinedX *= $scale;
            $combinedZ *= $scale;
        }
        $entity->applyMotion(new EntityMotion($combinedX, $motion->y, $combinedZ));
        if ($entity instanceof AbstractMobEntity && $entity->getActivationState() === MobActivationState::SLEEPING) {
            $entity->setActivationState(MobActivationState::REDUCED);
        }
    }

    private function overlapsVertically(AbstractEntity $left, AbstractEntity $right): bool
    {
        $leftY = $left->internalPosition()->y;
        $rightY = $right->internalPosition()->y;

        return $leftY < $rightY + $right->collisionHeight()
            && $rightY < $leftY + $left->collisionHeight();
    }

    private function sameMountAssembly(AbstractEntity $left, AbstractEntity $right): bool
    {
        if ($this->mounts === null) {
            return false;
        }
        $leftLink = $this->mounts->entityLink($left->getRuntimeId());
        $rightLink = $this->mounts->entityLink($right->getRuntimeId());

        return self::isVehicleOf($left, $rightLink)
            || self::isVehicleOf($right, $leftLink)
            || ($leftLink !== null && $rightLink !== null && $leftLink->vehicle === $rightLink->vehicle);
    }

    private static function isVehicleOf(AbstractEntity $entity, ?MountLink $link): bool
    {
        return $link !== null && $link->vehicle === $entity;
    }
}
