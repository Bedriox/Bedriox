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
        $data = BedrockDataSet::bundled();
        $catalog = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );

        self::assertCount(count($data->itemNetworkRegistry()->definitions()) - 1, $catalog->all());
        foreach ($data->itemNetworkRegistry()->definitions() as $identifier => $_definition) {
            if ($identifier === 'minecraft:air') {
                self::assertFalse($catalog->has($identifier));
                continue;
            }
            self::assertSame($identifier, $catalog->type($identifier)->identifier);
        }

        $creativeIdentifiers = [];
        foreach ($data->creativeInventoryRegistry()->entries() as $entry) {
            $creativeIdentifiers[$entry->item()->identifier()] = true;
        }
        self::assertCount(count($creativeIdentifiers), $catalog->creativeItems());
        self::assertTrue($catalog->type('minecraft:grass_block')->isPlaceable());
        self::assertSame('minecraft:grass_block', $catalog->type('minecraft:grass_block')->placedBlockState?->identifier());
        self::assertSame('minecraft:cobblestone', $catalog->type('minecraft:cobblestone')->placedBlockState?->identifier());
        self::assertSame('minecraft:cobbled_deepslate', $catalog->type('minecraft:cobbled_deepslate')->placedBlockState?->identifier());
        self::assertSame(16, $catalog->type('minecraft:snowball')->maximumStackSize);
        self::assertSame(64, $catalog->type('minecraft:diamond')->maximumStackSize);
        self::assertCount(count($catalog->all()), $catalog->commandIdentifiers());
        self::assertContains('diamond', $catalog->commandIdentifiers());
        self::assertNotContains('minecraft:diamond', $catalog->commandIdentifiers());
    }

    public function testBlockItemFormsComeFromTheSuppliedBlockCatalog(): void
    {
        $blocks = new BlockCatalog([
            new BlockType(VanillaBlockStates::air(), -1.0, null, null, BlockDropKind::None, false),
            new BlockType(VanillaBlockStates::cobblestone(), 2.0, null, null, BlockDropKind::Self),
        ]);
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry(), $blocks);

        self::assertFalse($items->has('minecraft:air'));
        self::assertFalse($items->type('minecraft:stone')->isPlaceable());
        self::assertSame(
            VanillaBlockStates::cobblestone()->canonicalKey(),
            $items->type('minecraft:cobblestone')->placedBlockState?->canonicalKey(),
        );
        self::assertFalse($items->type('minecraft:diamond')->isPlaceable());
    }

    public function testGeneratedCatalogUsesEveryAdmittedBlockItemMapping(): void
    {
        $data = BedrockDataSet::bundled();
        $blocks = BlockCatalog::vanilla(new BlockStateRegistry($data->blockStateRegistry()->states()));
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $blocks,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );

        self::assertTrue($items->type('minecraft:short_grass')->isPlaceable());
        self::assertTrue($items->type('minecraft:glass_pane')->isPlaceable());
        foreach ($data->blockItemMappingRegistry()->mappings() as $mapping) {
            self::assertSame(
                $mapping->blockState()->canonicalKey(),
                $items->type($mapping->itemIdentifier())->placedBlockState?->canonicalKey(),
            );
        }
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

        $bow = $catalog->type('minecraft:bow');
        self::assertSame(1, $bow->maximumStackSize);
        self::assertSame(385, $bow->durability());
        self::assertSame(60, $woodenSword->durability());
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
