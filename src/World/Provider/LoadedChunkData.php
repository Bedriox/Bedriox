<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\World\Chunk;

/** Immutable result of decoding an existing provider chunk. */
final readonly class LoadedChunkData
{
    public function __construct(
        public Chunk $chunk,
        public bool $upgraded = false,
    ) {}
}
