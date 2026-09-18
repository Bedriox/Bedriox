<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

use InvalidArgumentException;

final readonly class Block
{
    public function __construct(
        public BlockPosition $position,
        public string $identifier,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Block identifier must be canonical and namespaced.');
        }
    }
}
