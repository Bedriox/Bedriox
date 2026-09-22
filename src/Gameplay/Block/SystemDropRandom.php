<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Block;

use Random\Randomizer;

final readonly class SystemDropRandom implements DropRandom
{
    public function __construct(private Randomizer $randomizer = new Randomizer()) {}

    public function integer(int $minimum, int $maximum): int
    {
        return $this->randomizer->getInt($minimum, $maximum);
    }
}
