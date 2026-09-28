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

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityType;
use Bedriox\Server\Simulation\Position;
use Closure;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/** Bounded authoritative owner for general entity identity and spatial admission. */
final class EntityRegistry
{
    public const int DEFAULT_CAPACITY = EntitySpatialIndex::DEFAULT_CAPACITY;
    public const int MAX_CAPACITY = EntitySpatialIndex::MAX_CAPACITY;

    /** @var array<int, AbstractEntity> */
    private array $byRuntimeId = [];

    /** @var array<string, AbstractEntity> */
    private array $byUniqueId = [];

    /** @var array<string, int> */
    private array $categoryCounts = [];

    /** @var array<string, int> */
    private array $typeCounts = [];

    private readonly EntitySpatialIndex $spatialIndex;

    private int $nextRuntimeId;

    public function __construct(
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        int $firstRuntimeId = 1,
    ) {
        if ($capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw new InvalidArgumentException('Entity-registry capacity is outside its supported range.');
        }
        if ($firstRuntimeId < 1 || $firstRuntimeId >= PHP_INT_MAX) {
            throw new InvalidArgumentException('First entity runtime ID is outside its supported range.');
        }
        $this->nextRuntimeId = $firstRuntimeId;
        $this->spatialIndex = new EntitySpatialIndex($capacity);
    }

    public function count(): int
    {
        return count($this->byRuntimeId);
    }

    public function remainingCapacity(): int
    {
        return $this->capacity - count($this->byRuntimeId);
    }

    public function canSpawn(): bool
    {
        return count($this->byRuntimeId) < $this->capacity
            && $this->nextRuntimeId > 0
            && $this->nextRuntimeId < PHP_INT_MAX;
    }

    public function getByRuntimeId(int $runtimeId): ?AbstractEntity
    {
        return $this->byRuntimeId[$runtimeId] ?? null;
    }

    public function getByUniqueId(string $uniqueId): ?AbstractEntity
    {
        return $this->byUniqueId[EntityUuid::validate($uniqueId)] ?? null;
    }

    /** @return list<AbstractEntity> */
    public function all(): array
    {
        // Runtime IDs are allocated monotonically and PHP preserves insertion
        // order when actors are removed or replaced, so this is already sorted.
        return array_values($this->byRuntimeId);
    }

    /**
     * @param Closure(string, int): AbstractEntity $factory Receives canonical UUID, then runtime ID.
     */
    public function spawn(Closure $factory, ?string $uniqueId = null): AbstractEntity
    {
        if (count($this->byRuntimeId) >= $this->capacity) {
            throw new OverflowException('Entity-registry capacity is exhausted.');
        }
        if ($this->nextRuntimeId < 1 || $this->nextRuntimeId >= PHP_INT_MAX) {
            throw new OverflowException('Entity runtime-ID space is exhausted.');
        }

        $canonicalUniqueId = EntityUuid::validate($uniqueId ?? EntityUuid::random());
        if (isset($this->byUniqueId[$canonicalUniqueId])) {
            throw new LogicException('Entity UUID is already registered.');
        }

        $runtimeId = $this->nextRuntimeId++;
        $entity = $factory($canonicalUniqueId, $runtimeId);
        if ($entity->getUniqueId() !== $canonicalUniqueId || $entity->getRuntimeId() !== $runtimeId) {
            throw new LogicException('Entity factory did not preserve its allocated identity.');
        }
        if ($entity->isRemoved()) {
            throw new LogicException('Removed entities cannot be admitted to the registry.');
        }

        $this->spatialIndex->add($entity);
        $this->byRuntimeId[$runtimeId] = $entity;
        $this->byUniqueId[$canonicalUniqueId] = $entity;
        $this->incrementCounts($entity);

        return $entity;
    }

