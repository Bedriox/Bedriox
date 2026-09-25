<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Crafting;

/** Exact authoritative ingredient assignment for one or more recipe repetitions. */
final readonly class CraftingRecipeMatch
{
    /**
     * @param array<int, int> $consumptionBySlot
     * @param list<RecipeOutput> $outputs
     */
    public function __construct(
        public string $recipeIdentifier,
        public int $repetitions,
        public array $consumptionBySlot,
        public array $outputs,
    ) {}
}
