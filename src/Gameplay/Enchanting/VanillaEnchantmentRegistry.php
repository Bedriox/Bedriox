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

use Bedriox\Server\Gameplay\Enchanting\EnchantmentItemCategory as Category;

/** One authoritative table for vanilla serialization, eligibility, costs, rarity, and conflicts. */
final class VanillaEnchantmentRegistry
{
    public static function create(): EnchantmentRegistry
    {
        $armorProtections = [
            VanillaEnchantments::PROTECTION,
            VanillaEnchantments::FIRE_PROTECTION,
            VanillaEnchantments::BLAST_PROTECTION,
            VanillaEnchantments::PROJECTILE_PROTECTION,
        ];
        $weaponDamage = [
            VanillaEnchantments::SHARPNESS,
            VanillaEnchantments::SMITE,
            VanillaEnchantments::BANE_OF_ARTHROPODS,
        ];
        $silkFortune = [VanillaEnchantments::SILK_TOUCH, VanillaEnchantments::FORTUNE];
        $crossbowProjectiles = [VanillaEnchantments::MULTISHOT, VanillaEnchantments::PIERCING];
        $tridentMovement = [VanillaEnchantments::RIPTIDE, VanillaEnchantments::LOYALTY, VanillaEnchantments::CHANNELING];
        $maceDamage = [VanillaEnchantments::DENSITY, VanillaEnchantments::BREACH];

        /** @var list<array{string, int, int, int, non-empty-list<Category>, int, int, int, bool, bool, bool, list<string>}> $rows */
        $rows = [
            [VanillaEnchantments::PROTECTION, 0, 4, 10, [Category::ARMOR], 1, 11, 11, false, false, true, self::others($armorProtections, VanillaEnchantments::PROTECTION)],
            [VanillaEnchantments::FIRE_PROTECTION, 1, 4, 5, [Category::ARMOR], 10, 8, 8, false, false, true, self::others($armorProtections, VanillaEnchantments::FIRE_PROTECTION)],
            [VanillaEnchantments::FEATHER_FALLING, 2, 4, 5, [Category::BOOTS], 5, 6, 6, false, false, true, []],
            [VanillaEnchantments::BLAST_PROTECTION, 3, 4, 2, [Category::ARMOR], 5, 8, 8, false, false, true, self::others($armorProtections, VanillaEnchantments::BLAST_PROTECTION)],
            [VanillaEnchantments::PROJECTILE_PROTECTION, 4, 4, 5, [Category::ARMOR], 3, 6, 6, false, false, true, self::others($armorProtections, VanillaEnchantments::PROJECTILE_PROTECTION)],
            [VanillaEnchantments::THORNS, 5, 3, 1, [Category::CHESTPLATE, Category::ARMOR], 10, 20, 50, false, false, true, []],
            [VanillaEnchantments::RESPIRATION, 6, 3, 2, [Category::HELMET], 10, 10, 30, false, false, true, []],
            [VanillaEnchantments::DEPTH_STRIDER, 7, 3, 2, [Category::BOOTS], 10, 10, 15, false, false, true, [VanillaEnchantments::FROST_WALKER]],
            [VanillaEnchantments::AQUA_AFFINITY, 8, 1, 2, [Category::HELMET], 1, 0, 40, false, false, true, []],
            [VanillaEnchantments::SHARPNESS, 9, 5, 10, [Category::SWORD, Category::SPEAR], 1, 11, 20, false, false, true, self::others($weaponDamage, VanillaEnchantments::SHARPNESS)],
            [VanillaEnchantments::SMITE, 10, 5, 5, [Category::SWORD, Category::SPEAR], 5, 8, 20, false, false, true, self::others($weaponDamage, VanillaEnchantments::SMITE)],
            [VanillaEnchantments::BANE_OF_ARTHROPODS, 11, 5, 5, [Category::SWORD, Category::SPEAR], 5, 8, 20, false, false, true, self::others($weaponDamage, VanillaEnchantments::BANE_OF_ARTHROPODS)],
            [VanillaEnchantments::KNOCKBACK, 12, 2, 5, [Category::SWORD, Category::SPEAR], 5, 20, 50, false, false, true, []],
            [VanillaEnchantments::FIRE_ASPECT, 13, 2, 2, [Category::SWORD, Category::SPEAR], 10, 20, 50, false, false, true, []],
            [VanillaEnchantments::LOOTING, 14, 3, 2, [Category::SWORD, Category::SPEAR], 15, 9, 50, false, false, true, []],
            [VanillaEnchantments::EFFICIENCY, 15, 5, 10, [Category::DIGGER], 1, 10, 50, false, false, true, []],
            [VanillaEnchantments::SILK_TOUCH, 16, 1, 1, [Category::DIGGER], 15, 0, 50, false, false, true, self::others($silkFortune, VanillaEnchantments::SILK_TOUCH)],
            [VanillaEnchantments::UNBREAKING, 17, 3, 5, [Category::BREAKABLE], 5, 8, 50, false, false, true, []],
            [VanillaEnchantments::FORTUNE, 18, 3, 2, [Category::DIGGER], 15, 9, 50, false, false, true, self::others($silkFortune, VanillaEnchantments::FORTUNE)],
            [VanillaEnchantments::POWER, 19, 5, 10, [Category::BOW], 1, 10, 15, false, false, true, []],
            [VanillaEnchantments::PUNCH, 20, 2, 2, [Category::BOW], 12, 20, 25, false, false, true, []],
            [VanillaEnchantments::FLAME, 21, 1, 2, [Category::BOW], 20, 0, 30, false, false, true, []],
            [VanillaEnchantments::INFINITY, 22, 1, 1, [Category::BOW], 20, 0, 30, false, false, true, [VanillaEnchantments::MENDING]],
            [VanillaEnchantments::LUCK_OF_THE_SEA, 23, 3, 2, [Category::FISHING_ROD], 15, 9, 50, false, false, true, []],
            [VanillaEnchantments::LURE, 24, 3, 2, [Category::FISHING_ROD], 15, 9, 50, false, false, true, []],
            [VanillaEnchantments::FROST_WALKER, 25, 2, 2, [Category::BOOTS], 10, 10, 15, true, false, false, [VanillaEnchantments::DEPTH_STRIDER]],
            [VanillaEnchantments::MENDING, 26, 1, 2, [Category::BREAKABLE], 25, 0, 50, true, false, false, [VanillaEnchantments::INFINITY]],
            [VanillaEnchantments::BINDING, 27, 1, 1, [Category::ARMOR], 25, 0, 50, true, true, false, []],
            [VanillaEnchantments::VANISHING, 28, 1, 1, [Category::BREAKABLE], 25, 0, 50, true, true, false, []],
            [VanillaEnchantments::IMPALING, 29, 5, 2, [Category::TRIDENT], 1, 8, 20, false, false, true, []],
            [VanillaEnchantments::RIPTIDE, 30, 3, 2, [Category::TRIDENT], 17, 7, 50, false, false, true, self::others($tridentMovement, VanillaEnchantments::RIPTIDE)],
            [VanillaEnchantments::LOYALTY, 31, 3, 5, [Category::TRIDENT], 12, 7, 50, false, false, true, [VanillaEnchantments::RIPTIDE]],
            [VanillaEnchantments::CHANNELING, 32, 1, 1, [Category::TRIDENT], 25, 0, 50, false, false, true, [VanillaEnchantments::RIPTIDE]],
            [VanillaEnchantments::MULTISHOT, 33, 1, 2, [Category::CROSSBOW], 20, 0, 50, false, false, true, self::others($crossbowProjectiles, VanillaEnchantments::MULTISHOT)],
            [VanillaEnchantments::PIERCING, 34, 4, 10, [Category::CROSSBOW], 1, 10, 50, false, false, true, self::others($crossbowProjectiles, VanillaEnchantments::PIERCING)],
            [VanillaEnchantments::QUICK_CHARGE, 35, 3, 5, [Category::CROSSBOW], 12, 20, 50, false, false, true, []],
            [VanillaEnchantments::SOUL_SPEED, 36, 3, 1, [Category::BOOTS], 10, 10, 15, true, false, false, []],
            [VanillaEnchantments::SWIFT_SNEAK, 37, 3, 1, [Category::LEGGINGS], 25, 25, 50, true, false, false, []],
            [VanillaEnchantments::WIND_BURST, 38, 3, 1, [Category::MACE], 15, 9, 50, true, false, false, []],
            [VanillaEnchantments::DENSITY, 39, 5, 5, [Category::MACE], 5, 8, 20, false, false, true, self::others($maceDamage, VanillaEnchantments::DENSITY)],
            [VanillaEnchantments::BREACH, 40, 4, 2, [Category::MACE], 15, 9, 50, false, false, true, self::others($maceDamage, VanillaEnchantments::BREACH)],
            [VanillaEnchantments::LUNGE, 41, 3, 2, [Category::SPEAR], 10, 10, 30, false, false, true, []],
        ];

        return new EnchantmentRegistry(array_map(
            static fn(array $row): EnchantmentDefinition => new EnchantmentDefinition(...$row),
            $rows,
        ));
    }

    /**
     * @param list<string> $group
     * @return list<string>
     */
    private static function others(array $group, string $identifier): array
    {
        return array_values(array_filter($group, static fn(string $entry): bool => $entry !== $identifier));
    }

    private function __construct() {}
}
