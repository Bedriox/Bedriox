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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldActions;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\World\ChunkRepositorySnapshot;
use Bedriox\Server\World\ChunkUnloadResult;
use InvalidArgumentException;
use LogicException;
use WeakReference;

/** Main-thread owner of the one canonical runtime for each loaded world. */
final class WorldRuntimeManager
{
    /** @var array<string, WorldStorageRuntime> */
    private array $loaded = [];
    /** @var array<string, array<string, World>> */
    private array $reservedHandles = [];
    /** @var array<string, true> */
    private array $loading = [];
    /** @var array<string, int> */
    private array $loadGenerations = [];
    private int $pollCursor = 0;

    public function __construct(
        private readonly string $defaultWorldId,
        WorldStorageRuntime $default,
        private readonly int $maximumLoadedWorlds = 64,
        private readonly ?WorldActions $actions = null,
    ) {
        $canonicalDefault = self::canonicalId($defaultWorldId);
        if ($canonicalDefault !== $default->root()->handle->id()) {
            throw new InvalidArgumentException('Default world runtime does not match the configured default ID.');
        }
        if ($maximumLoadedWorlds < 1 || $maximumLoadedWorlds > 1_024) {
            throw new InvalidArgumentException('Loaded world capacity must be between 1 and 1024.');
        }
        $this->loaded[$canonicalDefault] = $default;
        $this->loadGenerations[$canonicalDefault] = $default->root()->handle->loadGeneration();
    }

    public function default(DimensionType $dimension = DimensionType::OVERWORLD): ManagedWorldRuntime
    {
        return $this->get($this->defaultWorldId, $dimension)
            ?? throw new LogicException('The default world does not expose the requested dimension.');
    }

    public function get(
        string $worldId,
        DimensionType $dimension = DimensionType::OVERWORLD,
    ): ?ManagedWorldRuntime {
        $runtime = $this->loaded[self::canonicalId($worldId)] ?? null;

        return $runtime?->dimension($dimension);
    }

    /** @return list<ManagedWorldRuntime> */
    public function loaded(): array
    {
        return array_map(
            static fn(WorldStorageRuntime $storage): ManagedWorldRuntime => $storage->root(),
            array_values($this->loaded),
        );
    }

    /** @return list<ManagedWorldRuntime> */
    public function loadedDimensions(): array
    {
        $dimensions = [];
        foreach ($this->loaded as $storage) {
            array_push($dimensions, ...$storage->dimensions());
        }

        return $dimensions;
    }

    public function count(): int
    {
        return count($this->loaded);
    }

    public function loadedChunkCount(): int
    {
        return array_sum(array_map(
            static fn(ManagedWorldRuntime $runtime): int => $runtime->opened->world->loadedChunkCount(),
            $this->loadedDimensions(),
        ));
    }

    public function dirtyChunkCount(): int
    {
        return array_sum(array_map(
            static fn(ManagedWorldRuntime $runtime): int => $runtime->opened->world->dirtyChunkCount(),
            $this->loadedDimensions(),
        ));
    }

    public function generatingChunkCount(): int
    {
        return array_sum(array_map(
            static fn(ManagedWorldRuntime $runtime): int => $runtime->opened->world->generatingChunkCount(),
            $this->loadedDimensions(),
        ));
    }

    public function pendingChunkUnloadCount(): int
    {
        return array_sum(array_map(
            static fn(ManagedWorldRuntime $runtime): int => $runtime->opened->world->pendingChunkUnloadCount(),
            $this->loadedDimensions(),
        ));
    }

