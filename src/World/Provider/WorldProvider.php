<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Provider\Exception\UnsupportedWorldFormatException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;

/** Storage-neutral read lifecycle for one world. */
interface WorldProvider
{
    /** @throws WorldProviderClosedException|WorldStorageException|UnsupportedWorldFormatException|CorruptWorldDataException */
    public function worldData(): WorldData;

    /**
     * Returns null only when the chunk does not exist.
     *
     * @throws WorldProviderClosedException|WorldStorageException|UnsupportedWorldFormatException|CorruptChunkException
     */
    public function loadChunk(ChunkPosition $position): ?LoadedChunkData;

    /**
     * Releases provider resources. Repeated calls must be harmless; every other operation after close must fail.
     */
    public function close(): void;
}
