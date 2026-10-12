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

use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\ItemTag;
use Bedriox\Api\Inventory\ItemTagRegistry;
use Bedriox\Api\Inventory\VanillaItems;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use Bedriox\Api\World\VanillaBlocks;
use Bedriox\Data\AdmittedRegistryDataSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SemanticTagRegistryTest extends TestCase
{
    public function testApiValuesSupportExactAndSemanticIdentityChecks(): void
    {
        $door = new Block(
            new BlockPosition(4, 70, -2),
            VanillaBlocks::OAK_DOOR,
            ['door_hinge_bit' => 1, 'open_bit' => 0],
        );
        self::assertSame(VanillaBlocks::OAK_DOOR, $door->getIdentifier());
        self::assertTrue($door->is(VanillaBlocks::OAK_DOOR));
        self::assertTrue($door->is(BlockTag::DOOR));
        self::assertTrue($door->hasTag(BlockTag::DOOR));
        self::assertFalse($door->is(BlockTag::TRAPDOOR));
        self::assertContains(BlockTag::DOOR, $door->getTags());

        $sword = new ItemStack(VanillaItems::DIAMOND_SWORD, 1, damage: 7);
        self::assertSame(VanillaItems::DIAMOND_SWORD, $sword->getIdentifier());
        self::assertTrue($sword->is(VanillaItems::DIAMOND_SWORD));
        self::assertTrue($sword->is(ItemTag::SWORD));
        self::assertTrue($sword->hasTag(ItemTag::SWORD));
        self::assertFalse($sword->is(ItemTag::AXE));
        self::assertContains(ItemTag::SWORD, $sword->getTags());
    }

    public function testBlockMembershipIsExactAndSupportsOverlappingFamilies(): void
    {
        self::assertTrue(BlockTagRegistry::contains(BlockTag::DOOR, 'minecraft:wooden_door'));
        self::assertTrue(BlockTagRegistry::contains(BlockTag::DOOR, 'minecraft:waxed_oxidized_copper_door'));
        self::assertFalse(BlockTagRegistry::contains(BlockTag::DOOR, 'minecraft:trapdoor'));
        self::assertTrue(BlockTagRegistry::contains(BlockTag::TRAPDOOR, 'minecraft:trapdoor'));
        self::assertSame(
            [BlockTag::HANGING_SIGN, BlockTag::SIGN],
            BlockTagRegistry::tagsFor('minecraft:oak_hanging_sign'),
        );
        self::assertTrue(BlockTagRegistry::contains(BlockTag::STAIRS, 'minecraft:oak_stairs'));
        self::assertTrue(BlockTagRegistry::contains(BlockTag::DOUBLE_SLAB, 'minecraft:oak_double_slab'));
        self::assertFalse(BlockTagRegistry::contains(BlockTag::SLAB, 'minecraft:oak_double_slab'));
        self::assertTrue(BlockTagRegistry::contains(BlockTag::GRAVITY_AFFECTED, 'minecraft:suspicious_sand'));
        self::assertFalse(BlockTagRegistry::contains(BlockTag::GRAVITY_AFFECTED, 'minecraft:soul_sand'));
        self::assertFalse(BlockTagRegistry::contains(BlockTag::DOOR, 'example:custom_door'));
    }

    public function testItemMembershipIsExactAndSupportsOverlappingFamilies(): void
    {
        self::assertTrue(ItemTagRegistry::contains(ItemTag::SWORD, 'minecraft:diamond_sword'));
        self::assertFalse(ItemTagRegistry::contains(ItemTag::SWORD, 'minecraft:diamond_axe'));
        self::assertSame(
            [ItemTag::ARMOR, ItemTag::HELMET],
            ItemTagRegistry::tagsFor('minecraft:diamond_helmet'),
        );
        self::assertTrue(ItemTagRegistry::contains(ItemTag::FOOD, 'minecraft:apple'));
        self::assertTrue(ItemTagRegistry::contains(ItemTag::CONSUMABLE, 'minecraft:milk_bucket'));
        self::assertFalse(ItemTagRegistry::contains(ItemTag::FOOD, 'minecraft:milk_bucket'));
        self::assertTrue(ItemTagRegistry::contains(ItemTag::SPAWN_EGG, 'minecraft:zombie_spawn_egg'));
        self::assertTrue(ItemTagRegistry::contains(ItemTag::FUEL, 'minecraft:oak_planks'));
        self::assertFalse(ItemTagRegistry::contains(ItemTag::FUEL, 'minecraft:stone'));
        self::assertFalse(ItemTagRegistry::contains(ItemTag::SWORD, 'example:custom_sword'));
    }

    public function testEveryBuiltInMemberExistsInTheAdmittedDataSet(): void
    {
        $data = AdmittedRegistryDataSet::bundled();
        $blocks = [];
        foreach ($data->blockStateRegistry()->states() as $state) {
            $blocks[$state->identifier()] = true;
        }
        foreach (BlockTag::cases() as $tag) {
            $members = BlockTagRegistry::members($tag);
            self::assertNotSame([], $members, $tag->value . ' must not be empty.');
            foreach ($members as $identifier) {
                self::assertArrayHasKey($identifier, $blocks, $tag->value . ' contains an unknown block.');
            }
        }

        $items = $data->requiredItems();
        foreach (ItemTag::cases() as $tag) {
            $members = ItemTagRegistry::members($tag);
            self::assertNotSame([], $members, $tag->value . ' must not be empty.');
            foreach ($members as $identifier) {
                self::assertArrayHasKey($identifier, $items, $tag->value . ' contains an unknown item.');
            }
        }
    }

    public function testCompleteDatasetFamiliesCannotSilentlyDrift(): void
    {
        $data = AdmittedRegistryDataSet::bundled();
        $blockIdentifiers = [];
        foreach ($data->blockStateRegistry()->states() as $state) {
            $blockIdentifiers[$state->identifier()] = true;
        }
        $doors = array_keys(array_filter(
            $blockIdentifiers,
            static fn(string $identifier): bool => str_ends_with($identifier, '_door')
                && !str_ends_with($identifier, '_trapdoor'),
            ARRAY_FILTER_USE_KEY,
        ));
        sort($doors, SORT_STRING);
        $taggedDoors = BlockTagRegistry::members(BlockTag::DOOR);
        sort($taggedDoors, SORT_STRING);
        self::assertSame($doors, $taggedDoors);

        $spawnEggs = array_keys(array_filter(
            $data->requiredItems(),
            static fn(string $identifier): bool => str_ends_with($identifier, '_spawn_egg'),
            ARRAY_FILTER_USE_KEY,
        ));
        sort($spawnEggs, SORT_STRING);
        $taggedSpawnEggs = ItemTagRegistry::members(ItemTag::SPAWN_EGG);
        sort($taggedSpawnEggs, SORT_STRING);
        self::assertSame($spawnEggs, $taggedSpawnEggs);
    }

    public function testMalformedIdentifiersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BlockTagRegistry::contains(BlockTag::DOOR, 'oak_door');
    }
}
