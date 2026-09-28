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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Crafting\CraftingGrid;
use Bedriox\Api\Crafting\RecipeIngredient;
use Bedriox\Api\Crafting\ShapedRecipe;
use Bedriox\Api\Crafting\ShapelessRecipe;
use Bedriox\Api\Event\Player\PlayerCraftedItemEvent;
use Bedriox\Api\Event\Player\PlayerCraftItemEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CraftingApiTest extends TestCase
{
    public function testDefinesBoundedShapedAndShapelessRecipes(): void
    {
        $planks = new RecipeIngredient(['minecraft:oak_planks', 'minecraft:birch_planks']);
        $shaped = new ShapedRecipe(
            'example:sticks',
            1,
            2,
            [$planks, $planks],
            [new ItemStack('minecraft:stick', 4)],
            10,
            false,
        );
        $shapeless = new ShapelessRecipe(
            'example:button',
            [RecipeIngredient::exact('minecraft:stone')],
            [new ItemStack('minecraft:stone_button', 1)],
        );

        self::assertSame('example:sticks', $shaped->identifier());
        self::assertSame(10, $shaped->priority());
        self::assertFalse($shaped->allowMirror);
        self::assertSame('example:button', $shapeless->identifier());
        self::assertCount(2, $planks->identifiers);
    }

    public function testCraftPreEventRestoresControlledOutputAndCancellationState(): void
    {
        $recipe = new ShapelessRecipe(
            'example:dye',
            [RecipeIngredient::exact('minecraft:red_dye')],
            [new ItemStack('minecraft:red_wool', 2)],
        );
        $grid = new CraftingGrid(2, 2, [new ItemStack('minecraft:red_dye', 1), null, null, null]);
        $event = new PlayerCraftItemEvent(
            self::player(),
            $recipe,
            $grid,
            1,
            [new ItemStack('minecraft:red_dye', 1)],
            $recipe->outputs(),
        );
        $state = $event->captureState();
        $replacement = [new ItemStack('minecraft:blue_wool', 1)];
        $event->setOutputs($replacement);
        $event->cancel();

        self::assertSame($replacement, $event->outputs());
        self::assertTrue($event->isCancelled());

        $event->restoreState($state);
        self::assertSame($recipe->outputs(), $event->outputs());
        self::assertFalse($event->isCancelled());
    }

    public function testCraftPreEventRejectsAnOutputIncrease(): void
    {
        $recipe = new ShapelessRecipe(
            'example:dye',
            [RecipeIngredient::exact('minecraft:red_dye')],
            [new ItemStack('minecraft:red_wool', 1)],
        );
        $event = new PlayerCraftItemEvent(
            self::player(),
            $recipe,
            new CraftingGrid(2, 2, [new ItemStack('minecraft:red_dye', 1), null, null, null]),
            1,
            [new ItemStack('minecraft:red_dye', 1)],
            $recipe->outputs(),
        );

        $this->expectException(InvalidArgumentException::class);
        $event->setOutputs([new ItemStack('minecraft:diamond', 2)]);
    }

    public function testCraftedEventIsAnImmutablePostEvent(): void
    {
        $recipe = new ShapelessRecipe(
            'example:button',
            [RecipeIngredient::exact('minecraft:stone')],
            [new ItemStack('minecraft:stone_button', 1)],
        );
        $event = new PlayerCraftedItemEvent(
            self::player(),
            $recipe,
            new CraftingGrid(2, 2, [new ItemStack('minecraft:stone', 1), null, null, null]),
            1,
            [new ItemStack('minecraft:stone', 1)],
            $recipe->outputs(),
        );

        self::assertInstanceOf(PostEvent::class, $event);
        self::assertSame($recipe->outputs(), $event->outputs);
    }

    private static function player(): Player
    {
        return new Player(
            'One',
            'identity-one',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
