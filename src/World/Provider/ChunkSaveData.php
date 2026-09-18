<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\World\Chunk;

/** Immutable save snapshot; providers must commit all represented components atomically. */
final readonly class ChunkSaveData
{
    public int $revision;

    public int $dirtyFlags;

    public function __construct(public Chunk $chunk)
    {
        $this->revision = $chunk->revision;
        $this->dirtyFlags = $chunk->dirtyFlags;
    }
}
