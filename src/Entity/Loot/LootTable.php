<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Inventory\ItemStack;

interface LootTable
{
    /** @return list<ItemStack> */
    public function roll(LootContext $context, LootRandomSource $random): array;
}
