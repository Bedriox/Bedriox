<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use InvalidArgumentException;

final readonly class SpawnPosition
{
    public function __construct(
        public int $x,
        public int $y,
        public int $z,
    ) {
        foreach ([$x, $y, $z] as $coordinate) {
            if ($coordinate < -0x80000000 || $coordinate > 0x7fffffff) {
                throw new InvalidArgumentException('Spawn coordinates must fit in signed 32-bit integers.');
            }
        }
        if ($y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            throw new InvalidArgumentException('Spawn Y is outside the supported overworld height.');
        }
    }
}
