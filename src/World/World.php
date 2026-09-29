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

namespace Bedriox\Server\World;

use Bedriox\Server\Entity\Persistence\EntityPersistenceConflictException;
use Bedriox\Server\Entity\Persistence\EntityPersistenceManager;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Persistence\PersistenceQueueStatusProvider;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\World\ChunkLoadCompletion;
use Bedriox\Server\Worker\Chunk\AsyncChunkGenerator;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockEntity\BlockEntity;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Provider\AsynchronousWorldDataProvider;
use Bedriox\Server\World\Provider\AsynchronousWorldProvider;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WorldProvider;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class World
{
    private readonly BlockOverrideStore $overrides;

    private readonly ?SpawnPosition $spawnOverride;

    private int $time;

    private bool $timeRunning = true;

    private readonly int $difficulty;

    private bool $closed = false;

    private ?AsyncChunkGenerator $asyncChunks;

    /** @var array<string, ChunkLoadCompletion> */
    private array $completedChunkLoads = [];

    /** @var array<string, true> storage or generation requests not yet installed */
    private array $pendingChunkRetains = [];

    /** @var array<string, int> Newest revision queued or in flight with the asynchronous provider. */
    private array $pendingChunkSaveRevisions = [];

    private readonly ChunkUnloadManager $chunkUnloads;

    private readonly string $generatorOptions;

    private bool $spawnChunkRetained = false;

    private ?EntityPersistenceManager $entityPersistence = null;

    public function __construct(
        public readonly WorldMetadata $metadata,
        private readonly WorldGenerator $generator,
        private readonly ChunkRepository $chunks,
        ?SpawnPosition $spawnOverride = null,
        ?BlockOverrideStore $overrides = null,
        private readonly ?WorldProvider $provider = null,
        ?AsyncChunkGenerator $asyncChunks = null,
        ?ChunkUnloadManager $chunkUnloads = null,
        ?string $generatorOptions = null,
    ) {
        $this->asyncChunks = $asyncChunks;
        $this->chunkUnloads = $chunkUnloads ?? new ChunkUnloadManager();
        $this->overrides = $overrides ?? new BlockOverrideStore();
        $worldData = $provider?->worldData();
        $this->generatorOptions = $generatorOptions ?? ($worldData === null ? '{}' : $worldData->generatorOptions);
        GeneratorOptions::fromJson($this->generatorOptions);
        if ($worldData !== null && (
            $worldData->metadata->name !== $metadata->name
            || $worldData->metadata->seed !== $metadata->seed
            || $worldData->generatorName !== $generator->name()
            || $worldData->generatorVersion !== ($generator instanceof VersionedWorldGenerator ? $generator->version() : 1)
        )) {
            throw new InvalidArgumentException('Provider world data does not match the configured world.');
        }
        $this->spawnOverride = $spawnOverride ?? ($worldData === null ? null : $worldData->spawn);
        $this->time = WorldTimeRules::validate($worldData === null ? 0 : $worldData->time);
        $this->difficulty = $worldData === null ? 2 : $worldData->difficulty;
    }

    /** Enables worker generation before the world begins serving chunks. */
    public function enableAsyncGeneration(AsyncChunkGenerator $generator): void
    {
        if ($this->closed || $this->chunks->count() !== 0 || $this->asyncChunks !== null) {
            throw new LogicException('Async generation must be enabled once before chunks are loaded.');
        }
        $this->asyncChunks = $generator;
    }

    /** Attaches the entity owner lifecycle before the first terrain chunk is activated. */
    public function attachEntityPersistence(EntityPersistenceManager $persistence): void
    {
        if ($this->closed || $this->chunks->count() !== 0 || $this->entityPersistence !== null) {
            throw new LogicException('Entity persistence must be attached exactly once before chunks are loaded.');
        }
        $this->entityPersistence = $persistence;
        $this->chunks->setBeforeEvictionObserver(static function (ChunkPosition $chunk) use ($persistence): void {
            $persistence->unloadChunk($chunk);
        });
    }

    public function entityPersistenceStore(): ?EntityPersistenceStore
    {
        return $this->provider instanceof EntityPersistenceStore ? $this->provider : null;
    }

    public function transientEntityPersistenceStore(): ?TransientEntityPersistenceStore
    {
        return $this->provider instanceof TransientEntityPersistenceStore ? $this->provider : null;
    }

    public function chunk(ChunkPosition $position): Chunk
    {
        $chunk = $this->chunks->get($position, $this->loadChunk(...), $this->evictionSaver());
        $this->entityPersistence?->activateChunk($position);
        if (!$this->chunks->isRetained($position)) {
            $this->chunkUnloads->queue($position);
        }

        return $chunk;
    }

    /** Read-only admission check used by entity spawning and other non-blocking systems. */
    public function hasLoadedChunk(ChunkPosition $position): bool
    {
        return $this->chunks->contains($position);
    }

    /** Returns the currently loaded immutable snapshot without loading, generating, or changing LRU state. */
    public function loadedChunk(ChunkPosition $position): ?Chunk
    {
        return $this->chunks->loaded($position);
    }

    public function retainChunk(ChunkPosition $position): Chunk
    {
        $chunk = $this->chunks->retain($position, $this->loadChunk(...), $this->evictionSaver());
        $this->entityPersistence?->activateChunk($position);
        $this->chunkUnloads->cancel($position);

        return $chunk;
    }

    /**
     * Requests one retained chunk without making generation block the caller.
     *
     * Returns true only when the chunk is installed and retained. A false
     * result is retried by the existing bounded chunk-streaming loop.
     */
    public function requestRetainChunk(ChunkPosition $position, bool $pollCompletions = true): bool
    {
        $key = $position->key();
        if ($pollCompletions) {
            $this->pollAsynchronousCompletions();
        }
        if ($this->chunks->contains($position)) {
            unset($this->pendingChunkRetains[$key]);
            $this->chunks->retain($position, $this->loadChunk(...), $this->evictionSaver());
            $this->entityPersistence?->activateChunk($position);
            $this->chunkUnloads->cancel($position);

            return true;
        }
        if ($this->provider instanceof AsynchronousWorldProvider) {
            $completion = $this->completedChunkLoads[$key] ?? null;
            if (!$completion instanceof ChunkLoadCompletion) {
                $this->provider->requestChunkLoad($position);
                $this->pendingChunkRetains[$key] = true;

                return false;
            }
            unset($this->completedChunkLoads[$key]);
            unset($this->pendingChunkRetains[$key]);
            if ($completion->failureCode !== null) {
                throw new \RuntimeException('Asynchronous chunk storage load failed: ' . $completion->failureCode);
            }
            if ($completion->loaded !== null) {
                $chunk = $completion->loaded->upgraded
                    ? self::markDirty($completion->loaded->chunk)
                    : $completion->loaded->chunk;
                $this->chunks->retain($position, static fn(ChunkPosition $_position): Chunk => $chunk, $this->evictionSaver());
                $this->entityPersistence?->activateChunk($position);

                return true;
            }
            if (!$completion->missing) {
                throw new \RuntimeException('Asynchronous chunk storage returned no outcome.');
            }
        } elseif ($this->provider !== null) {
            $loaded = $this->provider->loadChunk($position);
            if ($loaded !== null) {
                $chunk = $loaded->upgraded ? self::markDirty($loaded->chunk) : $loaded->chunk;
                $this->chunks->retain($position, static fn(ChunkPosition $_position): Chunk => $chunk, $this->evictionSaver());
                $this->entityPersistence?->activateChunk($position);

                return true;
            }
        }
        if ($this->asyncChunks === null) {
            $chunk = $this->generator->generate($position);
            if ($this->provider !== null) {
                $chunk = self::markDirty($chunk);
            } else {
                foreach ($this->overrides->forChunk($position) as $override) {
                    $chunk = $chunk->withBlockState(
                        $override['x'],
                        $override['y'],
                        $override['z'],
                        $override['state'],
                    );
                }
            }
            $this->chunks->retain($position, static fn(ChunkPosition $_position): Chunk => $chunk, $this->evictionSaver());
            $this->entityPersistence?->activateChunk($position);

            return true;
        }
        if ($this->asyncChunks->isPending($position)) {
            $this->pendingChunkRetains[$key] = true;
            return false;
        }
        $accepted = $this->asyncChunks->request($position, function (Chunk $chunk) use ($position, $key): void {
            unset($this->pendingChunkRetains[$key]);
            if ($this->provider !== null) {
                $chunk = self::markDirty($chunk);
            } else {
                foreach ($this->overrides->forChunk($position) as $override) {
                    $chunk = $chunk->withBlockState(
                        $override['x'],
                        $override['y'],
                        $override['z'],
                        $override['state'],
                    );
                }
            }
            $this->chunks->get($position, static fn(ChunkPosition $_position): Chunk => $chunk, $this->evictionSaver());
            $this->entityPersistence?->activateChunk($position);
            if (!$this->chunks->isRetained($position)) {
                $this->chunkUnloads->queue($position);
            }
        });
        if ($accepted) {
            $this->pendingChunkRetains[$key] = true;
            return false;
        }

        unset($this->pendingChunkRetains[$key]);
        if (!$this->asyncChunks->allowsSynchronousFallback()) {
            return false;
        }
        $this->retainChunk($position);

        return true;
    }

    public function isChunkRetainPending(ChunkPosition $position): bool
    {
        return isset($this->pendingChunkRetains[$position->key()]);
    }

    /** Keeps the configured world spawn available without blocking on asynchronous storage or generation. */
    public function requestRetainSpawnChunk(): bool
    {
        if ($this->spawnChunkRetained) {
            return true;
        }
        $spawn = $this->spawn();
        if (!$this->requestRetainChunk(self::chunkPosition($spawn->x, $spawn->z))) {
            return false;
        }
        $this->spawnChunkRetained = true;

        return true;
    }

    public function releaseChunk(ChunkPosition $position): void
    {
        $this->chunks->release($position);
        if (!$this->chunks->isRetained($position)) {
            $this->chunkUnloads->queue($position);
        }
    }

    public function blockStateAt(int $x, int $y, int $z): InternalBlockStateId
    {
        $position = self::chunkPosition($x, $z);

        return $this->chunk($position)->blockStateAt(self::localCoordinate($x), $y, self::localCoordinate($z));
    }

    public function blockEntityAt(BlockPosition $position): ?BlockEntity
    {
        return $this->chunk(self::chunkPosition($position->x, $position->z))->blockEntityAt($position);
    }

    /** @return list<BlockEntity> Immutable block entities in currently loaded chunks. */
    public function loadedBlockEntities(): array
    {
        $entities = [];
        foreach ($this->chunks->loadedChunks() as $chunk) {
            array_push($entities, ...$chunk->blockEntities());
        }

        return $entities;
    }

    /** Installs immutable durable state and returns the block entity previously stored at the position. */
    public function setBlockEntity(BlockEntity $blockEntity): ?BlockEntity
    {
        $chunkPosition = self::chunkPosition($blockEntity->position->x, $blockEntity->position->z);
        $chunk = $this->chunk($chunkPosition);
        $previous = $chunk->blockEntityAt($blockEntity->position);
        $replacement = $chunk->withBlockEntity($blockEntity);
        if ($replacement !== $chunk) {
            $this->chunks->replace($replacement);
        }

        return $previous;
    }

    /**
     * Installs a bounded block-entity batch from immutable chunk snapshots.
     *
     * Every replacement is prepared before any authoritative chunk is changed, so paired
     * storage cannot expose only one updated half if validation or chunk loading fails.
     *
     * @return list<BlockEntity|null> previous entities in argument order
     */
    public function setBlockEntities(BlockEntity ...$blockEntities): array
    {
        if ($blockEntities === [] || count($blockEntities) > 16) {
            throw new InvalidArgumentException('A block entity batch must contain between one and sixteen entries.');
        }

        /** @var array<string, ChunkPosition> $positions */
        $positions = [];
        /** @var array<string, true> $entityPositions */
        $entityPositions = [];
        foreach ($blockEntities as $blockEntity) {
            $entityKey = $blockEntity->position->x . ':' . $blockEntity->position->y . ':' . $blockEntity->position->z;
            if (isset($entityPositions[$entityKey])) {
                throw new InvalidArgumentException('A block entity batch may not replace the same position twice.');
            }
            $entityPositions[$entityKey] = true;
            $chunkPosition = self::chunkPosition($blockEntity->position->x, $blockEntity->position->z);
            $positions[$chunkPosition->key()] = $chunkPosition;
        }

        /** @var array<string, Chunk> $prepared */
        $prepared = [];
        $retained = [];
        $previous = [];
        try {
            foreach ($positions as $key => $position) {
                $prepared[$key] = $this->retainChunk($position);
                $retained[] = $position;
            }
            foreach ($blockEntities as $blockEntity) {
                $chunkPosition = self::chunkPosition($blockEntity->position->x, $blockEntity->position->z);
                $key = $chunkPosition->key();
                $chunk = $prepared[$key];
                $previous[] = $chunk->blockEntityAt($blockEntity->position);
                $prepared[$key] = $chunk->withBlockEntity($blockEntity);
            }
            foreach ($prepared as $chunk) {
                $this->chunks->replace($chunk);
            }
        } finally {
            foreach ($retained as $position) {
                $this->releaseChunk($position);
            }
        }

        return $previous;
    }

    public function removeBlockEntity(BlockPosition $position): ?BlockEntity
    {
        $chunkPosition = self::chunkPosition($position->x, $position->z);
        $chunk = $this->chunk($chunkPosition);
        $previous = $chunk->blockEntityAt($position);
        if ($previous !== null) {
            $this->chunks->replace($chunk->withoutBlockEntity($position));
        }

        return $previous;
    }

    /** Atomically replaces one process-local block state and returns its previous value. */
    public function setBlockState(int $x, int $y, int $z, InternalBlockStateId $state): InternalBlockStateId
    {
        $position = self::chunkPosition($x, $z);
        $chunk = $this->chunk($position);
        $localX = self::localCoordinate($x);
        $localZ = self::localCoordinate($z);
        $previous = $chunk->blockStateAt($localX, $y, $localZ);
        if ($previous->value !== $state->value) {
            // Providerless worlds retain the bounded override store only for existing tests and ephemeral operation.
            // Provider-backed worlds persist the authoritative immutable chunk revision instead.
            if ($this->provider === null) {
                $this->overrides->set($position, $localX, $y, $localZ, $state);
            }
            $this->chunks->replace($chunk->withBlockState($localX, $y, $localZ, $state));
        }

        return $previous;
    }

    public function spawn(): SpawnPosition
    {
        return WorldSpawnResolver::resolve($this->generator, $this->spawnOverride);
    }

    public function generatorName(): string
    {
        return $this->generator->name();
    }

    public function difficulty(): int
    {
        return $this->difficulty;
    }

    public function time(): int
    {
        return $this->time;
    }

    public function timeOfDay(): int
    {
        return WorldTimeRules::timeOfDay($this->time);
    }

    public function day(): int
    {
        return WorldTimeRules::day($this->time);
    }

    public function setTime(int $time): void
    {
        $this->time = WorldTimeRules::validate($time);
    }

    public function addTime(int $amount): void
    {
        $this->time = WorldTimeRules::add($this->time, $amount);
    }

    public function advanceTime(): void
    {
        if ($this->timeRunning) {
            $this->time = WorldTimeRules::add($this->time, 1);
        }
    }

    public function isTimeRunning(): bool
    {
        return $this->timeRunning;
    }

    public function startTime(): void
    {
        $this->timeRunning = true;
    }

    public function stopTime(): void
    {
        $this->timeRunning = false;
    }

    /** Saves a bounded number of dirty chunks, oldest-dirty first. */
    public function autosave(int $maximumChunks): int
    {
        if ($maximumChunks < 1) {
            throw new InvalidArgumentException('Autosave chunk limit must be positive.');
        }
        if (!$this->provider instanceof WritableWorldProvider) {
            throw new LogicException('This world does not have a writable provider.');
        }

        if (!$this->provider instanceof AsynchronousWorldProvider) {
            return $this->chunks->saveDirty($maximumChunks, $this->saveChunk(...));
        }

        $this->pollAsynchronousCompletions();
        $submitted = 0;
        foreach ($this->chunks->dirtySnapshots($maximumChunks, $this->pendingChunkSaveRevisions) as $chunk) {
            $status = $this->submitAsynchronousChunkSave($chunk);
            if ($status === PersistenceSubmission::SATURATED) {
                break;
            }
            if ($status !== PersistenceSubmission::STALE) {
                ++$submitted;
            }
        }
        $this->pollAsynchronousCompletions();

        return $submitted;
    }

    /**
     * Processes due unretained chunks within both count and elapsed-time bounds.
     *
     * Dirty asynchronous chunks remain resident until their exact revision is acknowledged.
     */
    public function processChunkUnloads(int $maximumChunks = 96, int $timeBudgetMicroseconds = 2_000): ChunkUnloadResult
    {
        $this->pollAsynchronousCompletions();
        $due = $this->chunkUnloads->due($maximumChunks, $timeBudgetMicroseconds);
        $examined = 0;
        $evicted = 0;
        $saveSubmissions = 0;
        $saturated = false;
        foreach ($due as $position) {
            ++$examined;
            if (!$this->chunks->contains($position)) {
                $this->chunkUnloads->cancel($position);

                continue;
            }
            if ($this->chunks->isRetained($position)) {
                $this->chunkUnloads->cancel($position);

                continue;
            }
            $chunk = $this->chunks->loaded($position);
            if (!$chunk instanceof Chunk) {
                $this->chunkUnloads->cancel($position);

                continue;
            }
            if ($chunk->isDirty()) {
                if ($this->provider instanceof AsynchronousWorldProvider) {
                    $status = $this->submitAsynchronousChunkSave($chunk);
                    if ($status === PersistenceSubmission::SATURATED) {
                        $this->chunkUnloads->defer($position);
                        $saturated = true;
                        break;
                    }
                    if ($status !== PersistenceSubmission::STALE) {
                        ++$saveSubmissions;
                    }
                    $this->chunkUnloads->defer($position);

                    continue;
                }
                if ($this->provider instanceof WritableWorldProvider) {
                    $this->chunks->save($position, $this->saveChunk(...));
                } elseif ($this->provider === null) {
                    $this->chunks->save($position, static function (Chunk $_chunk): void {});
                } else {
                    $this->chunkUnloads->defer($position);

                    continue;
                }
            }
            try {
                $evictedChunk = $this->chunks->evictIfCleanAndUnretained($position);
            } catch (EntityPersistenceConflictException) {
                // The entity owner remains installed because the eviction observer
                // failed before repository removal. Retry after persistence catches up.
                $this->chunkUnloads->defer($position);

                continue;
            }
            if ($evictedChunk) {
                $this->chunkUnloads->cancel($position);
                unset($this->pendingChunkSaveRevisions[$position->key()]);
                ++$evicted;
            }
        }

        return new ChunkUnloadResult(
            $examined,
            $evicted,
            $saveSubmissions,
            $saturated,
            $this->chunkUnloads->count(),
        );
    }

    /** Saves all dirty chunks and current format-independent world metadata. */
    public function flush(): int
    {
        if (!$this->provider instanceof WritableWorldProvider) {
            if ($this->provider === null) {
                return 0;
            }

            throw new LogicException('This world does not have a writable provider.');
        }

        $saved = $this->provider instanceof AsynchronousWorldProvider
            ? $this->flushAsynchronousChunks($this->provider)
            : $this->chunks->flush($this->saveChunk(...));
        $this->saveWorldData();

        return $saved;
    }

    /** Persists the current format-independent world metadata without flushing terrain. */
    public function saveWorldData(): void
    {
        if (!$this->provider instanceof WritableWorldProvider) {
            if ($this->provider === null) {
                return;
            }

            throw new LogicException('This world does not have a writable provider.');
        }
        $this->provider->saveWorldData($this->currentWorldData());
    }

    /** Queues routine metadata autosave without placing storage latency on the simulation thread. */
    public function scheduleWorldDataSave(): PersistenceSubmission
    {
        if (!$this->provider instanceof WritableWorldProvider) {
            if ($this->provider === null) {
                return PersistenceSubmission::ACCEPTED;
            }

            throw new LogicException('This world does not have a writable provider.');
        }
        $data = $this->currentWorldData();
        if ($this->provider instanceof AsynchronousWorldDataProvider) {
            return $this->provider->enqueueWorldDataSave($data)->status;
        }
        $this->provider->saveWorldData($data);

        return PersistenceSubmission::ACCEPTED;
    }

    /** @return list<PersistenceWriteCompletion> */
    public function pollWorldDataSaves(int $maximumCompletions = 16): array
    {
        if (!$this->provider instanceof AsynchronousWorldDataProvider) {
            return [];
        }

        return $this->provider->pollWorldDataSaves($maximumCompletions);
    }

    private function currentWorldData(): WorldData
    {
        return new WorldData(
            $this->metadata,
            $this->generator->name(),
            $this->spawn(),
            $this->time,
            $this->difficulty,
            $this->generator instanceof VersionedWorldGenerator ? $this->generator->version() : 1,
            $this->generatorOptions,
        );
    }

    public function close(bool $save = true): void
    {
        if ($this->closed) {
            return;
        }
        $this->asyncChunks?->cancelAll();

        $failure = null;
        try {
            if ($save && $this->provider instanceof WritableWorldProvider) {
                $this->flush();
            }
        } catch (Throwable $error) {
            $failure = $error;
        }
        try {
            $this->provider?->close();
        } catch (Throwable $error) {
            $failure ??= $error;
        } finally {
            $this->closed = true;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function dirtyChunkCount(): int
    {
        return $this->chunks->dirtyCount();
    }

    public function loadedChunkCount(): int
    {
        return $this->chunks->count();
    }

    public function pendingChunkUnloadCount(): int
    {
        return $this->chunkUnloads->count();
    }

    public function generatingChunkCount(): int
    {
        return $this->asyncChunks?->pendingCount() ?? 0;
    }

    public function chunkRepositorySnapshot(): ChunkRepositorySnapshot
    {
        return $this->chunks->snapshot();
    }

    public function persistenceQueueSnapshot(): ?PersistenceQueueSnapshot
    {
        return $this->provider instanceof PersistenceQueueStatusProvider
            ? $this->provider->persistenceQueueSnapshot()
            : null;
    }

    private static function chunkPosition(int $x, int $z): ChunkPosition
    {
        return new ChunkPosition((int) floor($x / 16.0), (int) floor($z / 16.0));
    }

    private static function localCoordinate(int $coordinate): int
    {
        return (($coordinate % 16) + 16) % 16;
    }

    private function loadChunk(ChunkPosition $position): Chunk
    {
        if ($this->provider !== null) {
            $loaded = $this->provider->loadChunk($position);
            if ($loaded !== null) {
                return $loaded->upgraded ? self::markDirty($loaded->chunk) : $loaded->chunk;
            }

            return self::markDirty($this->generator->generate($position));
        }

        $chunk = $this->generator->generate($position);
        foreach ($this->overrides->forChunk($position) as $override) {
            $chunk = $chunk->withBlockState(
                $override['x'],
                $override['y'],
                $override['z'],
                $override['state'],
            );
        }

        return $chunk;
    }

    /** @return null|Closure(Chunk): void */
    private function evictionSaver(): ?Closure
    {
        if ($this->provider instanceof AsynchronousWorldProvider) {
            return null;
        }
        if ($this->provider instanceof WritableWorldProvider) {
            return $this->saveChunk(...);
        }
        if ($this->provider === null) {
            // The providerless compatibility path replays BlockOverrideStore after eviction.
            return static function (Chunk $_chunk): void {};
        }

        return null;
    }

    private function saveChunk(Chunk $chunk): void
    {
        if (!$this->provider instanceof WritableWorldProvider) {
            throw new LogicException('This world does not have a writable provider.');
        }

        $this->provider->saveChunk(new ChunkSaveData($chunk));
    }

    /** Collects a bounded batch of completed storage work for later non-blocking consumers. */
    public function pollAsynchronousCompletions(int $maximumLoads = 32, int $maximumSaves = 32): void
    {
        if ($maximumLoads < 1 || $maximumLoads > 256 || $maximumSaves < 1 || $maximumSaves > 256) {
            throw new InvalidArgumentException('Asynchronous completion limits must be between 1 and 256.');
        }
        if (!$this->provider instanceof AsynchronousWorldProvider) {
            return;
        }
        foreach ($this->provider->pollChunkLoads($maximumLoads) as $completion) {
            $this->completedChunkLoads[$completion->position->key()] = $completion;
        }
        foreach ($this->provider->pollChunkSaves($maximumSaves) as $completion) {
            $position = self::positionFromPersistenceKey($completion->key);
            $key = $position->key();
            if (($this->pendingChunkSaveRevisions[$key] ?? -1) <= $completion->revision) {
                unset($this->pendingChunkSaveRevisions[$key]);
            }
            if (!$completion->successful) {
                continue;
            }
            if ($this->chunks->contains($position)) {
                $this->chunks->acknowledgePersisted($position, $completion->revision);
            }
        }
    }

    private function submitAsynchronousChunkSave(Chunk $chunk): PersistenceSubmission
    {
        if (!$this->provider instanceof AsynchronousWorldProvider) {
            throw new LogicException('This world does not have an asynchronous provider.');
        }
        $key = $chunk->position->key();
        if (($this->pendingChunkSaveRevisions[$key] ?? -1) >= $chunk->revision) {
            return PersistenceSubmission::STALE;
        }

        $result = $this->provider->enqueueChunkSave(new ChunkSaveData($chunk));
        if ($result->status !== PersistenceSubmission::SATURATED) {
            $this->pendingChunkSaveRevisions[$key] = $chunk->revision;
        }

        return $result->status;
    }

    private function flushAsynchronousChunks(AsynchronousWorldProvider $provider): int
    {
        $saved = 0;
        while ($this->chunks->dirtyCount() > 0) {
            foreach ($this->chunks->dirtySnapshots(min(256, $this->chunks->dirtyCount())) as $chunk) {
                $result = $provider->enqueueChunkSave(new ChunkSaveData($chunk));
                if ($result->status === PersistenceSubmission::SATURATED) {
                    break;
                }
            }
            $completions = $provider->drainChunkSaves(30_000);
            if ($completions === []) {
                throw new \RuntimeException('Asynchronous world persistence made no shutdown progress.');
            }
            foreach ($completions as $completion) {
                if (!$completion->successful) {
                    throw new \RuntimeException('Asynchronous world persistence failed: ' . $completion->failureCode);
                }
                $position = self::positionFromPersistenceKey($completion->key);
                if ($this->chunks->contains($position)
                    && $this->chunks->acknowledgePersisted($position, $completion->revision)) {
                    ++$saved;
                }
            }
        }

        return $saved;
    }

    private static function positionFromPersistenceKey(string $key): ChunkPosition
    {
        if (preg_match('/^chunk:(-?(?:0|[1-9][0-9]*)):(-?(?:0|[1-9][0-9]*))$/D', $key, $matches) !== 1) {
            throw new \RuntimeException('Asynchronous world persistence returned an invalid chunk key.');
        }

        return new ChunkPosition((int) $matches[1], (int) $matches[2]);
    }

    private static function markDirty(Chunk $chunk): Chunk
    {
        if (($chunk->dirtyFlags & Chunk::DIRTY_ALL) === Chunk::DIRTY_ALL) {
            return $chunk;
        }

        return new Chunk(
            $chunk->position,
            $chunk->airState(),
            $chunk->populatedSections(),
            $chunk->biome(),
            $chunk->revision + 1,
            $chunk->persistedRevision,
            $chunk->dirtyFlags | Chunk::DIRTY_ALL,
            $chunk->finalizationState,
            $chunk->biomeStorages(),
            $chunk->blockEntityCollection(),
        );
    }
}
