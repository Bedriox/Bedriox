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

/** Immutable indexed adaptation of admitted workstation recipes. */
final readonly class FurnaceRecipeCatalog
{
    private const int WILDCARD_AUX_VALUE = 32_767;
    /** @var array<string, list<FurnaceRecipe>> */
    private array $byTypeAndInput;

    /** @var array<string, list<FurnaceRecipe>> */
    private array $byStationAndInput;

    public function __construct(RecipeRegistry $recipes)
    {
        $indexed = [];
        $stationIndexed = [];
        foreach ($recipes->workstationRecipes() as $definition) {
            $station = $definition->station();
            if ($station !== RecipeStation::FURNACE && $station !== RecipeStation::BLAST_FURNACE
                && $station !== RecipeStation::SMOKER && $station !== RecipeStation::CAMPFIRE
                && $station !== RecipeStation::SOUL_CAMPFIRE) {
                continue;
            }
            $recipe = self::adapt($definition);
            if ($recipe !== null) {
                $stationIndexed[$station->value . "\0" . $recipe->inputIdentifier][] = $recipe;
            }
        }
        foreach (FurnaceType::cases() as $type) {
            foreach ($stationIndexed as $key => $recipesForInput) {
                $prefix = $type->recipeStation()->value . "\0";
                if (str_starts_with($key, $prefix)) {
                    $indexed[$type->value . "\0" . substr($key, strlen($prefix))] = $recipesForInput;
                }
            }
        }
        $this->byTypeAndInput = $indexed;
        $this->byStationAndInput = $stationIndexed;
    }

    public function matchStation(RecipeStation $station, ContainerItemStack $input): ?FurnaceRecipe
    {
        foreach ($this->byStationAndInput[$station->value . "\0" . $input->identifier] ?? [] as $recipe) {
            if ($recipe->matches($input)) {
                return $recipe;
            }
        }
        return null;
    }

    public function match(FurnaceType $type, ContainerItemStack $input): ?FurnaceRecipe
    {
        foreach ($this->byTypeAndInput[$type->value . "\0" . $input->identifier] ?? [] as $recipe) {
            if ($recipe->matches($input)) {
                return $recipe;
            }
        }
        return null;
    }

    private static function adapt(RecipeDefinition $recipe): ?FurnaceRecipe
    {
        $ingredients = $recipe->ingredients();
        $outputs = $recipe->outputs();
        if (count($ingredients) !== 1 || count($outputs) !== 1) {
            return null;
        }
        $ingredient = $ingredients[0];
        $input = $ingredient->itemIdentifier();
        if ($input === null || $ingredient->count() !== 1) {
            return null;
        }
        $output = $outputs[0];
        $identifier = $recipe->identifier() ?? 'bedriox:workstation/' . $recipe->sourceIndex();

        return new FurnaceRecipe(
            $identifier,
            $input,
            $ingredient->auxValue() === self::WILDCARD_AUX_VALUE ? null : $ingredient->auxValue(),
            new ContainerItemStack(
                $output->itemIdentifier(),
                $output->count(),
                $output->damage(),
                $output->nbt() === null ? null : ItemNbt::fromBinary($output->nbt()),
            ),
            FurnaceGameplayRules::experienceFor($input),
        );
    }
}