    public function replace(AbstractEntity $entity): void
    {
        $runtimeId = $entity->getRuntimeId();
        $existing = $this->byRuntimeId[$runtimeId] ?? null;
        if ($existing === null || ($this->byUniqueId[$entity->getUniqueId()] ?? null) !== $existing) {
            throw new InvalidArgumentException('Cannot replace an entity which is not registered.');
        }
        if ($entity->isRemoved()) {
            throw new LogicException('Removed entities cannot replace a registered entity.');
        }
        if ($existing->getUniqueId() !== $entity->getUniqueId()
            || $existing->getType()->identifier() !== $entity->getType()->identifier()
            || $existing->getCategory() !== $entity->getCategory()) {
            throw new LogicException('Entity replacement must preserve identity, type, and category.');
        }

        $this->spatialIndex->replace($entity);
        $this->byRuntimeId[$runtimeId] = $entity;
        $this->byUniqueId[$entity->getUniqueId()] = $entity;
        if ($existing !== $entity) {
            $existing->remove();
        }
    }

    public function reindex(AbstractEntity $entity): void
    {
        if (($this->byRuntimeId[$entity->getRuntimeId()] ?? null) !== $entity) {
            throw new InvalidArgumentException('Only the registered entity instance can be reindexed.');
        }
        $this->spatialIndex->reindex($entity);
    }

    public function move(
        int $runtimeId,
        string $worldName,
        Position $position,
        float $yaw,
        float $pitch,
    ): AbstractEntity {
        $entity = $this->byRuntimeId[$runtimeId] ?? null;
        if ($entity === null) {
            throw new InvalidArgumentException('Cannot move an entity which is not registered.');
        }

        $entity->moveTo($worldName, $position, $yaw, $pitch);
        $this->spatialIndex->reindex($entity);

        return $entity;
    }

    public function remove(int $runtimeId): ?AbstractEntity
    {
        $entity = $this->byRuntimeId[$runtimeId] ?? null;
        if ($entity === null) {
            return null;
        }

        $indexed = $this->spatialIndex->remove($runtimeId);
        if ($indexed !== $entity) {
            throw new LogicException('Entity spatial index disagrees with registry ownership.');
        }
        unset($this->byRuntimeId[$runtimeId], $this->byUniqueId[$entity->getUniqueId()]);
        $this->decrementCounts($entity);
        $entity->remove();

        return $entity;
    }

    public function countByCategory(EntityCategory $category): int
    {
        return $this->categoryCounts[$category->value] ?? 0;
    }

    public function countByType(EntityType $type): int
    {
        return $this->typeCounts[$type->identifier()] ?? 0;
    }

    /** @return list<AbstractEntity> */
    public function nearby(
        string $worldName,
        Position $center,
        float $radius,
        int $limit = 64,
        ?EntityCategory $category = null,
        ?EntityType $type = null,
    ): array {
        $typeIdentifier = $type?->identifier();
        $filter = $category === null && $typeIdentifier === null
            ? null
            : static fn(AbstractEntity $entity): bool =>
                ($category === null || $entity->getCategory() === $category)
                && ($typeIdentifier === null || $entity->getType()->identifier() === $typeIdentifier);

        return $this->spatialIndex->nearby($worldName, $center, $radius, $limit, $filter);
    }

    private function incrementCounts(AbstractEntity $entity): void
    {
        $category = $entity->getCategory()->value;
        $type = $entity->getType()->identifier();
        $this->categoryCounts[$category] = ($this->categoryCounts[$category] ?? 0) + 1;
        $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;
    }

    private function decrementCounts(AbstractEntity $entity): void
    {
        self::decrement($this->categoryCounts, $entity->getCategory()->value);
        self::decrement($this->typeCounts, $entity->getType()->identifier());
    }

    /** @param array<string, int> $counts */
    private static function decrement(array &$counts, string $key): void
    {
        $count = $counts[$key] ?? 0;
        if ($count < 1) {
            throw new LogicException('Entity registry count underflow.');
        }
        if ($count === 1) {
            unset($counts[$key]);
        } else {
            $counts[$key] = $count - 1;
        }
    }
}
