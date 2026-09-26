<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Block;

interface DropRandom
{
    /** @phpstan-impure */
    public function integer(int $minimum, int $maximum): int;
}
