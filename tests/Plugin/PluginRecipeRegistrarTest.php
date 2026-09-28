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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Crafting\RecipeIngredient as ApiRecipeIngredient;
use Bedriox\Api\Crafting\ShapedRecipe as ApiShapedRecipe;
use Bedriox\Api\Crafting\ShapelessRecipe as ApiShapelessRecipe;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Crafting\CraftingRecipeRegistry;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Plugin\OwnedRecipeRegistrar;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRecipeRegistrar;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PluginRecipeRegistrarTest extends TestCase
{
    public function testRegistersAndReplacesOwnedRecipeWithoutChangingItsNetworkId(): void
    {
        [$registry, $ownership, $registrar, $catalog] = self::registrar();
        $initialRevision = $catalog->revision();
        $owned = new OwnedRecipeRegistrar('Example', $registrar);
        $owned->register(new ApiShapedRecipe(
            'example:stone_to_dirt',
            1,
            1,
            [ApiRecipeIngredient::exact('minecraft:stone')],
            [new ItemStack('minecraft:dirt', 1)],
        ));
        $networkId = $registry->networkId('example:stone_to_dirt');

        $owned->register(new ApiShapelessRecipe(
            'example:stone_to_dirt',
            [ApiRecipeIngredient::exact('minecraft:stone')],
            [new ItemStack('minecraft:dirt', 2)],
        ), true);

        $registered = $registry->recipe('example:stone_to_dirt');
        self::assertNotNull($registered);
        self::assertNotNull($networkId);
        self::assertSame($networkId, $registry->networkId('example:stone_to_dirt'));
        self::assertSame('Example', $registered->owner());
        self::assertSame(1, $ownership->count('Example'));
        self::assertSame(2, $registered->outputs()[0]->count);
        self::assertSame($initialRevision + 2, $catalog->revision());
        self::assertCount(4_143, $catalog->protocolRecipes());
    }

    public function testOwnerCleanupRemovesRecipesAndDoesNotReuseTheirNetworkIds(): void
    {
        [$registry, $ownership, $registrar, $catalog] = self::registrar();
        $example = new OwnedRecipeRegistrar('Example', $registrar);
        $example->register(new ApiShapelessRecipe(
            'example:first',
            [ApiRecipeIngredient::exact('minecraft:stone')],
            [new ItemStack('minecraft:dirt', 1)],
        ));
        $releasedId = $registry->networkId('example:first');

        self::assertSame([], $ownership->releaseAll('example'));
        self::assertNull($registry->recipe('example:first'));
        self::assertNull($registry->networkId('example:first'));
        self::assertCount(4_142, $catalog->protocolRecipes());

        (new OwnedRecipeRegistrar('Other', $registrar))->register(new ApiShapelessRecipe(
            'other:second',
            [ApiRecipeIngredient::exact('minecraft:stone')],
            [new ItemStack('minecraft:dirt', 1)],
        ));
        self::assertGreaterThan($releasedId ?? 0, $registry->networkId('other:second'));
    }

    public function testRejectsBuiltInReplacementAndInvalidOutputStackSize(): void
    {
        [$registry, $_ownership, $registrar] = self::registrar();
        $owned = new OwnedRecipeRegistrar('Example', $registrar);
        $builtInIdentifier = $registry->all()[0]->identifier();

        try {
            $owned->register(new ApiShapelessRecipe(
                $builtInIdentifier,
                [ApiRecipeIngredient::exact('minecraft:stone')],
                [new ItemStack('minecraft:dirt', 1)],
            ), true);
            self::fail('Built-in recipe replacement was accepted.');
        } catch (InvalidArgumentException) {
            self::assertNotNull($registry->recipe($builtInIdentifier));
        }

        $this->expectException(InvalidArgumentException::class);
        $owned->register(new ApiShapelessRecipe(
            'example:stacked_pickaxes',
            [ApiRecipeIngredient::exact('minecraft:stone')],
            [new ItemStack('minecraft:iron_pickaxe', 2)],
        ));
    }

    public function testExpandsBoundedIngredientAlternativesIntoStableNetworkAliases(): void
    {
        [$registry, $_ownership, $registrar, $catalog] = self::registrar();

        (new OwnedRecipeRegistrar('Example', $registrar))->register(new ApiShapelessRecipe(
            'example:alternative_planks',
            [new ApiRecipeIngredient(['minecraft:oak_planks', 'minecraft:birch_planks'])],
            [new ItemStack('minecraft:stick', 1)],
        ));

        $networkIds = $registry->networkIdsFor('example:alternative_planks');
        self::assertCount(2, $networkIds);
        self::assertSame(
            $registry->recipe('example:alternative_planks'),
            $registry->recipeByNetworkId($networkIds[1]),
        );
        self::assertCount(4_144, $catalog->protocolRecipes());

        (new OwnedRecipeRegistrar('Example', $registrar))->register(new ApiShapelessRecipe(
            'example:alternative_planks',
            [new ApiRecipeIngredient(['minecraft:birch_planks', 'minecraft:oak_planks'])],
            [new ItemStack('minecraft:stick', 2)],
        ), true);
        self::assertSame($networkIds, $registry->networkIdsFor('example:alternative_planks'));
        self::assertCount(4_144, $catalog->protocolRecipes());
    }

    public function testUnprojectableIngredientConstraintFailsWithoutPublishingARevisionOrOwnership(): void
    {
        [$registry, $ownership, $registrar, $catalog] = self::registrar();
        $revision = $catalog->revision();

        try {
            (new OwnedRecipeRegistrar('Example', $registrar))->register(new ApiShapelessRecipe(
                'example:damaged_stone',
                [ApiRecipeIngredient::exact('minecraft:stone', damage: 1)],
                [new ItemStack('minecraft:dirt', 1)],
            ));
            self::fail('An unprojectable plugin recipe was accepted.');
        } catch (InvalidArgumentException) {
            self::assertNull($registry->recipe('example:damaged_stone'));
            self::assertSame($revision, $catalog->revision());
            self::assertSame(0, $ownership->count('Example'));
            self::assertCount(4_142, $catalog->protocolRecipes());
        }
    }

    /** @return array{CraftingRecipeRegistry, PluginOwnershipRegistry, PluginRecipeRegistrar, CraftingCatalog} */
    private static function registrar(): array
    {
        $data = BedrockDataSet::bundled();
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $catalog = CraftingCatalog::fromData(
            $data,
            $items,
            $states,
            BedrockInventoryPacketProjector::fromData(
                $data,
                new BlockNetworkTranslator($states, $data->blockStateRegistry()),
                $items,
            ),
        );
        $registry = $catalog->recipes();
        $ownership = new PluginOwnershipRegistry();

        return [$registry, $ownership, new PluginRecipeRegistrar($catalog, $ownership, $items, $states), $catalog];
    }
}
