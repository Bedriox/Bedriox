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

namespace Bedriox\Server\Tests\World;

use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;
use Bedriox\Server\World\Provider\LoadedChunkData;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WritableWorldProvider;

/** Test-only provider which models successful revision durability without filesystem state. */
final class InMemoryWorldProvider implements WritableWorldProvider
{
    /** @var array<string, Chunk> */
    public array $chunks = [];

    /** @var list<int> */
    public array $savedRevisions = [];

    /** @var array<string, true> */
    public array $corruptChunks = [];

    /** @var array<string, true> */
    public array $upgradedChunks = [];

    public bool $failSaves = false;

    public bool $closed = false;

    public int $closeCalls = 0;

    public function __construct(public WorldData $data) {}

    public function worldData(): WorldData
    {
        $this->ensureOpen();

        return $this->data;
    }

    public function loadChunk(ChunkPosition $position): ?LoadedChunkData
    {
        $this->ensureOpen();
        if (isset($this->corruptChunks[$position->key()])) {
            throw new CorruptChunkException('Injected corrupt chunk.');
        }
        $chunk = $this->chunks[$position->key()] ?? null;

        return $chunk instanceof Chunk
            ? new LoadedChunkData($chunk, isset($this->upgradedChunks[$position->key()]))
            : null;
    }

    public function saveWorldData(WorldData $worldData): void
    {
        $this->ensureWritable();
        $this->data = $worldData;
    }

    public function saveChunk(ChunkSaveData $chunkData): void
    {
        $this->ensureWritable();
        $this->savedRevisions[] = $chunkData->revision;
        $this->chunks[$chunkData->chunk->position->key()] = $chunkData->chunk->withPersistedRevision(
            $chunkData->revision,
        );
    }

    public function seed(Chunk $chunk): void
    {
        $this->chunks[$chunk->position->key()] = $chunk;
    }

    public function close(): void
    {
        ++$this->closeCalls;
        $this->closed = true;
    }

    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new WorldProviderClosedException('Test provider is closed.');
        }
    }

    private function ensureWritable(): void
    {
        $this->ensureOpen();
        if ($this->failSaves) {
            throw new WorldStorageException('Injected save failure.');
        }
    }
}
