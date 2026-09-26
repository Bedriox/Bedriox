<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Inventory\ItemStack;

final readonly class ZombieLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        $drops = [];
        $flesh = $random->nextInt(0, 2);
        if ($flesh > 0) {
            $drops[] = new ItemStack('minecraft:rotten_flesh', $flesh);
        }

        if ($random->nextInt(0, 199) < 5) {
            $rareItem = match ($random->nextInt(0, 2)) {
                0 => 'minecraft:iron_ingot',
                1 => 'minecraft:carrot',
                2 => 'minecraft:potato',
                default => throw new \LogicException('Bounded loot random returned an out-of-range value.'),
            };
            $drops[] = new ItemStack($rareItem, 1);
        }

        return $drops;
    }
}
