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
        self::assertSame(1, $catalog->type('minecraft:shulker_box')->maximumStackSize);
        self::assertSame(1, $catalog->type('minecraft:red_shulker_box')->maximumStackSize);
        self::assertSame(
            'minecraft:undyed_shulker_box',
            $catalog->type('minecraft:shulker_box')->placedBlockState?->identifier(),
        );
        self::assertSame(
            'minecraft:red_shulker_box',
            $catalog->type('minecraft:red_shulker_box')->placedBlockState?->identifier(),
        );
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
        foreach ([
            'minecraft:brewing_stand',
            'minecraft:campfire',
            'minecraft:cauldron',
            'minecraft:soul_campfire',
            'minecraft:stonecutter',
        ] as $identifier) {
            self::assertTrue($items->type($identifier)->isPlaceable(), $identifier);
        }
        self::assertSame(
            'minecraft:stonecutter_block',
            $items->type('minecraft:stonecutter')->placedBlockState?->identifier(),
        );
    }

    public function testTieredToolsAndShearsHaveAuthoritativeProperties(): void
    {
        $catalog = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        $woodenSword = $catalog->type('minecraft:wooden_sword');
        $goldenPickaxe = $catalog->type('minecraft:golden_pickaxe');
        $copperAxe = $catalog->type('minecraft:copper_axe');
        $diamondSpear = $catalog->type('minecraft:diamond_spear');
        $netheriteShovel = $catalog->type('minecraft:netherite_shovel');
        $shears = $catalog->type('minecraft:shears');
        self::assertNotNull($woodenSword->tool);
        self::assertNotNull($goldenPickaxe->tool);
        self::assertNotNull($copperAxe->tool);
        self::assertNotNull($diamondSpear->tool);
        self::assertNotNull($netheriteShovel->tool);
        self::assertNotNull($shears->tool);

        self::assertSame(ToolType::Sword, $woodenSword->tool->type);
        self::assertSame(ToolTier::Wood, $woodenSword->tool->tier);
        self::assertSame(60, $woodenSword->tool->durability);
        self::assertSame(2, $woodenSword->tool->durabilityDamagePerBlock);
        self::assertSame(1, $woodenSword->tool->durabilityDamagePerAttack);
        self::assertSame(12.0, $goldenPickaxe->tool->miningEfficiency);
        self::assertSame(191, $copperAxe->tool->durability);
        self::assertSame(ToolType::Spear, $diamondSpear->tool->type);
        self::assertSame(5.0, $diamondSpear->tool->attackDamage());
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
