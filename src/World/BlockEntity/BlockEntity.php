<?php

declare(strict_types=1);

namespace Bedriox\Server\World\BlockEntity;

use Bedriox\Server\World\BlockPosition;

/** Immutable authoritative state attached to one block position. */
abstract readonly class BlockEntity
{
    public function __construct(
        public BlockEntityType $type,
        public BlockPosition $position,
        public int $revision = 0,
    ) {
        if ($revision < 0) {
            throw new \InvalidArgumentException('Block-entity revision cannot be negative.');
        }
    }
}
