<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

use InvalidArgumentException;

final readonly class SystemNaturalDespawnRandom implements NaturalDespawnRandom
{
    public function oneIn(int $chance): bool
    {
        if ($chance < 1 || $chance > 1_000_000) {
            throw new InvalidArgumentException('Natural-despawn chance is outside its supported bounds.');
        }

        return random_int(1, $chance) === 1;
    }
}
