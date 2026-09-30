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

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Gameplay\Processing\WorkstationItemData;
use Bedriox\Server\Simulation\DamageCause;
use InvalidArgumentException;

/** Pure vanilla enchantment calculations shared by authoritative gameplay paths. */
final class EnchantmentEffects
{
    public const int FIRE_ASPECT_TICKS_PER_LEVEL = 80;
    public const float KNOCKBACK_HORIZONTAL_BONUS_PER_LEVEL = 0.4;
    public const float PUNCH_HORIZONTAL_BONUS_PER_LEVEL = 0.6;

    public static function level(?ItemNbt $nbt, string $identifier): int
    {
        return WorkstationItemData::enchantments($nbt)[$identifier] ?? 0;
    }

    /** @param array<string, int> $enchantments */
    public static function meleeDamageBonus(array $enchantments, bool $undead, bool $arthropod): float
    {
        $sharpness = $enchantments[VanillaEnchantments::SHARPNESS] ?? 0;
        $smite = $undead ? ($enchantments[VanillaEnchantments::SMITE] ?? 0) : 0;
        $bane = $arthropod ? ($enchantments[VanillaEnchantments::BANE_OF_ARTHROPODS] ?? 0) : 0;

        return ($sharpness > 0 ? 0.5 * ($sharpness + 1) : 0.0)
            + (2.5 * $smite)
            + (2.5 * $bane);
    }

    /** @param list<array<string, int>> $armorEnchantments */
    public static function protectionFactor(array $armorEnchantments, DamageCause $cause): int
    {
        $factor = 0;
        foreach ($armorEnchantments as $enchantments) {
            $factor += self::protectionContribution($enchantments[VanillaEnchantments::PROTECTION] ?? 0, 0.75);
            if ($cause === DamageCause::Fire) {
                $factor += self::protectionContribution($enchantments[VanillaEnchantments::FIRE_PROTECTION] ?? 0, 1.25);
            } elseif ($cause === DamageCause::Fall) {
                $factor += self::protectionContribution($enchantments[VanillaEnchantments::FEATHER_FALLING] ?? 0, 2.5);
            } elseif ($cause === DamageCause::Projectile) {
                $factor += self::protectionContribution($enchantments[VanillaEnchantments::PROJECTILE_PROTECTION] ?? 0, 1.5);
            }
        }

        return min(20, $factor);
    }

    public static function durabilityDamage(
        int $amount,
        int $unbreakingLevel,
        bool $armor,
        DropRandom $random,
    ): int {
        if ($amount < 0 || $amount > 65_535 || $unbreakingLevel < 0 || $unbreakingLevel > 255) {
            throw new InvalidArgumentException('Durability calculation input is outside its supported range.');
        }
        if ($amount === 0 || $unbreakingLevel === 0) {
            return $amount;
        }
        $applied = 0;
        for ($index = 0; $index < $amount; ++$index) {
            if ($armor && $random->integer(1, 100) <= 60) {
                ++$applied;
                continue;
            }
            if ($random->integer(0, $unbreakingLevel) === 0) {
                ++$applied;
            }
        }

        return $applied;
    }

    private static function protectionContribution(int $level, float $modifier): int
    {
        return $level <= 0 ? 0 : (int) floor((6 + ($level ** 2)) * $modifier / 3.0);
    }

    private function __construct() {}
}
