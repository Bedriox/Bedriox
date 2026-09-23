<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\World;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\LoadedChunkData;
use InvalidArgumentException;

final readonly class ChunkLoadCompletion
{
    public function __construct(
        public ChunkPosition $position,
        public ?LoadedChunkData $loaded,
        public bool $missing,
        public ?string $failureCode = null,
    ) {
        $outcomes = (int) ($loaded !== null) + (int) $missing + (int) ($failureCode !== null);
        if ($outcomes !== 1 || ($failureCode !== null && (strlen($failureCode) > 128
            || preg_match('/^[a-z][a-z0-9_.-]*$/D', $failureCode) !== 1))) {
            throw new InvalidArgumentException('Chunk load completion must contain exactly one valid outcome.');
        }
    }
}
