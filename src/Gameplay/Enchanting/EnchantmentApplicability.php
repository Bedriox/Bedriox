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

namespace Bedriox\Server\Gameplay\Enchanting;

use Bedriox\Server\Gameplay\Item\ArmorSlot;
use Bedriox\Server\Gameplay\Item\ItemType;
use Bedriox\Server\Gameplay\Item\ToolType;

final class EnchantmentApplicability
{
    public static function accepts(EnchantmentDefinition $enchantment, ItemType $item): bool
    {
        foreach ($enchantment->categories as $category) {
            if (self::hasCategory($item, $category)) {
                return true;
            }
        }
        return false;
    }

    public static function hasCategory(ItemType $item, EnchantmentItemCategory $category): bool
    {
        return match ($category) {
            EnchantmentItemCategory::ARMOR => $item->armor !== null,
            EnchantmentItemCategory::HELMET => $item->armor?->slot === ArmorSlot::Head,
            EnchantmentItemCategory::CHESTPLATE => $item->armor?->slot === ArmorSlot::Chest,
            EnchantmentItemCategory::LEGGINGS => $item->armor?->slot === ArmorSlot::Legs,
            EnchantmentItemCategory::BOOTS => $item->armor?->slot === ArmorSlot::Feet,
            EnchantmentItemCategory::SWORD => $item->tool?->type === ToolType::Sword,
            EnchantmentItemCategory::DIGGER => in_array($item->tool?->type, [
                ToolType::Pickaxe,
                ToolType::Axe,
                ToolType::Shovel,
                ToolType::Hoe,
                ToolType::Shears,
            ], true),
            EnchantmentItemCategory::BREAKABLE => $item->durability() !== null,
            EnchantmentItemCategory::BOW => $item->identifier === 'minecraft:bow',
            EnchantmentItemCategory::CROSSBOW => $item->identifier === 'minecraft:crossbow',
            EnchantmentItemCategory::TRIDENT => $item->identifier === 'minecraft:trident',
            EnchantmentItemCategory::FISHING_ROD => $item->identifier === 'minecraft:fishing_rod',
            EnchantmentItemCategory::MACE => $item->identifier === 'minecraft:mace',
            EnchantmentItemCategory::SPEAR => $item->identifier === 'minecraft:spear'
                || str_ends_with($item->identifier, '_spear'),
        };
    }

    private function __construct() {}
}
