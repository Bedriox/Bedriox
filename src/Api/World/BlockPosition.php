<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

final readonly class BlockPosition
{
    public function __construct(
        public int $x,
        public int $y,
        public int $z,
    ) {}
}
