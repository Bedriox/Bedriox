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

use Bedriox\Api\Entity\MobActivationState;
use Bedriox\Server\Entity\Ai\AiClock;
use Bedriox\Server\Entity\Ai\SystemAiClock;
use Bedriox\Server\Entity\Mount\MountLink;
use Bedriox\Server\Entity\Mount\MountRegistry;

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
    ) {}

    public function resolve(EntityWorkBudget $budget): EntityContactMetrics
    {
        $start = $this->clock->nanoseconds();
        $candidates = $pairs = $contacts = 0;
        $seen = [];
        $exhausted = false;

        foreach ($this->entities->all() as $entity) {
            if ($entity->isRemoved()) {
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
                if ($entity === $other || $other->isRemoved()) {
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
                if ($this->sameMountAssembly($entity, $other) || !$this->overlapsVertically($entity, $other)) {
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
                $this->push($entity, -$nx * $push, -$nz * $push);
                $this->push($other, $nx * $push, $nz * $push);
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
        $entity->setMotion(new EntityMotion($combinedX, $motion->y, $combinedZ));
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
