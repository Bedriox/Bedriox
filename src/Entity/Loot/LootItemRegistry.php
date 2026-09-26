<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

interface LootItemRegistry
{
    /** Returns null when the canonical item is not admitted by the active data set. */
    public function maximumStackSize(string $identifier): ?int;
}
