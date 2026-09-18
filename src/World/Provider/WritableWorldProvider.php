<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\World\Provider\Exception\UnsupportedWorldFormatException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;

interface WritableWorldProvider extends WorldProvider
{
    /** @throws WorldProviderClosedException|WorldStorageException|UnsupportedWorldFormatException */
    public function saveWorldData(WorldData $worldData): void;

    /** @throws WorldProviderClosedException|WorldStorageException|UnsupportedWorldFormatException */
    public function saveChunk(ChunkSaveData $chunkData): void;
}
