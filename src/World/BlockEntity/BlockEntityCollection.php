<?php

declare(strict_types=1);

namespace Bedriox\Server\World\BlockEntity;

use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Countable;
use InvalidArgumentException;

/** Immutable, position-indexed block entities owned by exactly one chunk. */
final readonly class BlockEntityCollection implements Countable
{
    public const int MAXIMUM_ENTITIES = 1_024;

    /** @var array<string, BlockEntity> */
    private array $entities;

    /** @param list<mixed> $entities */
    public function __construct(public ChunkPosition $position, array $entities = [])
    {
        if (count($entities) > self::MAXIMUM_ENTITIES) {
            throw new InvalidArgumentException('Chunk block-entity count exceeds its configured limit.');
        }
        $indexed = [];
        foreach ($entities as $entity) {
            if (!$entity instanceof BlockEntity || !self::belongsTo($entity->position, $position)) {
                throw new InvalidArgumentException('Block entity does not belong to its owning chunk.');
            }
            $key = self::key($entity->position);
            if (isset($indexed[$key])) {
                throw new InvalidArgumentException('Chunk contains more than one block entity at a position.');
            }
            $indexed[$key] = $entity;
        }
        ksort($indexed, SORT_STRING);
        $this->entities = $indexed;
    }

    public function at(BlockPosition $position): ?BlockEntity
    {
        if (!self::belongsTo($position, $this->position)) {
            return null;
        }

        return $this->entities[self::key($position)] ?? null;
    }

    /** @return list<BlockEntity> */
    public function all(): array
    {
        return array_values($this->entities);
    }

    public function with(BlockEntity $entity): self
    {
        if (!self::belongsTo($entity->position, $this->position)) {
            throw new InvalidArgumentException('Block entity does not belong to its owning chunk.');
        }
        $key = self::key($entity->position);
        if (($this->entities[$key] ?? null) === $entity) {
            return $this;
        }
        $entities = $this->entities;
        $entities[$key] = $entity;

        return new self($this->position, array_values($entities));
    }

    public function without(BlockPosition $position): self
    {
        $key = self::key($position);
        if (!isset($this->entities[$key])) {
            return $this;
        }
        $entities = $this->entities;
        unset($entities[$key]);

        return new self($this->position, array_values($entities));
    }

    public function count(): int
    {
        return count($this->entities);
    }

    private static function belongsTo(BlockPosition $block, ChunkPosition $chunk): bool
    {
        return (int) floor($block->x / 16) === $chunk->x && (int) floor($block->z / 16) === $chunk->z;
    }

    private static function key(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }
}
