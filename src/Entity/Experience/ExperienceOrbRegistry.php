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
use InvalidArgumentException;
use OverflowException;

/** Bounded authoritative owner for live experience-orb actors. */
final class ExperienceOrbRegistry
{
    public const int DEFAULT_CAPACITY = 4_096;
    public const int MAX_CAPACITY = 65_536;
    public const int MAX_TICK_ADVANCE = 1_200;
    public const int MAX_TARGETS = 256;
    public const float GRAVITY = 0.04;
    public const float DRAG = 0.02;
    public const float ATTRACTION_RADIUS = 8.0;
    public const float PICKUP_RADIUS = 0.75;
    public const float MERGE_RADIUS = 0.5;
    public const int TARGET_SEARCH_INTERVAL_TICKS = 20;
    public const int MERGE_INTERVAL_TICKS = 20;

    /** @var list<int> Largest to smallest, matching normal client orb sizes. */
    public const array SPLIT_SIZES = [2477, 1237, 617, 307, 149, 73, 37, 17, 7, 3, 1];

    /** @var array<int, ExperienceOrbEntity> Runtime-ID keyed actors. */
    private array $entities = [];
    private int $nextEntityId;
    private int $elapsedTicks = 0;

    public function __construct(private readonly int $capacity = self::DEFAULT_CAPACITY, int $firstEntityId = 1)
    {
        if ($capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw new InvalidArgumentException('Experience-orb registry capacity is outside its supported range.');
        }
        if ($firstEntityId < 1 || $firstEntityId >= PHP_INT_MAX) {
            throw new InvalidArgumentException('First experience-orb entity ID is outside its supported range.');
        }
        $this->nextEntityId = $firstEntityId;
    }

    public function count(): int
    {
        return count($this->entities);
    }

    public function remainingCapacity(): int
    {
        return $this->capacity - count($this->entities);
    }

    public function get(int $runtimeEntityId): ?ExperienceOrbEntity
    {
        return $this->entities[$runtimeEntityId] ?? null;
    }

    /** @return list<ExperienceOrbEntity> */
    public function all(): array
    {
        return array_values($this->entities);
    }

    public function remove(int $runtimeEntityId): ?ExperienceOrbEntity
    {
        $entity = $this->entities[$runtimeEntityId] ?? null;
        unset($this->entities[$runtimeEntityId]);

        return $entity;
    }

    public function spawn(
        int $value,
        Position $position,
        ?ExperienceOrbMotion $motion = null,
        int $pickupDelayTicks = 0,
        ?int $despawnAfterTicks = ExperienceOrbEntity::DEFAULT_DESPAWN_TICKS,
    ): ExperienceOrbEntity {
        if (count($this->entities) >= $this->capacity) {
            throw new OverflowException('Experience-orb registry capacity is exhausted.');
        }
        if ($this->nextEntityId < 1 || $this->nextEntityId === PHP_INT_MAX) {
            throw new OverflowException('Experience-orb entity ID space is exhausted.');
        }
        $id = $this->nextEntityId++;
        $entity = new ExperienceOrbEntity(
            $id,
            $id,
            $value,
            $position,
            $motion ?? new ExperienceOrbMotion(),
            pickupDelayTicks: $pickupDelayTicks,
            despawnAfterTicks: $despawnAfterTicks,
        );
        $this->entities[$id] = $entity;

        return $entity;
    }

    /**
     * Splits a total award into standard orb values and admits the complete batch atomically.
     *
     * @return list<ExperienceOrbEntity>
     */
    public function spawnSplit(
        int $totalExperience,
        Position $position,
        ?ExperienceOrbMotion $motion = null,
        int $pickupDelayTicks = 0,
        ?int $despawnAfterTicks = ExperienceOrbEntity::DEFAULT_DESPAWN_TICKS,
    ): array {
        $values = self::splitValues($totalExperience);
        if (count($values) > $this->remainingCapacity()
            || $this->nextEntityId > PHP_INT_MAX - count($values)) {
            throw new OverflowException('Experience-orb batch exceeds registry capacity.');
        }
        $spawned = [];
        foreach ($values as $value) {
            $spawned[] = $this->spawn($value, $position, $motion, $pickupDelayTicks, $despawnAfterTicks);
        }

        return $spawned;
    }