    public function processChunkUnloads(int $maximumChunks, int $timeBudgetMicroseconds): ChunkUnloadResult
    {
        $dimensions = $this->loadedDimensions();
        $remainingChunks = max(1, $maximumChunks);
        $remainingMicroseconds = max(1, $timeBudgetMicroseconds);
        $totals = [0, 0, 0];
        $saturated = false;
        $remainingQueued = 0;
        $remainingDimensions = count($dimensions);
        foreach ($dimensions as $runtime) {
            $chunkBudget = max(1, intdiv($remainingChunks, max(1, $remainingDimensions)));
            $timeBudget = max(1, intdiv($remainingMicroseconds, max(1, $remainingDimensions)));
            $result = $runtime->opened->world->processChunkUnloads($chunkBudget, $timeBudget);
            $totals[0] += $result->examined;
            $totals[1] += $result->evicted;
            $totals[2] += $result->saveSubmissions;
            $saturated = $saturated || $result->persistenceSaturated;
            $remainingQueued += $result->remainingQueued;
            $remainingChunks = max(0, $remainingChunks - $chunkBudget);
            $remainingMicroseconds = max(0, $remainingMicroseconds - $timeBudget);
            --$remainingDimensions;
        }

        return new ChunkUnloadResult(
            $totals[0],
            $totals[1],
            $totals[2],
            $saturated,
            $remainingQueued,
        );
    }

    public function chunkRepositorySnapshot(): ChunkRepositorySnapshot
    {
        $totals = [0, 0, 0, 0, 0, 0, 0, 0];
        foreach ($this->loadedDimensions() as $runtime) {
            $snapshot = $runtime->opened->world->chunkRepositorySnapshot();
            $totals[0] += $snapshot->capacity;
            $totals[1] += $snapshot->loaded;
            $totals[2] += $snapshot->retainedChunks;
            $totals[3] += $snapshot->retentionReferences;
            $totals[4] += $snapshot->dirty;
            $totals[5] += $snapshot->hits;
            $totals[6] += $snapshot->misses;
            $totals[7] += $snapshot->evictions;
        }

        return new ChunkRepositorySnapshot(...$totals);
    }

    public function persistenceQueueSnapshot(): ?PersistenceQueueSnapshot
    {
        $totals = [0, 0, 0, 0, 0, 0, 0];
        $available = false;
        foreach ($this->loaded as $storage) {
            $snapshot = $storage->root()->opened->world->persistenceQueueSnapshot();
            if ($snapshot === null) {
                continue;
            }
            $available = true;
            $totals[0] += $snapshot->queued;
            $totals[1] += $snapshot->inFlight;
            $totals[2] += $snapshot->completions;
            $totals[3] += $snapshot->requestBytes;
            $totals[4] += $snapshot->coalesced;
            $totals[5] += $snapshot->saturated;
            $totals[6] += $snapshot->failed;
        }

        return $available ? new PersistenceQueueSnapshot(...$totals) : null;
    }

    public function isLoading(string $worldId): bool
    {
        return isset($this->loading[self::canonicalId($worldId)]);
    }

    /**
     * Loads one generation synchronously on the main thread.
     *
     * The loading marker prevents a recursive or duplicate owner from opening a second provider.
     * The public asynchronous operation layer should coalesce callers before invoking this method.
     *
     * @param callable(World): WorldStorageRuntime $loader
     */
    public function load(string $worldId, callable $loader): ManagedWorldRuntime
    {
        $current = $this->get($worldId);
        if ($current !== null) {
            return $current;
        }
        $handle = $this->reserve($worldId);
        try {
            $storage = $loader($handle);
            return $this->attach($handle, $storage)->root();
        } finally {
            $this->abortReservation($handle);
        }
    }

    /** Reserves one load generation while storage preparation runs outside the main tick. */
    public function reserve(string $worldId): World
    {
        $id = $this->assertReservable($worldId);
        $generation = ($this->loadGenerations[$id] ?? 0) + 1;
        $manager = WeakReference::create($this);
        $handles = [];
        foreach (DimensionType::cases() as $dimension) {
            $handles[$dimension->value] = new World(
                $id,
                $generation,
                $this->actions,
                $dimension,
                static fn(World $_source, DimensionType $target): ?World => $manager->get()?->resolveHandle(
                    $id,
                    $generation,
                    $target,
                ),
            );
        }
        $this->reservedHandles[$id] = $handles;
        $this->loading[$id] = true;

        return $handles[DimensionType::OVERWORLD->value];
    }

