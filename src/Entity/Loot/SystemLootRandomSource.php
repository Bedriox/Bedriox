<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Random\Randomizer;

final readonly class SystemLootRandomSource implements LootRandomSource
{
    public function __construct(private Randomizer $randomizer = new Randomizer()) {}

    public function nextInt(int $minimum, int $maximum): int
    {
        return $this->randomizer->getInt($minimum, $maximum);
    }
}
