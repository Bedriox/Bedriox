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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Data\RecipeDefinition;
use Bedriox\Data\RecipeRegistry;
use Bedriox\Data\RecipeStation;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;

/** Resolves a client-selected stonecutting recipe against admitted server recipe data. */
final readonly class StonecutterProcessor
{
    /** @var array<int, RecipeDefinition> */
    private array $recipesBySourceIndex;

    /** @var array<string, list<RecipeDefinition>> */
    private array $recipesByInput;

    public function __construct(RecipeRegistry $recipes)
    {
        $indexed = [];
        $byInput = [];
        foreach ($recipes->recipesForStation(RecipeStation::STONECUTTER) as $recipe) {
            if (count($recipe->ingredients()) === 1 && count($recipe->outputs()) === 1) {
                $indexed[$recipe->sourceIndex()] = $recipe;
                $identifier = $recipe->ingredients()[0]->itemIdentifier();
                if ($identifier !== null) {
                    $byInput[$identifier][] = $recipe;
                }
            }
        }
        $this->recipesBySourceIndex = $indexed;
        $this->recipesByInput = $byInput;
    }

    /** @return list<int> Stable admitted recipe source indexes matching this exact input. */
    public function choices(ContainerItemStack $input): array
    {
        $choices = [];
        foreach ($this->recipesByInput[$input->identifier] ?? [] as $recipe) {
            if ($this->matches($recipe, $input)) {
                $choices[] = $recipe->sourceIndex();
            }
        }
        return $choices;
    }

    public function process(ContainerItemStack $input, int $sourceIndex): ?WorkstationResult
    {
        $recipe = $this->recipesBySourceIndex[$sourceIndex] ?? null;
        if ($recipe === null || !$this->matches($recipe, $input)) {
            return null;
        }
        $ingredient = $recipe->ingredients()[0];
        $output = $recipe->outputs()[0];

        return new WorkstationResult(
            [0 => $ingredient->count()],
            [new ContainerItemStack(
                $output->itemIdentifier(),
                $output->count(),
                $output->damage(),
                $output->nbt() === null ? null : ItemNbt::fromBinary($output->nbt()),
            )],
        );
    }

    private function matches(RecipeDefinition $recipe, ContainerItemStack $input): bool
    {
        $ingredient = $recipe->ingredients()[0];
        return $ingredient->itemIdentifier() === $input->identifier
            && $input->count >= $ingredient->count()
            && ($ingredient->auxValue() === null || $ingredient->auxValue() === 32_767
                || $ingredient->auxValue() === $input->auxValue);
    }
}