    /** Validates load/create ownership before plugin pre-events are dispatched. */
    public function assertReservable(string $worldId): string
    {
        $id = self::canonicalId($worldId);
        if (isset($this->loaded[$id])) {
            throw new LogicException("World '$id' is already loaded.");
        }
        if (isset($this->loading[$id])) {
            throw new LogicException("World '$id' already has a lifecycle operation in progress.");
        }
        if (count($this->loaded) + count($this->loading) >= $this->maximumLoadedWorlds) {
            throw new LogicException('Loaded world capacity is exhausted.');
        }

        return $id;
    }

    /** Returns the authoritative runtime only when the handle is current. */
    public function assertCurrent(World $handle): ManagedWorldRuntime
    {
        return $this->runtimeForHandle($handle);
    }

    /** Validates every core unload rule before plugin pre-events are dispatched. */
    public function assertUnloadable(World $handle): WorldStorageRuntime
    {
        $id = $handle->id();
        if ($id === self::canonicalId($this->defaultWorldId)) {
            throw new LogicException('The default world cannot be unloaded.');
        }
        $runtime = $this->runtimeStorageForHandle($handle);
        if ($runtime->playerCount() !== 0) {
            throw new LogicException('An occupied world cannot be unloaded.');
        }

        return $runtime;
    }

    /** Publishes a completely prepared runtime on the authoritative thread. */
    public function attach(World $handle, WorldStorageRuntime $runtime): WorldStorageRuntime
    {
        $id = $handle->id();
        if (!isset($this->loading[$id])) {
            $runtime->close(false);
            throw new LogicException('World load reservation is no longer active.');
        }
        if (!$runtime->root()->handle->isSameLoad($handle)) {
            $runtime->close(false);
            throw new LogicException('World loader returned a runtime for a different identity or generation.');
        }
        $reserved = $this->reservedHandles[$id] ?? [];
        foreach ($runtime->dimensions() as $dimensionRuntime) {
            $dimension = $dimensionRuntime->handle->getDimensionType();
            if (($reserved[$dimension->value] ?? null) !== $dimensionRuntime->handle) {
                $runtime->close(false);
                throw new LogicException('World loader returned a non-canonical dimension handle.');
            }
        }
        if ($runtime->isClosed()) {
            throw new LogicException('World loader returned a closed runtime.');
        }
        $this->loaded[$id] = $runtime;
        $this->loadGenerations[$id] = $handle->loadGeneration();
        unset($this->loading[$id]);
        unset($this->reservedHandles[$id]);

        return $runtime;
    }

    public function abortReservation(World $handle): void
    {
        if (!isset($this->loaded[$handle->id()])) {
            unset($this->loading[$handle->id()]);
            unset($this->reservedHandles[$handle->id()]);
        }
    }

    public function save(World $handle): void
    {
        $this->runtimeStorageForHandle($handle)->save();
    }

    public function scheduleWorldDataSave(World $handle): PersistenceSubmission
    {
        return $this->runtimeStorageForHandle($handle)->scheduleWorldDataSave();
    }

    public function unload(World $handle, bool $save = true): void
    {
        $id = $handle->id();
        $this->assertUnloadable($handle);
        $this->runtimeStorageForHandle($handle)->close($save);
        unset($this->loaded[$id]);
        $this->pollCursor = min($this->pollCursor, max(0, count($this->loaded) - 1));
    }

