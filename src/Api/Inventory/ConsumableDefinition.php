<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

/** Data-only plugin definition for an authoritative consumable item. */
final readonly class ConsumableDefinition
{
    /** @param list<ItemStack> $residue */
    public function __construct(
        public int $foodRestore,
        public float $saturationRestore,
        public bool $requiresHunger = true,
        public array $residue = [],
    ) {
        new ConsumptionResult($foodRestore, $saturationRestore, $residue);
    }

    public function result(): ConsumptionResult
    {
        return new ConsumptionResult($this->foodRestore, $this->saturationRestore, $this->residue);
    }
}
