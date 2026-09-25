<?php

declare(strict_types=1);

namespace Bedriox\Api\Crafting;

use LogicException;

/** @internal */
final class UnavailableRecipeRegistrar implements RecipeRegistrar
{
    public function register(ShapedRecipe|ShapelessRecipe $recipe, bool $replace = false): void
    {
        throw new LogicException('Crafting recipe registration is unavailable in this plugin context.');
    }
}
