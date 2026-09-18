<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use InvalidArgumentException;

/** An immutable inventory snapshot containing no protocol stack identifiers. */
final readonly class Inventory
{
    /** @param list<ItemStack|null> $slots */
    public function __construct(
        public array $slots,
        public int $selectedHotbarSlot,
        public ?ItemStack $cursor = null,
    ) {
        if ($selectedHotbarSlot < 0 || $selectedHotbarSlot > 8) {
            throw new InvalidArgumentException('Selected hotbar slot must be between 0 and 8.');
        }
    }

    public function stackAt(int $slot): ?ItemStack
    {
        if ($slot < 0 || $slot >= count($this->slots)) {
            throw new InvalidArgumentException('Inventory slot is outside the inventory.');
        }

        return $this->slots[$slot];
    }
}