    /** @return list<int> */
    public static function splitValues(int $totalExperience): array
    {
        if ($totalExperience < 1 || $totalExperience > 0x7fffffff) {
            throw new InvalidArgumentException('Total experience must be between 1 and 2147483647.');
        }
        $result = [];
        while ($totalExperience > 0) {
            foreach (self::SPLIT_SIZES as $size) {
                if ($totalExperience >= $size) {
                    $result[] = $size;
                    if (count($result) > self::MAX_CAPACITY) {
                        throw new OverflowException('Experience award requires too many orb actors.');
                    }
                    $totalExperience -= $size;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @param list<ExperienceOrbTarget> $targets
     */
    public function tick(
        array $targets = [],
        ?ExperienceOrbCollisionResolver $collisions = null,
        int $ticks = 1,
    ): ExperienceOrbTickResult {
        if ($ticks < 1 || $ticks > self::MAX_TICK_ADVANCE || count($targets) > self::MAX_TARGETS) {
            throw new InvalidArgumentException('Experience-orb tick input is outside its supported bounds.');
        }
        $targetsBySession = [];
        foreach ($targets as $target) {
            if (isset($targetsBySession[$target->sessionId])) {
                throw new InvalidArgumentException('Experience-orb targets must be unique typed projections.');
            }
            $targetsBySession[$target->sessionId] = $target;
        }

        $updated = [];
        $removed = [];
        $pickups = [];
        for ($step = 0; $step < $ticks; ++$step) {
            ++$this->elapsedTicks;
            foreach ($this->entities as $runtimeId => $before) {
                $target = $this->selectTarget($before, $targetsBySession);
                $before = $before->withTarget(
                    $target?->sessionId,
                    $before->targetSearchDelayTicks === 0
                        ? self::TARGET_SEARCH_INTERVAL_TICKS
                        : $before->targetSearchDelayTicks,
                );
                $entity = $before->advance($target, self::GRAVITY, self::DRAG, self::ATTRACTION_RADIUS);
                if ($collisions !== null) {
                    $entity = $collisions->resolve($before, $entity);
                }
                if ($entity->hasExpired()) {
                    unset($this->entities[$runtimeId], $updated[$runtimeId]);
                    $removed[$runtimeId] = $entity;
                    continue;
                }
                $this->entities[$runtimeId] = $entity;
                $updated[$runtimeId] = $entity;

                if ($target !== null && $target->canPickup && $entity->canBePickedUp()
                    && self::distanceSquared($entity->position, $target->pickupPosition)
                        <= self::PICKUP_RADIUS ** 2) {
                    $remaining = $entity->orbCount > 1 ? $entity->withOrbCount($entity->orbCount - 1) : null;
                    $pickups[] = new ExperienceOrbPickupResult(
                        $runtimeId,
                        $target->sessionId,
                        $target->runtimeActorId,
                        $entity->value,
                        $remaining === null,
                        $remaining,
                    );
                    if ($remaining === null) {
                        unset($this->entities[$runtimeId], $updated[$runtimeId]);
                        $removed[$runtimeId] = $entity;
                    } else {
                        $this->entities[$runtimeId] = $remaining;
                        $updated[$runtimeId] = $remaining;
                    }
                }
            }
            if ($this->elapsedTicks % self::MERGE_INTERVAL_TICKS === 0) {
                foreach ($this->mergeNearby() as $merged) {
                    $removed[$merged->runtimeEntityId] = $merged;
                    unset($updated[$merged->runtimeEntityId]);
                }
                foreach ($this->entities as $runtimeId => $entity) {
                    $updated[$runtimeId] = $entity;
                }
            }
        }

        return new ExperienceOrbTickResult(array_values($updated), array_values($removed), $pickups);
    }

    /**
     * @param array<string, ExperienceOrbTarget> $targets
     */
    private function selectTarget(ExperienceOrbEntity $entity, array $targets): ?ExperienceOrbTarget
    {
        $current = $entity->targetSessionId !== null ? ($targets[$entity->targetSessionId] ?? null) : null;
        if ($current !== null && (!$current->eligibleForAttraction()
            || self::distanceSquared($entity->position, $current->pickupPosition) > self::ATTRACTION_RADIUS ** 2)) {
            $current = null;
        }
        if ($entity->targetSearchDelayTicks > 0) {
            return $current;
        }
        $closestDistance = $current === null
            ? self::ATTRACTION_RADIUS ** 2
            : self::distanceSquared($entity->position, $current->pickupPosition);
        foreach ($targets as $target) {
            if (!$target->eligibleForAttraction()) {
                continue;
            }
            $distance = self::distanceSquared($entity->position, $target->pickupPosition);
            if ($distance < $closestDistance
                || ($distance === $closestDistance && $current !== null
                    && strcmp($target->sessionId, $current->sessionId) < 0)) {
                $current = $target;
                $closestDistance = $distance;
            }
        }

        return $current;
    }

    /** @return list<ExperienceOrbEntity> Actors removed into a surviving aggregate. */
    private function mergeNearby(): array
    {
        /** @var array<string, list<int>> $buckets */
        $buckets = [];
        foreach ($this->entities as $runtimeId => $entity) {
            $key = self::bucketKey($entity->position);
            $buckets[$key][] = $runtimeId;
        }
        $removed = [];
        foreach (array_keys($this->entities) as $runtimeId) {
            $survivor = $this->get($runtimeId);
            if ($survivor === null) {
                continue;
            }
            [$bucketX, $bucketY, $bucketZ] = self::bucketCoordinates($survivor->position);
            for ($x = $bucketX - 1; $x <= $bucketX + 1; ++$x) {
                for ($y = $bucketY - 1; $y <= $bucketY + 1; ++$y) {
                    for ($z = $bucketZ - 1; $z <= $bucketZ + 1; ++$z) {
                        foreach ($buckets[$x . ':' . $y . ':' . $z] ?? [] as $candidateId) {
                            if ($candidateId <= $runtimeId) {
                                continue;
                            }
                            $candidate = $this->entities[$candidateId] ?? null;
                            if ($candidate === null || $candidate->value !== $survivor->value
                                || $survivor->orbCount + $candidate->orbCount > 32_767
                                || self::distanceSquared($survivor->position, $candidate->position)
                                    > self::MERGE_RADIUS ** 2) {
                                continue;
                            }
                            $survivor = $survivor->withOrbCount(
                                $survivor->orbCount + $candidate->orbCount,
                                min($survivor->ageTicks, $candidate->ageTicks),
                            );
                            $this->entities[$runtimeId] = $survivor;
                            unset($this->entities[$candidateId]);
                            $removed[] = $candidate;
                        }
                    }
                }
            }
        }

        return $removed;
    }

    private static function distanceSquared(Position $left, Position $right): float
    {
        $x = $left->x - $right->x;
        $y = $left->y - $right->y;
        $z = $left->z - $right->z;

        return ($x * $x) + ($y * $y) + ($z * $z);
    }

    private static function bucketKey(Position $position): string
    {
        return implode(':', self::bucketCoordinates($position));
    }

    /** @return array{int, int, int} */
    private static function bucketCoordinates(Position $position): array
    {
        return [
            (int) floor($position->x / self::MERGE_RADIUS),
            (int) floor($position->y / self::MERGE_RADIUS),
            (int) floor($position->z / self::MERGE_RADIUS),
        ];
    }
}
