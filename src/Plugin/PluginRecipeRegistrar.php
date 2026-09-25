<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Crafting\RecipeIngredient as ApiRecipeIngredient;
use Bedriox\Api\Crafting\ShapedRecipe as ApiShapedRecipe;
use Bedriox\Api\Crafting\ShapelessRecipe as ApiShapelessRecipe;
use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Crafting\CraftingRecipe;
use Bedriox\Server\Gameplay\Crafting\CraftingRecipeRegistry;
use Bedriox\Server\Gameplay\Crafting\RecipeIngredient;
use Bedriox\Server\Gameplay\Crafting\RecipeOutput;
use Bedriox\Server\Gameplay\Crafting\ShapedRecipe;
use Bedriox\Server\Gameplay\Crafting\ShapelessRecipe;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\World\Block\BlockStateRegistry;
use InvalidArgumentException;
use Throwable;

/** @internal Adapts bounded plugin definitions into the active owner-scoped recipe registry. */
final class PluginRecipeRegistrar
{
    public const int MAXIMUM_RECIPES_PER_PLUGIN = 512;
    public const int MAXIMUM_PLUGIN_RECIPES = 4_096;

    private const string OWNERSHIP_RESOURCE = 'crafting-recipes';

    /** @var array<string, true> lowercase plugin owners with registered cleanup */
    private array $owned = [];

    private readonly CraftingRecipeRegistry $recipes;

    public function __construct(
        private readonly CraftingCatalog $catalog,
        private readonly PluginOwnershipRegistry $ownership,
        private readonly ItemCatalog $items,
        private readonly BlockStateRegistry $blocks,
    ) {
        $this->recipes = $catalog->recipes();
    }

    public function register(
        string $plugin,
        ApiShapedRecipe|ApiShapelessRecipe $recipe,
        bool $replace = false,
    ): void {
        $existing = $this->recipes->recipe($recipe->identifier());
        if ($existing !== null) {
            if ($existing->owner() === null) {
                throw new InvalidArgumentException('A plugin recipe cannot replace a built-in recipe.');
            }
            if (strcasecmp($existing->owner(), $plugin) !== 0) {
                throw new InvalidArgumentException('Crafting recipe is owned by another plugin.');
            }
        }
        if ($existing === null) {
            [$ownerCount, $totalPluginCount] = $this->pluginRecipeCounts($plugin);
            if ($ownerCount >= self::MAXIMUM_RECIPES_PER_PLUGIN) {
                throw new InvalidArgumentException('Plugin crafting recipe capacity is exhausted.');
            }
            if ($totalPluginCount >= self::MAXIMUM_PLUGIN_RECIPES) {
                throw new InvalidArgumentException('Server plugin crafting recipe capacity is exhausted.');
            }
        }

        $definition = $this->adapt($plugin, $recipe);
        $ownerKey = strtolower($plugin);
        $newOwnership = !isset($this->owned[$ownerKey]);
        if ($newOwnership) {
            $this->ownership->own($plugin, self::OWNERSHIP_RESOURCE, function () use ($plugin, $ownerKey): void {
                $this->catalog->unregisterOwnedBy($plugin);
                unset($this->owned[$ownerKey]);
            });
            $this->owned[$ownerKey] = true;
        }
        try {
            $this->catalog->register($definition, $replace);
        } catch (Throwable $failure) {
            if ($newOwnership) {
                $this->ownership->forget($plugin, self::OWNERSHIP_RESOURCE);
                unset($this->owned[$ownerKey]);
            }
            throw $failure;
        }
    }

    private function adapt(string $plugin, ApiShapedRecipe|ApiShapelessRecipe $recipe): CraftingRecipe
    {
        $outputs = array_map($this->output(...), $recipe->outputs());
        if ($recipe instanceof ApiShapedRecipe) {
            return new ShapedRecipe(
                $recipe->identifier(),
                $recipe->width,
                $recipe->height,
                array_map(
                    fn(?ApiRecipeIngredient $ingredient): ?RecipeIngredient => $ingredient === null
                        ? null
                        : $this->ingredient($ingredient),
                    $recipe->ingredients,
                ),
                $outputs,
                $recipe->priority(),
                $recipe->allowMirror,
                $plugin,
            );
        }

        return new ShapelessRecipe(
            $recipe->identifier(),
            array_map($this->ingredient(...), $recipe->ingredients),
            $outputs,
            $recipe->priority(),
            $plugin,
        );
    }

    private function ingredient(ApiRecipeIngredient $ingredient): RecipeIngredient
    {
        foreach ($ingredient->identifiers as $identifier) {
            $type = $this->items->type($identifier);
            if ($ingredient->count > $type->maximumStackSize) {
                throw new InvalidArgumentException('Recipe ingredient count exceeds an admitted item stack size.');
            }
        }

        return new RecipeIngredient(
            $ingredient->identifiers,
            $ingredient->count,
            $ingredient->auxValue,
            $ingredient->damage,
            $ingredient->nbt,
        );
    }

    private function output(ApiItemStack $output): RecipeOutput
    {
        $type = $this->items->type($output->identifier);
        if ($output->count > $type->maximumStackSize) {
            throw new InvalidArgumentException('Recipe output count exceeds the admitted item stack size.');
        }

        return new RecipeOutput(
            $output->identifier,
            $output->count,
            $output->damage,
            $output->nbt,
            $output->auxValue,
            $type->placedBlockState === null ? null : $this->blocks->internalId($type->placedBlockState),
        );
    }

    /** @return array{int, int} */
    private function pluginRecipeCounts(string $plugin): array
    {
        $ownerCount = 0;
        $total = 0;
        foreach ($this->recipes->all() as $recipe) {
            $owner = $recipe->owner();
            if ($owner === null) {
                continue;
            }
            ++$total;
            if (strcasecmp($owner, $plugin) === 0) {
                ++$ownerCount;
            }
        }

        return [$ownerCount, $total];
    }
}
