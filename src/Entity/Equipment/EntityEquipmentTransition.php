<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Equipment;

use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** Final item and drop chance selected by an equipment pre-transition hook. */
final readonly class EntityEquipmentTransition
{
    public function __construct(
        public ?ItemStack $item,
        public float $dropChance,
    ) {
        if (!is_finite($dropChance) || $dropChance < 0.0 || $dropChance > 1.0) {
            throw new InvalidArgumentException('Entity equipment transition drop chance must be between zero and one.');
        }
    }
}
