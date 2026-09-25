<?php

declare(strict_types=1);

namespace Bedriox\Api\Crafting;

use Bedriox\Api\Inventory\ItemStack;

/** Data-only recipe definition with no protocol or registry identities. */
interface CraftingRecipe
{
    public function identifier(): string;

    public function priority(): int;

    /** @return list<ItemStack> */
    public function outputs(): array;
}
