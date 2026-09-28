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

use InvalidArgumentException;

final readonly class ShapedRecipe implements CraftingRecipe
{
    /** @var list<RecipeIngredient|null> */
    private array $ingredients;

    /** @var list<RecipeOutput> */
    private array $results;

    /**
     * @param array<array-key, mixed> $ingredients row-major pattern
     * @param array<array-key, mixed> $outputs
     */
    public function __construct(
        private string $id,
        public int $width,
        public int $height,
        array $ingredients,
        array $outputs,
        private int $recipePriority = 0,
        public bool $allowMirror = true,
        private ?string $recipeOwner = null,
    ) {
        self::validateIdentifier($id);
        if ($width < 1 || $width > 3 || $height < 1 || $height > 3
            || !array_is_list($ingredients) || count($ingredients) !== $width * $height) {
            throw new InvalidArgumentException('Shaped recipe dimensions or ingredient count are invalid.');
        }
        if (!array_is_list($outputs) || $outputs === [] || count($outputs) > 16) {
            throw new InvalidArgumentException('Shaped recipe output count is outside its supported bounds.');
        }
        $hasIngredient = false;
        $validatedIngredients = [];
        foreach ($ingredients as $ingredient) {
            if ($ingredient !== null && !$ingredient instanceof RecipeIngredient) {
                throw new InvalidArgumentException('Shaped recipe contains an invalid ingredient.');
            }
            $hasIngredient = $hasIngredient || $ingredient !== null;
            $validatedIngredients[] = $ingredient;
        }
        if (!$hasIngredient) {
            throw new InvalidArgumentException('Shaped recipe must contain at least one ingredient.');
        }
        $validatedOutputs = [];
        foreach ($outputs as $output) {
            if (!$output instanceof RecipeOutput) {
                throw new InvalidArgumentException('Shaped recipe contains an invalid output.');
            }
            $validatedOutputs[] = $output;
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

    public function owner(): ?string
    {
        return $this->recipeOwner;
    }

    public function outputs(): array
    {
        return $this->results;
    }

    public function ingredients(): array
    {
        $ingredients = [];
        foreach ($this->ingredients as $ingredient) {
            if ($ingredient !== null) {
                $ingredients[] = $ingredient;
            }
        }

        return $ingredients;
    }

    /** @return list<RecipeIngredient|null> */
    public function ingredientSlots(): array
    {
        return $this->ingredients;
    }

    public function outputsFor(CraftingGrid $grid): array
    {
        return $this->results;
    }

    public function outputsForInputs(array $inputs): array
    {
        return $this->results;
    }

    public function match(CraftingGrid $grid, int $repetitions = 1): ?CraftingRecipeMatch
    {
        if ($repetitions < 1 || $repetitions > 255 || $grid->width < $this->width || $grid->height < $this->height) {
            return null;
        }
        foreach ([false, true] as $mirrored) {
            if ($mirrored && !$this->allowMirror) {
                continue;
            }
            for ($offsetY = 0; $offsetY <= $grid->height - $this->height; ++$offsetY) {
                for ($offsetX = 0; $offsetX <= $grid->width - $this->width; ++$offsetX) {
                    $consumption = $this->matchAt($grid, $offsetX, $offsetY, $mirrored, $repetitions);
                    if ($consumption !== null) {
                        return new CraftingRecipeMatch($this->id, $repetitions, $consumption, $this->results);
                    }
                }
            }
        }

        return null;
    }

    /** @return null|array<int, int> */
    private function matchAt(CraftingGrid $grid, int $offsetX, int $offsetY, bool $mirrored, int $repetitions): ?array
    {
        $consumption = [];
        for ($gridY = 0; $gridY < $grid->height; ++$gridY) {
            for ($gridX = 0; $gridX < $grid->width; ++$gridX) {
                $patternX = $gridX - $offsetX;
                $patternY = $gridY - $offsetY;
                $ingredient = null;
                if ($patternX >= 0 && $patternX < $this->width && $patternY >= 0 && $patternY < $this->height) {
                    $sourceX = $mirrored ? $this->width - 1 - $patternX : $patternX;
                    $ingredient = $this->ingredients[$patternY * $this->width + $sourceX];
                }
                $stack = $grid->slot($gridX, $gridY);
                if ($ingredient === null) {
                    if ($stack !== null) {
                        return null;
                    }
                    continue;
                }
                if ($stack === null || !$ingredient->accepts($stack, $repetitions)) {
                    return null;
                }
                $consumption[$gridY * $grid->width + $gridX] = $ingredient->count * $repetitions;
            }
        }

        return $consumption;
    }

    private static function validateIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Recipe identifier must be canonical and namespaced.');
        }
    }
}
