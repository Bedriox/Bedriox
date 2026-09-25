<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Crafting\RecipeRegistrar;
use Bedriox\Api\Crafting\ShapedRecipe;
use Bedriox\Api\Crafting\ShapelessRecipe;

final readonly class OwnedRecipeRegistrar implements RecipeRegistrar
{
    public function __construct(private string $plugin, private PluginRecipeRegistrar $registrar) {}

    public function register(ShapedRecipe|ShapelessRecipe $recipe, bool $replace = false): void
    {
        $this->registrar->register($this->plugin, $recipe, $replace);
    }
}
