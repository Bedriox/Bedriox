<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use InvalidArgumentException;

final readonly class ChunkPosition
{
    public function __construct(
        public int $x,
        public int $z,
    ) {
        if ($x < -0x80000000 || $x > 0x7fffffff || $z < -0x80000000 || $z > 0x7fffffff) {
            throw new InvalidArgumentException('Chunk coordinates must fit in signed 32-bit integers.');
        }
    }

    public function key(): string
    {
        return $this->x . ':' . $this->z;
    }
}
