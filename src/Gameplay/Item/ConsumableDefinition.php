<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

use Bedriox\Api\Inventory\ConsumptionResult;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** Server-owned nutrition and residue produced by one completed consumption. */
final readonly class ConsumableDefinition
{
    /** @var list<ItemStack> */
    public array $residue;

    public ?string $residueIdentifier;

    /**
     * @param list<ItemStack> $residue
     */
    public function __construct(
        public float $foodRestore,
        public float $saturationRestore,
        public bool $requiresHunger = true,
        ?string $residueIdentifier = null,
        array $residue = [],
    ) {
        if (!is_finite($foodRestore) || $foodRestore < 0.0 || $foodRestore > 20.0
            || !is_finite($saturationRestore) || $saturationRestore < 0.0 || $saturationRestore > 20.0) {
            throw new InvalidArgumentException('Consumable nutrition must be finite and bounded.');
        }
        if ($foodRestore === 0.0 && $saturationRestore === 0.0) {
            throw new InvalidArgumentException('Consumable nutrition must change at least one player value.');
        }
        if ($residueIdentifier !== null
            && preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $residueIdentifier) !== 1) {
            throw new InvalidArgumentException('Consumable residue must use a canonical namespaced identifier.');
        }
        if ($residueIdentifier !== null && $residue !== []) {
            throw new InvalidArgumentException('Consumable residue must use one canonical representation.');
        }
        $this->residue = $residueIdentifier === null
            ? $residue
            : [new ItemStack($residueIdentifier, 1)];
        new ConsumptionResult((int) $foodRestore, $saturationRestore, $this->residue);
        $single = $this->residue[0] ?? null;
        $this->residueIdentifier = $single instanceof ItemStack
            && count($this->residue) === 1
            && $single->count === 1
            && $single->damage === 0
            && $single->nbt === null
            && $single->auxValue === 0
                ? $single->identifier
                : null;
    }
}
