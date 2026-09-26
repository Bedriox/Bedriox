<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

use InvalidArgumentException;

final readonly class CustomMobTickContext
{
    public function __construct(
        public Mob $mob,
        public int $currentTick,
        public CustomMobController $controller,
    ) {
        if ($currentTick < 0) {
            throw new InvalidArgumentException('Custom mob tick must not be negative.');
        }
    }
}
