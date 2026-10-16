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

namespace Bedriox\Server\Tests\Gameplay\Trading;

use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Gameplay\Trading\VillagerTradeNetworkNbt;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use PHPUnit\Framework\TestCase;

final class VillagerTradeNetworkNbtTest extends TestCase
{
    public function testOffersEncodeAsCurrentBedrockLittleEndianNbt(): void
    {
        $payload = VillagerTradeNetworkNbt::encode(new VillagerTradeList([
            new VillagerTradeOffer(
                'bedriox:test',
                VillagerLevel::NOVICE,
                new ItemStack('minecraft:emerald', 2),
                null,
                new ItemStack('minecraft:bread', 3),
            ),
        ], 4));

        $root = (new LittleEndianNbtCodec())->decodeRootCompounds($payload, 1)[0];
        self::assertSame(LittleEndianNbtTag::LIST, $root['Recipes']->type);
        self::assertIsArray($root['Recipes']->value);
        self::assertCount(1, $root['Recipes']->value);
        self::assertSame(LittleEndianNbtTag::LIST, $root['TierExpRequirements']->type);
        self::assertIsArray($root['TierExpRequirements']->value);
        self::assertCount(5, $root['TierExpRequirements']->value);
        $firstTier = $root['TierExpRequirements']->value[0];
        $lastTier = $root['TierExpRequirements']->value[4];
        self::assertInstanceOf(LittleEndianNbtTag::class, $firstTier);
        self::assertInstanceOf(LittleEndianNbtTag::class, $lastTier);
        self::assertIsArray($firstTier->value);
        self::assertIsArray($lastTier->value);
        self::assertArrayHasKey(0, $firstTier->value);
        self::assertArrayHasKey(4, $lastTier->value);
    }

    public function testOnlyUnlockedTradeTiersArePublishedWithoutRenumberingRecipes(): void
    {
        $payload = VillagerTradeNetworkNbt::encode(new VillagerTradeList([
            new VillagerTradeOffer(
                'bedriox:novice',
                VillagerLevel::NOVICE,
                new ItemStack('minecraft:emerald', 4),
                null,
                new ItemStack('minecraft:iron_boots', 1),
            ),
            new VillagerTradeOffer(
                'bedriox:master',
                VillagerLevel::MASTER,
                new ItemStack('minecraft:emerald', 21),
                null,
                new ItemStack('minecraft:diamond_chestplate', 1),
            ),
        ], 1), VillagerLevel::NOVICE);

        $root = (new LittleEndianNbtCodec())->decodeRootCompounds($payload, 1)[0];
        self::assertIsArray($root['Recipes']->value);
        self::assertCount(1, $root['Recipes']->value);
        $recipe = $root['Recipes']->value[0];
        self::assertInstanceOf(LittleEndianNbtTag::class, $recipe);
        self::assertIsArray($recipe->value);
        $networkId = $recipe->value['netId'];
        self::assertInstanceOf(LittleEndianNbtTag::class, $networkId);
        self::assertSame(1, $networkId->value);
    }
}
