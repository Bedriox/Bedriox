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
