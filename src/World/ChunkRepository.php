<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use InvalidArgumentException;
use OverflowException;
use UnexpectedValueException;

/** Bounded process-local LRU cache. Retained chunks cannot be evicted while a player uses them. */
final class ChunkRepository
{
    /** @var array<string, Chunk> */
    private array $chunks = [];

    /** @var array<string, int> */
    private array $lastAccess = [];

    /** @var array<string, int> */
    private array $retainCounts = [];

    private int $accessSequence = 0;

    public function __construct(private readonly int $capacity)
    {
        if ($capacity < 1 || $capacity > 100_000) {
            throw new InvalidArgumentException('Chunk cache capacity must be between 1 and 100000.');
        }
    }

    /** @param callable(ChunkPosition): Chunk $loader */
    public function get(ChunkPosition $position, callable $loader): Chunk
    {
        $key = $position->key();
        $cached = $this->chunks[$key] ?? null;
        if ($cached instanceof Chunk) {
            $this->touch($key);

            return $cached;
        }

        $evictionCandidate = count($this->chunks) >= $this->capacity
            ? $this->leastRecentlyUsedEvictionCandidate()
            : null;
        $chunk = $loader($position);
        if ($chunk->position->x !== $position->x || $chunk->position->z !== $position->z) {
            throw new UnexpectedValueException('Chunk loader returned a chunk for the wrong position.');
        }
        if ($evictionCandidate !== null) {
            unset($this->chunks[$evictionCandidate], $this->lastAccess[$evictionCandidate]);
        }
        $this->chunks[$key] = $chunk;
        $this->touch($key);

        return $chunk;
    }

    /** @param callable(ChunkPosition): Chunk $loader */
    public function retain(ChunkPosition $position, callable $loader): Chunk
    {
        $chunk = $this->get($position, $loader);
        $key = $position->key();
        $this->retainCounts[$key] = ($this->retainCounts[$key] ?? 0) + 1;

        return $chunk;
    }

    public function release(ChunkPosition $position): void
    {
        $key = $position->key();
        $count = $this->retainCounts[$key] ?? 0;
        if ($count < 1) {
            throw new InvalidArgumentException('Cannot release a chunk that is not retained.');
        }
        if ($count === 1) {
            unset($this->retainCounts[$key]);

            return;
        }
        $this->retainCounts[$key] = $count - 1;
    }

    public function contains(ChunkPosition $position): bool
    {
        return isset($this->chunks[$position->key()]);
    }

    /** Replaces an already generated chunk without changing its retention ownership. */
    public function replace(Chunk $chunk): void
    {
        $key = $chunk->position->key();
        if (!isset($this->chunks[$key])) {
            throw new InvalidArgumentException('Cannot replace a chunk which has not been generated.');
        }
        $this->chunks[$key] = $chunk;
        $this->touch($key);
    }

    public function count(): int
    {
        return count($this->chunks);
    }

    public function clear(): void
    {
        if ($this->retainCounts !== []) {
            throw new OverflowException('Cannot clear a chunk cache while chunks are retained.');
        }
        $this->chunks = [];
        $this->lastAccess = [];
        $this->accessSequence = 0;
    }

    private function touch(string $key): void
    {
        $this->lastAccess[$key] = ++$this->accessSequence;
    }

    private function leastRecentlyUsedEvictionCandidate(): string
    {
        $candidate = null;
        $oldestAccess = PHP_INT_MAX;
        foreach ($this->lastAccess as $key => $access) {
            if (($this->retainCounts[$key] ?? 0) === 0 && $access < $oldestAccess) {
                $candidate = $key;
                $oldestAccess = $access;
            }
        }
        if ($candidate === null) {
            throw new OverflowException('Chunk cache capacity is exhausted by retained chunks.');
        }

        return $candidate;
    }
}
