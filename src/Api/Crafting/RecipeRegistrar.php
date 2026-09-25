<?php

declare(strict_types=1);

namespace Bedriox\Api\Crafting;

interface RecipeRegistrar
{
    /** Ingredient alternatives are expanded into a bounded complete client catalog. */
    public function register(ShapedRecipe|ShapelessRecipe $recipe, bool $replace = false): void;
}
