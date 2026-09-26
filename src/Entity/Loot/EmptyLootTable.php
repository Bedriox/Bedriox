<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

final readonly class EmptyLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        return [];
    }
}
