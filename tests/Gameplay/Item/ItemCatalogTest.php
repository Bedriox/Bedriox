<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Gameplay\Item;

use Bedriox\Api\Inventory\ItemDefinition;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Block\BlockDropKind;
use Bedriox\Server\Gameplay\Block\BlockType;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ToolTier;
use Bedriox\Server\Gameplay\Item\ToolType;
use Bedriox\Server\Plugin\OwnedItemRegistrar;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\VanillaBlockStates;
use PHPUnit\Framework\TestCase;

final class ItemCatalogTest extends TestCase
{
    public function testVanillaCatalogAdmitsInitialItemsAgainstCurrentData(): void
    {
        $catalog = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());

        self::assertCount(77, $catalog->all());
        self::assertCount(77, $catalog->creativeItems());
        self::assertTrue($catalog->type('minecraft:grass_block')->isPlaceable());
        self::assertSame('minecraft:grass_block', $catalog->type('minecraft:grass_block')->placedBlockState?->identifier());
        self::assertSame('minecraft:cobblestone', $catalog->type('minecraft:cobblestone')->placedBlockState?->identifier());
        self::assertSame('minecraft:cobbled_deepslate', $catalog->type('minecraft:cobbled_deepslate')->placedBlockState?->identifier());
        self::assertSame(16, $catalog->type('minecraft:snowball')->maximumStackSize);
        self::assertSame(64, $catalog->type('minecraft:diamond')->maximumStackSize);
    }

    public function testBlockItemFormsComeFromTheSuppliedBlockCatalog(): void
    {
        $blocks = new BlockCatalog([
            new BlockType(VanillaBlockStates::air(), -1.0, null, null, BlockDropKind::None, false),
            new BlockType(VanillaBlockStates::cobblestone(), 2.0, null, null, BlockDropKind::Self),
        ]);
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry(), $blocks);

        self::assertFalse($items->has('minecraft:air'));
        self::assertFalse($items->has('minecraft:stone'));
        self::assertSame(
            VanillaBlockStates::cobblestone()->canonicalKey(),
            $items->type('minecraft:cobblestone')->placedBlockState?->canonicalKey(),
        );
        self::assertFalse($items->type('minecraft:diamond')->isPlaceable());
    }

    public function testGeneratedCatalogAdmitsOnlyPlaceableBlockItemForms(): void
    {
        $data = BedrockDataSet::bundled();
        $blocks = BlockCatalog::vanilla(new BlockStateRegistry($data->blockStateRegistry()->states()));
        $items = ItemCatalog::vanilla($data->itemNetworkRegistry(), $blocks);

        self::assertTrue($items->type('minecraft:short_grass')->isPlaceable());
        self::assertTrue($items->type('minecraft:glass_pane')->isPlaceable());
        self::assertFalse($items->has('minecraft:farmland'));
        self::assertFalse($items->has('minecraft:grass_path'));
        self::assertFalse($items->has('minecraft:wheat'));
        self::assertFalse($items->has('minecraft:water'));
        self::assertFalse($items->has('minecraft:lava'));
    }

    public function testTieredToolsAndShearsHaveAuthoritativeProperties(): void
    {
        $catalog = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        $woodenSword = $catalog->type('minecraft:wooden_sword');
        $goldenPickaxe = $catalog->type('minecraft:golden_pickaxe');
        $copperAxe = $catalog->type('minecraft:copper_axe');
        $netheriteShovel = $catalog->type('minecraft:netherite_shovel');
        $shears = $catalog->type('minecraft:shears');
        self::assertNotNull($woodenSword->tool);
        self::assertNotNull($goldenPickaxe->tool);
        self::assertNotNull($copperAxe->tool);
        self::assertNotNull($netheriteShovel->tool);
        self::assertNotNull($shears->tool);

        self::assertSame(ToolType::Sword, $woodenSword->tool->type);
        self::assertSame(ToolTier::Wood, $woodenSword->tool->tier);
        self::assertSame(60, $woodenSword->tool->durability);
        self::assertSame(2, $woodenSword->tool->durabilityDamagePerBlock);
        self::assertSame(1, $woodenSword->tool->durabilityDamagePerAttack);
        self::assertSame(12.0, $goldenPickaxe->tool->miningEfficiency);
        self::assertSame(191, $copperAxe->tool->durability);
        self::assertSame(2_032, $netheriteShovel->tool->durability);
        self::assertSame(ToolType::Shears, $shears->tool->type);
        self::assertNull($shears->tool->tier);
        self::assertSame(239, $shears->tool->durability);
        self::assertSame(0, $shears->tool->durabilityDamagePerAttack);
        self::assertSame(1, $shears->maximumStackSize);
    }

    public function testPluginReplacementPreservesExistingGameplayBehavior(): void
    {
        $catalog = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        $before = $catalog->type('minecraft:grass_block');

        (new OwnedItemRegistrar('TestPlugin', $catalog))->register(
            new ItemDefinition('minecraft:grass_block', 32, false),
            true,
        );

        $after = $catalog->type('minecraft:grass_block');
        self::assertSame(1, $catalog->revision());
        self::assertSame(32, $after->maximumStackSize);
        self::assertFalse($after->creative);
        self::assertSame('TestPlugin', $after->owner);
        self::assertSame($before->placedBlockState?->canonicalKey(), $after->placedBlockState?->canonicalKey());
    }
}
