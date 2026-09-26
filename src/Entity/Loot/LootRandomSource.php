<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

interface LootRandomSource
{
    /** Returns an integer in the inclusive range. */
    public function nextInt(int $minimum, int $maximum): int;
}
