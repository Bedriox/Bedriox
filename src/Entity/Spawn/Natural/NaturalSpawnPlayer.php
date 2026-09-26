<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final readonly class NaturalSpawnPlayer
{
    public function __construct(
        public string $worldName,
        public Position $position,
    ) {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1
            || !is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0) {
            throw new InvalidArgumentException('Natural-spawn player position is invalid.');
        }
    }
}
