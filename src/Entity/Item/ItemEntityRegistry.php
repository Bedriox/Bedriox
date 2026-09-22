<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Item;

use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use OverflowException;

/** Bounded authoritative owner for dropped-item lifecycle and movement. */
final class ItemEntityRegistry
{
    public const int DEFAULT_CAPACITY = 4_096;
    public const int MAX_CAPACITY = 65_536;
    public const int MAX_TICK_ADVANCE = 1_200;
    public const int MAX_PICKUP_CANDIDATES = 256;
    public const float GRAVITY = 0.04;
    public const float DRAG = 0.02;

    /** @var array<int, DroppedItemEntity> Runtime ID keyed entities. */
    private array $entities = [];

    private int $nextEntityId;

    public function __construct(
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        int $firstEntityId = 1,
    ) {
        if ($capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw new InvalidArgumentException('Item-entity registry capacity is outside its supported range.');
        }
        if ($firstEntityId < 1 || $firstEntityId >= PHP_INT_MAX) {
            throw new InvalidArgumentException('First item-entity ID is outside its supported range.');
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

    public function canSpawn(): bool
    {
        return count($this->entities) < $this->capacity
            && $this->nextEntityId > 0 && $this->nextEntityId < PHP_INT_MAX;
    }

    public function get(int $runtimeEntityId): ?DroppedItemEntity
    {
        return $this->entities[$runtimeEntityId] ?? null;
    }

    public function replace(DroppedItemEntity $entity): void
    {
        if (!isset($this->entities[$entity->runtimeEntityId])) {
            throw new InvalidArgumentException('Cannot replace an item entity which is not registered.');
        }
        $this->entities[$entity->runtimeEntityId] = $entity;
    }

    public function remove(int $runtimeEntityId): ?DroppedItemEntity
    {
        $entity = $this->entities[$runtimeEntityId] ?? null;
        if ($entity !== null) {
            unset($this->entities[$runtimeEntityId]);
        }

        return $entity;
    }

    /** @return list<DroppedItemEntity> */
    public function all(): array
    {
        return array_values($this->entities);
    }

    public function spawn(
        InventoryStack $stack,
        Position $position,
        ?ItemEntityMotion $motion = null,
        int $pickupDelayTicks = 0,
        ?int $despawnAfterTicks = DroppedItemEntity::DEFAULT_DESPAWN_TICKS,
    ): DroppedItemEntity {
        if (count($this->entities) >= $this->capacity) {
            throw new OverflowException('Item-entity registry capacity is exhausted.');
        }
        if ($this->nextEntityId < 1 || $this->nextEntityId === PHP_INT_MAX) {
            throw new OverflowException('Item-entity ID space is exhausted.');
        }
        $id = $this->nextEntityId++;
        $entity = new DroppedItemEntity(
            $id,
            $id,
            $stack,
            $position,
            $motion ?? new ItemEntityMotion(0.0, 0.0, 0.0),
            $pickupDelayTicks,
            despawnAfterTicks: $despawnAfterTicks,
        );
        $this->entities[$id] = $entity;

        return $entity;
    }

    public function tick(int $ticks = 1): ItemEntityTickResult
    {
        if ($ticks < 1 || $ticks > self::MAX_TICK_ADVANCE) {
            throw new InvalidArgumentException('Item-entity tick advance is outside its supported range.');
        }
        $updated = [];
        $despawned = [];
        for ($tick = 0; $tick < $ticks; ++$tick) {
            foreach ($this->entities as $runtimeId => $entity) {
                $entity = $entity->tick(self::GRAVITY, self::DRAG);
                if ($entity->hasExpired()) {
                    unset($this->entities[$runtimeId]);
                    $despawned[$runtimeId] = $entity;
                    unset($updated[$runtimeId]);
                } else {
                    $this->entities[$runtimeId] = $entity;
                    $updated[$runtimeId] = $entity;
                }
            }
        }

        return new ItemEntityTickResult(array_values($updated), array_values($despawned));
    }

    /** @return list<DroppedItemEntity> */
    public function nearbyPickupCandidates(Position $position, float $radius, int $limit = 64): array
    {
        if (!is_finite($radius) || $radius < 0.0 || $radius > 32.0
            || $limit < 1 || $limit > self::MAX_PICKUP_CANDIDATES) {
            throw new InvalidArgumentException('Item pickup query is outside its supported bounds.');
        }
        $radiusSquared = $radius * $radius;
        $candidates = [];
        foreach ($this->entities as $entity) {
            if (!$entity->canBePickedUp()) {
                continue;
            }
            $dx = $entity->position->x - $position->x;
            $dy = $entity->position->y - $position->y;
            $dz = $entity->position->z - $position->z;
            $distanceSquared = ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
            if ($distanceSquared <= $radiusSquared) {
                $candidates[] = [$distanceSquared, $entity];
            }
        }
        usort($candidates, static fn(array $left, array $right): int =>
            ($left[0] <=> $right[0]) ?: ($left[1]->runtimeEntityId <=> $right[1]->runtimeEntityId));

        return array_map(
            static fn(array $candidate): DroppedItemEntity => $candidate[1],
            array_slice($candidates, 0, $limit),
        );
    }

    public function pickup(int $runtimeEntityId, int $acceptedCount): ?ItemEntityPickupResult
    {
        $entity = $this->entities[$runtimeEntityId] ?? null;
        if ($entity === null || !$entity->canBePickedUp()) {
            return null;
        }
        if ($acceptedCount < 1 || $acceptedCount > $entity->stack->count) {
            throw new InvalidArgumentException('Accepted pickup count exceeds the dropped stack.');
        }
        $pickedUp = $entity->stack->withCountAndNetworkId($acceptedCount, $entity->stack->stackNetworkId);
        $remainingCount = $entity->stack->count - $acceptedCount;
        if ($remainingCount === 0) {
            unset($this->entities[$runtimeEntityId]);

            return new ItemEntityPickupResult($entity->uniqueEntityId, $runtimeEntityId, $pickedUp, null);
        }
        $remaining = $entity->stack->withCountAndNetworkId($remainingCount, $entity->stack->stackNetworkId);
        $this->entities[$runtimeEntityId] = $entity->withStack($remaining);

        return new ItemEntityPickupResult($entity->uniqueEntityId, $runtimeEntityId, $pickedUp, $remaining);
    }
}
