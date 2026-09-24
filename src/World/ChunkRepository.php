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

    /** @var array<string, int> Monotonic order in which cached chunks first became dirty. */
    private array $dirtySince = [];

    private int $accessSequence = 0;

    private int $dirtySequence = 0;

    private int $hits = 0;

    private int $misses = 0;

    private int $evictions = 0;

    public function __construct(private readonly int $capacity)
    {
        if ($capacity < 1 || $capacity > 100_000) {
            throw new InvalidArgumentException('Chunk cache capacity must be between 1 and 100000.');
        }
    }

    /**
     * @param callable(ChunkPosition): Chunk $loader
     * @param null|callable(Chunk): void $saver
     */
    public function get(ChunkPosition $position, callable $loader, ?callable $saver = null): Chunk
    {
        $key = $position->key();
        $cached = $this->chunks[$key] ?? null;
        if ($cached instanceof Chunk) {
            ++$this->hits;
            $this->touch($key);

            return $cached;
        }
        ++$this->misses;

        $evictionCandidate = count($this->chunks) >= $this->capacity
            ? $this->leastRecentlyUsedEvictionCandidate($saver === null)
            : null;
        $chunk = $loader($position);
        if ($chunk->position->x !== $position->x || $chunk->position->z !== $position->z) {
            throw new UnexpectedValueException('Chunk loader returned a chunk for the wrong position.');
        }
        if ($evictionCandidate !== null) {
            $this->saveForEviction($evictionCandidate, $saver);
            $this->remove($evictionCandidate);
        }
        $this->chunks[$key] = $chunk;
        $this->trackDirtyState($key, $chunk);
        $this->touch($key);

        return $chunk;
    }

    /**
     * @param callable(ChunkPosition): Chunk $loader
     * @param null|callable(Chunk): void $saver
     */
    public function retain(ChunkPosition $position, callable $loader, ?callable $saver = null): Chunk
    {
        $chunk = $this->get($position, $loader, $saver);
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

    public function isRetained(ChunkPosition $position): bool
    {
        return ($this->retainCounts[$position->key()] ?? 0) > 0;
    }

    /** Returns the currently loaded immutable snapshot without changing its LRU position. */
    public function loaded(ChunkPosition $position): ?Chunk
    {
        return $this->chunks[$position->key()] ?? null;
    }

    /**
     * Removes a clean unretained chunk and all repository bookkeeping.
     *
     * Dirty or retained chunks fail closed so callers cannot discard authoritative state.
     */
    public function evictIfCleanAndUnretained(ChunkPosition $position): bool
    {
        $key = $position->key();
        $chunk = $this->chunks[$key] ?? null;
        if (!$chunk instanceof Chunk || $chunk->isDirty() || ($this->retainCounts[$key] ?? 0) > 0) {
            return false;
        }

        $this->remove($key);

        return true;
    }

    /** Replaces an already generated chunk without changing its retention ownership. */
    public function replace(Chunk $chunk): void
    {
        $key = $chunk->position->key();
        if (!isset($this->chunks[$key])) {
            throw new InvalidArgumentException('Cannot replace a chunk which has not been generated.');
        }
        $this->chunks[$key] = $chunk;
        $this->trackDirtyState($key, $chunk);
        $this->touch($key);
    }

    /**
     * Saves at most the requested number of dirty chunks, oldest-dirty first.
     *
     * The saver receives an immutable revision snapshot. Its successful return acknowledges exactly that revision;
     * a newer replacement installed during the save remains dirty.
     *
     * @param callable(Chunk): void $saver
     */
    public function saveDirty(int $maximumChunks, callable $saver): int
    {
        if ($maximumChunks < 1 || $maximumChunks > $this->capacity) {
            throw new InvalidArgumentException('Dirty chunk save limit must be between 1 and the cache capacity.');
        }

        $keys = array_keys($this->dirtySince);
        usort($keys, fn(string $left, string $right): int => $this->dirtySince[$left] <=> $this->dirtySince[$right]);
        $saved = 0;
        foreach ($keys as $key) {
            if ($saved >= $maximumChunks) {
                break;
            }
            if ($this->saveKey($key, $saver)) {
                ++$saved;
            }
        }

        return $saved;
    }

    /** Saves and acknowledges exactly the currently loaded revision for one coordinate. */
    public function save(ChunkPosition $position, callable $saver): bool
    {
        return $this->saveKey($position->key(), $saver);
    }

    /** @param callable(Chunk): void $saver */
    public function flush(callable $saver): int
    {
        $saved = 0;
        while ($this->dirtySince !== []) {
            $before = count($this->dirtySince);
            $saved += $this->saveDirty($this->capacity, $saver);
            if (count($this->dirtySince) >= $before) {
                throw new UnexpectedValueException('Chunk revisions changed while the cache was being flushed.');
            }
        }

        return $saved;
    }

    public function dirtyCount(): int
    {
        return count($this->dirtySince);
    }

    /**
     * @param array<string, int> $pendingRevisions Newest revision already queued or in flight per chunk key.
     *
     * @return list<Chunk> Immutable oldest-dirty snapshots suitable for bounded persistence submission.
     */
    public function dirtySnapshots(int $maximumChunks, array $pendingRevisions = []): array
    {
        if ($maximumChunks < 1 || $maximumChunks > $this->capacity) {
            throw new InvalidArgumentException('Dirty chunk snapshot limit must be between 1 and the cache capacity.');
        }

        $keys = array_keys($this->dirtySince);
        usort($keys, fn(string $left, string $right): int => $this->dirtySince[$left] <=> $this->dirtySince[$right]);
        $snapshots = [];
        foreach ($keys as $key) {
            $chunk = $this->chunks[$key] ?? null;
            if ($chunk instanceof Chunk && $chunk->isDirty()) {
                if (($pendingRevisions[$key] ?? -1) >= $chunk->revision) {
                    continue;
                }
                $snapshots[] = $chunk;
                if (count($snapshots) >= $maximumChunks) {
                    break;
                }
            }
        }

        return $snapshots;
    }

    /** Applies an exact durable completion without allowing an older revision to clean newer state. */
    public function acknowledgePersisted(ChunkPosition $position, int $revision): bool
    {
        $key = $position->key();
        $current = $this->chunks[$key] ?? null;
        if (!$current instanceof Chunk) {
            throw new InvalidArgumentException('Cannot acknowledge a chunk which is not loaded.');
        }
        if ($revision > $current->revision) {
            throw new UnexpectedValueException('A persisted chunk revision leads authoritative state.');
        }
        if ($revision <= $current->persistedRevision) {
            return false;
        }

        $acknowledged = $current->withPersistedRevision($revision);
        $this->chunks[$key] = $acknowledged;
        $this->trackDirtyState($key, $acknowledged);

        return true;
    }

    public function count(): int
    {
        return count($this->chunks);
    }

    public function snapshot(): ChunkRepositorySnapshot
    {
        return new ChunkRepositorySnapshot(
            $this->capacity,
            count($this->chunks),
            count($this->retainCounts),
            array_sum($this->retainCounts),
            count($this->dirtySince),
            $this->hits,
            $this->misses,
            $this->evictions,
        );
    }

    public function clear(): void
    {
        if ($this->retainCounts !== []) {
            throw new OverflowException('Cannot clear a chunk cache while chunks are retained.');
        }
        $this->chunks = [];
        $this->lastAccess = [];
        $this->dirtySince = [];
        $this->accessSequence = 0;
        $this->dirtySequence = 0;
        $this->hits = 0;
        $this->misses = 0;
        $this->evictions = 0;
    }

    private function touch(string $key): void
    {
        $this->lastAccess[$key] = ++$this->accessSequence;
    }

    private function leastRecentlyUsedEvictionCandidate(bool $cleanOnly = false): string
    {
        $candidate = null;
        $oldestAccess = PHP_INT_MAX;
        foreach ($this->lastAccess as $key => $access) {
            if (($this->retainCounts[$key] ?? 0) === 0
                && (!$cleanOnly || !$this->chunks[$key]->isDirty())
                && $access < $oldestAccess) {
                $candidate = $key;
                $oldestAccess = $access;
            }
        }
        if ($candidate === null) {
            throw new OverflowException($cleanOnly
                ? 'Chunk cache capacity is exhausted by retained or dirty chunks awaiting persistence.'
                : 'Chunk cache capacity is exhausted by retained chunks.');
        }

        return $candidate;
    }

    /** @param null|callable(Chunk): void $saver */
    private function saveForEviction(string $key, ?callable $saver): void
    {
        $chunk = $this->chunks[$key];
        if (!$chunk->isDirty()) {
            return;
        }
        if ($saver === null) {
            throw new OverflowException('Cannot evict a dirty chunk without a persistence saver.');
        }
        $this->saveKey($key, $saver);
        if (($this->chunks[$key] ?? null)?->isDirty() === true) {
            throw new OverflowException('Cannot evict a chunk which changed while it was being saved.');
        }
    }

    /** @param callable(Chunk): void $saver */
    private function saveKey(string $key, callable $saver): bool
    {
        $snapshot = $this->chunks[$key] ?? null;
        if (!$snapshot instanceof Chunk || !$snapshot->isDirty()) {
            unset($this->dirtySince[$key]);

            return false;
        }

        $saver($snapshot);
        if (!array_key_exists($key, $this->chunks)) {
            throw new UnexpectedValueException('A chunk disappeared while its save was in progress.');
        }
        $current = $this->chunks[$key];
        if ($current->revision < $snapshot->revision) {
            throw new UnexpectedValueException('A chunk revision moved backwards while its save was in progress.');
        }

        $this->acknowledgePersisted($snapshot->position, $snapshot->revision);

        return true;
    }

    private function trackDirtyState(string $key, Chunk $chunk): void
    {
        if (!$chunk->isDirty()) {
            unset($this->dirtySince[$key]);

            return;
        }
        $this->dirtySince[$key] ??= ++$this->dirtySequence;
    }

    private function remove(string $key): void
    {
        unset($this->chunks[$key], $this->lastAccess[$key], $this->retainCounts[$key], $this->dirtySince[$key]);
        ++$this->evictions;
    }
}
