<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Chunk;

use InvalidArgumentException;

final readonly class ChunkPreparationRequest
{
    public function __construct(
        public int $protocolVersion,
        public int $serializerVersion,
        public int $compressionThreshold,
        public string $registryHash,
        public string $chunkTransfer,
    ) {
        if ($protocolVersion < 1 || $protocolVersion > 0x7fff_ffff
            || $serializerVersion < 1 || $serializerVersion > 65_535
            || $compressionThreshold < 0 || $compressionThreshold > 65_535
            || preg_match('/^[a-f0-9]{64}$/D', $registryHash) !== 1
            || $chunkTransfer === '') {
            throw new InvalidArgumentException('Chunk-preparation request identity is invalid.');
        }
    }
}
