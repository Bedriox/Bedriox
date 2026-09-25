<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Gameplay\Crafting;

use Bedriox\Server\Gameplay\Crafting\CraftingGrid;
use Bedriox\Server\Gameplay\Crafting\CraftingRecipeRegistry;
use Bedriox\Server\Gameplay\Crafting\RecipeIngredient;
use Bedriox\Server\Gameplay\Crafting\RecipeOutput;
use Bedriox\Server\Gameplay\Crafting\ShapedRecipe;
use Bedriox\Server\Gameplay\Crafting\ShapelessRecipe;
use Bedriox\Server\Player\InventoryStack;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CraftingRecipeRegistryTest extends TestCase
{
    public function testShapedRecipeMatchesOffsetsAndMirrorsWithoutAcceptingExtraItems(): void
    {
        $recipe = new ShapedRecipe(
            'bedriox:test_stairs',
            2,
            2,
            [
                RecipeIngredient::exact('minecraft:stone'), null,
                RecipeIngredient::exact('minecraft:stone'), RecipeIngredient::exact('minecraft:stone'),
            ],
            [new RecipeOutput('minecraft:stone_stairs', 4)],
        );
        $stone = static fn(int $id): InventoryStack => new InventoryStack('minecraft:stone', 3, $id);
        $grid = new CraftingGrid(3, 3, [
            null, null, null,
            null, null, $stone(1),
            null, $stone(2), $stone(3),
        ]);

        $match = $recipe->match($grid, 2);

        self::assertNotNull($match);
        self::assertSame([5 => 2, 7 => 2, 8 => 2], $match->consumptionBySlot);
        self::assertSame(4, $match->outputs[0]->count);
        self::assertNull($recipe->match(new CraftingGrid(3, 3, [
            new InventoryStack('minecraft:dirt', 1, 9), null, null,
            null, null, $stone(1),
            null, $stone(2), $stone(3),
        ])));
    }

    public function testShapelessMatchingUsesExactAssignmentForOverlappingAlternatives(): void
    {
        $recipe = new ShapelessRecipe(
            'bedriox:test_overlap',
            [
                new RecipeIngredient(['minecraft:oak_planks', 'minecraft:birch_planks']),
                RecipeIngredient::exact('minecraft:oak_planks'),
            ],
            [new RecipeOutput('minecraft:stick', 4)],
        );
        $match = $recipe->match(new CraftingGrid(2, 2, [
            new InventoryStack('minecraft:oak_planks', 1, 1),
            new InventoryStack('minecraft:birch_planks', 1, 2),
            null,
            null,
        ]));

        self::assertNotNull($match);
        self::assertSame([0 => 1, 1 => 1], $match->consumptionBySlot);
    }

    public function testRegistryNetworkIdsAreDeterministicAndOwnerCleanupRestoresBuiltIn(): void
    {
        $builtIn = new ShapelessRecipe(
            'minecraft:sticks',
            [RecipeIngredient::exact('minecraft:oak_planks')],
            [new RecipeOutput('minecraft:stick', 4)],
            10,
        );
        $other = new ShapelessRecipe(
            'minecraft:button',
            [RecipeIngredient::exact('minecraft:stone')],
            [new RecipeOutput('minecraft:stone_button')],
        );
        $registry = new CraftingRecipeRegistry([$other, $builtIn]);
        self::assertSame(1, $registry->networkId('minecraft:sticks'));
        self::assertSame(2, $registry->networkId('minecraft:button'));

        $override = new ShapelessRecipe(
            'minecraft:sticks',
            [RecipeIngredient::exact('minecraft:birch_planks')],
            [new RecipeOutput('minecraft:stick', 8)],
            10,
            'Example',
        );
        $registry->register($override, true);
        self::assertSame($override, $registry->recipe('minecraft:sticks'));
        self::assertSame(1, $registry->networkId('minecraft:sticks'));
        self::assertSame(1, $registry->unregisterOwnedBy('example'));
        self::assertSame($builtIn, $registry->recipe('minecraft:sticks'));
        self::assertSame(1, $registry->networkId('minecraft:sticks'));

        $registry->reserveNetworkIds(2);
        $plugin = new ShapelessRecipe(
            'example:new_recipe',
            [RecipeIngredient::exact('minecraft:dirt')],
            [new RecipeOutput('minecraft:stone')],
            recipeOwner: 'Example',
        );
        $registry->register($plugin);
        self::assertSame(5, $registry->networkId('example:new_recipe'));
        self::assertSame(1, $registry->unregisterOwnedBy('example'));
        self::assertNull($registry->networkId('example:new_recipe'));
    }

    public function testRecipeCapacityAndInvalidValuesFailClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecipeIngredient([], 1);
    }
}
