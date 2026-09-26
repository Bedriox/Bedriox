<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Inventory\ItemStack;

final readonly class CowLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        $drops = [];
        $leather = $random->nextInt(0, 2);
        if ($leather > 0) {
            $drops[] = new ItemStack('minecraft:leather', $leather);
        }
        $drops[] = new ItemStack(
            $context->burning ? 'minecraft:cooked_beef' : 'minecraft:beef',
            $random->nextInt(1, 3),
        );

        return $drops;
    }
}
