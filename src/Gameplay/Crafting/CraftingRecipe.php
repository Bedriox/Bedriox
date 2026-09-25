<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Crafting;

interface CraftingRecipe
{
    public function identifier(): string;

    public function priority(): int;

    public function owner(): ?string;

    /** @return list<RecipeOutput> */
    public function outputs(): array;

    /** @return list<RecipeIngredient> */
    public function ingredients(): array;

    /** @return list<RecipeOutput> */
    public function outputsFor(CraftingGrid $grid): array;

    /**
     * @param list<\Bedriox\Server\Player\InventoryStack> $inputs
     * @return list<RecipeOutput>
     */
    public function outputsForInputs(array $inputs): array;

    public function match(CraftingGrid $grid, int $repetitions = 1): ?CraftingRecipeMatch;
}
