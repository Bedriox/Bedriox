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

namespace Bedriox\Server\Tests\Gameplay\Enchanting;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Enchanting\EnchantmentApplicability;
use Bedriox\Server\Gameplay\Enchanting\EnchantmentDefinition;
use Bedriox\Server\Gameplay\Enchanting\EnchantmentItemCategory;
use Bedriox\Server\Gameplay\Enchanting\EnchantmentRegistry;
use Bedriox\Server\Gameplay\Enchanting\VanillaEnchantmentRegistry;
use Bedriox\Server\Gameplay\Enchanting\VanillaEnchantments;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VanillaEnchantmentRegistryTest extends TestCase
{
    public function testEveryCurrentVanillaDefinitionHasUniqueTypedIdentity(): void
    {
        $registry = VanillaEnchantmentRegistry::create();

        self::assertCount(42, $registry->all());
        self::assertSame(9, $registry->get(VanillaEnchantments::SHARPNESS)->bedrockId);
        self::assertSame(5, $registry->get(VanillaEnchantments::DENSITY)->maximumLevel);
        self::assertSame(4, $registry->get(VanillaEnchantments::BREACH)->maximumLevel);
        self::assertSame(VanillaEnchantments::LUNGE, $registry->getByBedrockId(41)->identifier);
    }

    public function testApplicabilityComesFromItemDefinitions(): void
    {
        $data = BedrockDataSet::bundled();
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $enchantments = VanillaEnchantmentRegistry::create();

        self::assertTrue(EnchantmentApplicability::accepts(
            $enchantments->get(VanillaEnchantments::SHARPNESS),
            $items->type('minecraft:iron_sword'),
        ));
        self::assertFalse(EnchantmentApplicability::accepts(
            $enchantments->get(VanillaEnchantments::SHARPNESS),
            $items->type('minecraft:iron_pickaxe'),
        ));
        self::assertTrue(EnchantmentApplicability::accepts(
            $enchantments->get(VanillaEnchantments::SHARPNESS),
            $items->type('minecraft:diamond_spear'),
        ));
        self::assertTrue(EnchantmentApplicability::accepts(
            $enchantments->get(VanillaEnchantments::LUNGE),
            $items->type('minecraft:diamond_spear'),
        ));
        self::assertTrue(EnchantmentApplicability::accepts(
            $enchantments->get(VanillaEnchantments::FLAME),
            $items->type('minecraft:bow'),
        ));
    }

    public function testRuntimeRegistrationIsImmediateAndBounded(): void
    {
        $registry = new EnchantmentRegistry();
        $definition = new EnchantmentDefinition(
            'example:momentum',
            1_000,
            2,
            5,
            [EnchantmentItemCategory::SWORD],
            1,
            10,
            20,
        );

        $registry->register($definition);
        self::assertSame($definition, $registry->get('example:momentum'));
        self::assertSame(1, $registry->revision());

        $this->expectException(InvalidArgumentException::class);
        $registry->register($definition);
    }
}
