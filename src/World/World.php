<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Persistence\PersistenceQueueStatusProvider;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\World\ChunkLoadCompletion;
use Bedriox\Server\Worker\Chunk\AsyncChunkGenerator;
use Bedriox\Server\World\Block\InternalBlockStateId;
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

    private readonly int $time;

    private readonly int $difficulty;

    private bool $closed = false;

    private ?AsyncChunkGenerator $asyncChunks;

    /** @var array<string, ChunkLoadCompletion> */
    private array $completedChunkLoads = [];

    public function __construct(
        public readonly WorldMetadata $metadata,
        private readonly WorldGenerator $generator,
        private readonly ChunkRepository $chunks,
        ?SpawnPosition $spawnOverride = null,
        ?BlockOverrideStore $overrides = null,
        private readonly ?WorldProvider $provider = null,
        ?AsyncChunkGenerator $asyncChunks = null,
    ) {
        $this->asyncChunks = $asyncChunks;
        $this->overrides = $overrides ?? new BlockOverrideStore();
        $worldData = $provider?->worldData();
        if ($worldData !== null && (
            $worldData->metadata->name !== $metadata->name
            || $worldData->metadata->seed !== $metadata->seed
            || $worldData->generatorName !== $generator->name()
            || $worldData->generatorVersion !== ($generator instanceof VersionedWorldGenerator ? $generator->version() : 1)
        )) {
            throw new InvalidArgumentException('Provider world data does not match the configured world.');
        }
        $this->spawnOverride = $spawnOverride ?? ($worldData === null ? null : $worldData->spawn);
        $this->time = $worldData === null ? 0 : $worldData->time;
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

    public function chunk(ChunkPosition $position): Chunk
    {
        return $this->chunks->get($position, $this->loadChunk(...), $this->evictionSaver());
    }

    public function retainChunk(ChunkPosition $position): Chunk
    {
        return $this->chunks->retain($position, $this->loadChunk(...), $this->evictionSaver());
    }

    /**
     * Requests one retained chunk without making generation block the caller.
     *
     * Returns true only when the chunk is installed and retained. A false
     * result is retried by the existing bounded chunk-streaming loop.
     */
    public function requestRetainChunk(ChunkPosition $position): bool
    {
        $this->pollAsynchronousProvider();
        if ($this->chunks->contains($position)) {
            $this->chunks->retain($position, $this->loadChunk(...), $this->evictionSaver());

            return true;
        }
        if ($this->provider instanceof AsynchronousWorldProvider) {
            $key = $position->key();
            $completion = $this->completedChunkLoads[$key] ?? null;
            if (!$completion instanceof ChunkLoadCompletion) {
                $this->provider->requestChunkLoad($position);

                return false;
            }
            unset($this->completedChunkLoads[$key]);
            if ($completion->failureCode !== null) {
                throw new \RuntimeException('Asynchronous chunk storage load failed: ' . $completion->failureCode);
            }
            if ($completion->loaded !== null) {
                $chunk = $completion->loaded->upgraded
                    ? self::markDirty($completion->loaded->chunk)
                    : $completion->loaded->chunk;
                $this->chunks->retain($position, static fn(ChunkPosition $_position): Chunk => $chunk, $this->evictionSaver());

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

            return true;
        }
        if ($this->asyncChunks->isPending($position)) {
            return false;
        }
        $accepted = $this->asyncChunks->request($position, function (Chunk $chunk) use ($position): void {
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
        });
        if ($accepted) {
            return false;
        }

        $this->retainChunk($position);

        return true;
    }

    public function releaseChunk(ChunkPosition $position): void
    {
        $this->chunks->release($position);
    }

    public function blockStateAt(int $x, int $y, int $z): InternalBlockStateId
    {
        $position = self::chunkPosition($x, $z);

        return $this->chunk($position)->blockStateAt(self::localCoordinate($x), $y, self::localCoordinate($z));
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

        $this->pollAsynchronousProvider();
        $submitted = 0;
        foreach ($this->chunks->dirtySnapshots($maximumChunks) as $chunk) {
            $result = $this->provider->enqueueChunkSave(new ChunkSaveData($chunk));
            if ($result->status === PersistenceSubmission::SATURATED) {
                break;
            }
            if ($result->status !== PersistenceSubmission::STALE) {
                ++$submitted;
            }
        }
        $this->pollAsynchronousProvider();

        return $submitted;
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
        $this->provider->saveWorldData(new WorldData(
            $this->metadata,
            $this->generator->name(),
            $this->spawn(),
            $this->time,
            $this->difficulty,
            $this->generator instanceof VersionedWorldGenerator ? $this->generator->version() : 1,
        ));

        return $saved;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->asyncChunks?->cancelAll();

        $failure = null;
        try {
            if ($this->provider instanceof WritableWorldProvider) {
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

    private function pollAsynchronousProvider(): void
    {
        if (!$this->provider instanceof AsynchronousWorldProvider) {
            return;
        }
        foreach ($this->provider->pollChunkLoads() as $completion) {
            $this->completedChunkLoads[$completion->position->key()] = $completion;
        }
        foreach ($this->provider->pollChunkSaves() as $completion) {
            if (!$completion->successful) {
                continue;
            }
            $position = self::positionFromPersistenceKey($completion->key);
            if ($this->chunks->contains($position)) {
                $this->chunks->acknowledgePersisted($position, $completion->revision);
            }
        }
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
        );
    }
}