    /**
     * Advances every loaded world from the default world's one authoritative server cadence.
     * World order rotates between polls so no world permanently absorbs the last share of a tick budget.
     *
     * @param null|callable(): void $beforeGlobalTick
     *
     * The Overworld retains the named-world key. Other dimensions use a private runtime key
     * that callers resolve through runtimeForPollKey(), never as a public world identifier.
     *
     * @return array<string, list<\Bedriox\Server\Simulation\SimulationTick>>
     */
    public function poll(?callable $beforeGlobalTick = null): array
    {
        $runtimes = $this->dimensionRuntimesByPollKey();
        $keys = array_keys($runtimes);
        $count = count($keys);
        if ($count === 0) {
            return [];
        }
        $start = $this->pollCursor % $count;
        $ticks = [];
        for ($offset = 0; $offset < $count; ++$offset) {
            $key = $keys[($start + $offset) % $count];
            $ticks[$key] = [];
        }
        $default = $this->default();
        $default->loop->pollCadence(function () use (&$ticks, $beforeGlobalTick, $runtimes): void {
            if ($beforeGlobalTick !== null) {
                $beforeGlobalTick();
            }
            foreach ($ticks as $key => $_) {
                $ticks[$key][] = $runtimes[$key]->simulation->tick();
            }
        });
        $this->pollCursor = ($start + 1) % $count;

        return $ticks;
    }

    public function nanosecondsUntilNextTick(): int
    {
        return $this->default()->nanosecondsUntilNextTick();
    }

    public function runtimeForPollKey(string $key): ?ManagedWorldRuntime
    {
        return $this->dimensionRuntimesByPollKey()[$key] ?? null;
    }

    public function closeAll(): void
    {
        $failure = null;
        $runtimes = array_reverse($this->loaded, true);
        foreach ($runtimes as $id => $storage) {
            if ($storage->playerCount() !== 0) {
                $failure ??= new LogicException("World '$id' still contains players during shutdown.");
                continue;
            }
            try {
                $storage->close(true);
                unset($this->loaded[$id]);
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public static function canonicalId(string $worldId): string
    {
        $id = strtolower(trim($worldId));
        $id = preg_replace('/ +/', '-', $id) ?? '';
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1 || $id === '.' || $id === '..') {
            throw new InvalidArgumentException('World ID must be a safe identifier of 1-64 characters.');
        }

        return $id;
    }

    private function runtimeForHandle(World $handle): ManagedWorldRuntime
    {
        $storage = $this->loaded[$handle->id()] ?? null;
        if ($storage === null) {
            throw new LogicException('World is not loaded.');
        }
        $runtime = $storage->dimension($handle->getDimensionType());
        if ($runtime === null || !$runtime->handle->isSameLoad($handle)) {
            throw new LogicException('World handle belongs to an earlier load generation.');
        }

        return $runtime;
    }

    /** @return array<string, ManagedWorldRuntime> */
    private function dimensionRuntimesByPollKey(): array
    {
        $dimensions = [];
        foreach ($this->loaded as $worldId => $storage) {
            foreach ($storage->dimensions() as $dimensionRuntime) {
                $dimension = $dimensionRuntime->opened->world->dimension();
                $key = $dimension === DimensionType::OVERWORLD
                    ? $worldId
                    : $worldId . '@' . ($dimension === DimensionType::NETHER ? 'nether' : 'end');
                $dimensions[$key] = $dimensionRuntime;
            }
        }

        return $dimensions;
    }

    private function runtimeStorageForHandle(World $handle): WorldStorageRuntime
    {
        $runtime = $this->runtimeForHandle($handle);

        return $this->loaded[$runtime->handle->id()];
    }

    private function resolveHandle(string $worldId, int $generation, DimensionType $dimension): ?World
    {
        $storage = $this->loaded[$worldId] ?? null;
        $runtime = $storage?->dimension($dimension);
        if ($runtime !== null && $runtime->handle->loadGeneration() === $generation) {
            return $runtime->handle;
        }

        return $this->reservedHandles[$worldId][$dimension->value] ?? null;
    }
}
