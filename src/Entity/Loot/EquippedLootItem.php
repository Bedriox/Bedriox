<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

final readonly class EquippedLootItem
{
    public function __construct(
        public EquipmentSlot $slot,
        public ItemStack $item,
        public float $dropChance,
    ) {
        if (!is_finite($dropChance) || $dropChance < 0.0 || $dropChance > 1.0) {
            throw new InvalidArgumentException('Equipment drop chance must be between zero and one.');
        }
    }
}
