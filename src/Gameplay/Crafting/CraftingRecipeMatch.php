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
