<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use InvalidArgumentException;

/** Exact durable snapshots committed by one atomic cross-chunk ownership move. */
final readonly class EntityOwnershipTransferResult
{
    public function __construct(
        public EntityChunkSnapshot $sourceAfter,
        public EntityChunkSnapshot $destinationAfter,
    ) {
        if ($sourceAfter->worldName !== $destinationAfter->worldName
            || ($sourceAfter->chunk->x === $destinationAfter->chunk->x
                && $sourceAfter->chunk->z === $destinationAfter->chunk->z)) {
            throw new InvalidArgumentException('Entity ownership transfer result must contain distinct chunks in one world.');
        }
    }
}
