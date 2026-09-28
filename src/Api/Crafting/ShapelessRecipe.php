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

namespace Bedriox\Api\Crafting;

use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

final readonly class ShapelessRecipe implements CraftingRecipe
{
    /** @var list<RecipeIngredient> */
    public array $ingredients;

    /** @var list<ItemStack> */
    private array $results;

    /**
     * @param array<array-key, mixed> $ingredients RecipeIngredient values.
     * @param array<array-key, mixed> $outputs ItemStack values.
     */
    public function __construct(
        private string $id,
        array $ingredients,
        array $outputs,
        private int $recipePriority = 0,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $id) !== 1) {
            throw new InvalidArgumentException('Recipe identifier must be canonical and namespaced.');
        }
        if (!array_is_list($ingredients) || $ingredients === [] || count($ingredients) > 9) {
            throw new InvalidArgumentException('Shapeless recipe ingredient count must be between one and nine.');
        }
        $validatedIngredients = [];
        foreach ($ingredients as $ingredient) {
            if (!$ingredient instanceof RecipeIngredient) {
                throw new InvalidArgumentException('Shapeless recipe contains an invalid ingredient.');
            }
            $validatedIngredients[] = $ingredient;
        }
        if (!array_is_list($outputs) || $outputs === [] || count($outputs) > 4) {
            throw new InvalidArgumentException('Recipe output count must be between one and four.');
        }
        $validatedOutputs = [];
        foreach ($outputs as $output) {
            if (!$output instanceof ItemStack) {
                throw new InvalidArgumentException('Recipe contains an invalid output.');
            }
            $validatedOutputs[] = $output;
        }
        if ($recipePriority < -32_768 || $recipePriority > 32_767) {
            throw new InvalidArgumentException('Recipe priority is outside its supported range.');
        }
        $this->ingredients = $validatedIngredients;
        $this->results = $validatedOutputs;
    }

    public function identifier(): string
    {
        return $this->id;
    }

    public function priority(): int
    {
        return $this->recipePriority;
    }

    public function outputs(): array
    {
        return $this->results;
    }
}
