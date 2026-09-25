<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Gameplay\Crafting;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\ShapedCraftingRecipe;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Crafting\CraftingGrid;
use Bedriox\Server\Gameplay\Crafting\RecipeIngredient;
use Bedriox\Server\Gameplay\Crafting\RecipeOutput;
use Bedriox\Server\Gameplay\Crafting\ShapedRecipe;
use Bedriox\Server\Gameplay\Crafting\ShapelessRecipe;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\TestCase;

final class CraftingCatalogTest extends TestCase
{
    public function testBundledCraftingCatalogBuildsCompleteGridSnapshot(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $translator = new BlockNetworkTranslator($states, $data->blockStateRegistry());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $catalog = CraftingCatalog::fromData(
            $data,
            $items,
            $states,
            BedrockInventoryPacketProjector::fromData($data, $translator, $items),
        );

        self::assertCount(4_128, $catalog->recipes()->all());
        self::assertCount(4_142, $catalog->protocolRecipes());
        self::assertNotNull($catalog->recipes()->recipeByNetworkId(1));
        self::assertNotNull($catalog->complexUuid(4_129));

        $bedSource = $data->recipeRegistry()->recipesForIdentifier('bed_color_0')[0];
        $bedRecipe = $catalog->recipes()->recipe('minecraft:recipe/' . $bedSource->sourceIndex());
        self::assertNotNull($bedRecipe);
        self::assertSame(0, $bedRecipe->outputs()[0]->damage);
        self::assertSame(15, $bedRecipe->outputs()[0]->auxValue);
        $bedNetworkId = $catalog->recipes()->networkId($bedRecipe->identifier());
        $bedProjection = array_values(array_filter(
            $catalog->protocolRecipes(),
            static fn($recipe): bool => $recipe->networkId() === $bedNetworkId,
        ));
        self::assertCount(1, $bedProjection);
        self::assertInstanceOf(ShapedCraftingRecipe::class, $bedProjection[0]);
        self::assertSame(15, $bedProjection[0]->results[0]->aux);

        $catalog->recipes()->register(new ShapelessRecipe(
            'example:reserved_after_complex',
            [RecipeIngredient::exact('minecraft:stone')],
            [new RecipeOutput('minecraft:dirt')],
            recipeOwner: 'Example',
        ));
        self::assertSame(4_143, $catalog->recipes()->networkId('example:reserved_after_complex'));
    }

    public function testEveryBundledStaticGridRecipeHasAnExecutableAuthoritativeMatch(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
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

        foreach ($catalog->recipes()->all() as $recipe) {
            $source = $recipe instanceof ShapedRecipe ? $recipe->ingredientSlots() : $recipe->ingredients();
            $slots = [];
            $networkId = 1;
            foreach ($source as $ingredient) {
                $slots[] = $ingredient === null
                    ? null
                    : new InventoryStack(
                        $ingredient->identifiers[0],
                        $ingredient->count,
                        $networkId++,
                        damage: $ingredient->damage ?? 0,
                        nbt: $ingredient->nbt,
                        auxValue: $ingredient->auxValue ?? 0,
                    );
            }
            if ($recipe instanceof ShapedRecipe) {
                $grid = new CraftingGrid($recipe->width, $recipe->height, $slots);
            } else {
                $slots = [...$slots, ...array_fill(0, 9 - count($slots), null)];
                $grid = new CraftingGrid(3, 3, $slots);
            }

            self::assertNotNull($recipe->match($grid), $recipe->identifier());
        }
    }
}
