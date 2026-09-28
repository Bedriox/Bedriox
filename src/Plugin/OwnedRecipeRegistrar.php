<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

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
