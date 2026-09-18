<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WorldProvider;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Closure;
use InvalidArgumentException;
use LogicException;

final class World
{
    private readonly BlockOverrideStore $overrides;

    private readonly ?SpawnPosition $spawnOverride;

    private readonly int $time;

    private bool $closed = false;

    public function __construct(
        public readonly WorldMetadata $metadata,
        private readonly WorldGenerator $generator,
        private readonly ChunkRepository $chunks,
        ?SpawnPosition $spawnOverride = null,
        ?BlockOverrideStore $overrides = null,
        private readonly ?WorldProvider $provider = null,
    ) {
        $this->overrides = $overrides ?? new BlockOverrideStore();
        $worldData = $provider?->worldData();
        if ($worldData !== null && (
            $worldData->metadata->name !== $metadata->name
            || $worldData->metadata->seed !== $metadata->seed
            || $worldData->generatorName !== $generator->name()
        )) {
            throw new InvalidArgumentException('Provider world data does not match the configured world.');
        }
        $this->spawnOverride = $spawnOverride ?? ($worldData === null ? null : $worldData->spawn);
        $this->time = $worldData === null ? 0 : $worldData->time;
    }

    public function chunk(ChunkPosition $position): Chunk
    {
        return $this->chunks->get($position, $this->loadChunk(...), $this->evictionSaver());
    }

    public function retainChunk(ChunkPosition $position): Chunk
    {
        return $this->chunks->retain($position, $this->loadChunk(...), $this->evictionSaver());
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

        return $this->chunks->saveDirty($maximumChunks, $this->saveChunk(...));
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

        $saved = $this->chunks->flush($this->saveChunk(...));
        $this->provider->saveWorldData(new WorldData(
            $this->metadata,
            $this->generator->name(),
            $this->spawn(),
            $this->time,
        ));

        return $saved;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->provider instanceof WritableWorldProvider) {
            $this->flush();
        }
        $this->provider?->close();
        $this->closed = true;
    }

    public function dirtyChunkCount(): int
    {
        return $this->chunks->dirtyCount();
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
