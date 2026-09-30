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

use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Gameplay\Enchanting\EnchantmentEffects;
use Bedriox\Server\Gameplay\Enchanting\VanillaEnchantments;
use Bedriox\Server\Simulation\DamageCause;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnchantmentEffectsTest extends TestCase
{
    public function testMeleeBonusesRespectTargetCategory(): void
    {
        $enchantments = [
            VanillaEnchantments::SHARPNESS => 3,
            VanillaEnchantments::SMITE => 2,
            VanillaEnchantments::BANE_OF_ARTHROPODS => 1,
        ];

        self::assertSame(2.0, EnchantmentEffects::meleeDamageBonus($enchantments, false, false));
        self::assertSame(7.0, EnchantmentEffects::meleeDamageBonus($enchantments, true, false));
        self::assertSame(4.5, EnchantmentEffects::meleeDamageBonus($enchantments, false, true));
    }

    public function testProtectionIsCauseSpecificAndCapped(): void
    {
        $armor = [[
            VanillaEnchantments::PROTECTION => 4,
            VanillaEnchantments::FIRE_PROTECTION => 4,
            VanillaEnchantments::FEATHER_FALLING => 4,
            VanillaEnchantments::PROJECTILE_PROTECTION => 4,
        ]];

        self::assertSame(5, EnchantmentEffects::protectionFactor($armor, DamageCause::Attack));
        self::assertSame(14, EnchantmentEffects::protectionFactor($armor, DamageCause::Fire));
        self::assertSame(20, EnchantmentEffects::protectionFactor($armor, DamageCause::Fall));
        self::assertSame(16, EnchantmentEffects::protectionFactor($armor, DamageCause::Projectile));
    }

    public function testUnbreakingAppliesIndependentWearTrials(): void
    {
        self::assertSame(2, EnchantmentEffects::durabilityDamage(
            4,
            2,
            false,
            new EnchantmentSequenceRandom([0, 1, 2, 0]),
        ));
        self::assertSame(2, EnchantmentEffects::durabilityDamage(
            3,
            3,
            true,
            new EnchantmentSequenceRandom([50, 75, 0, 90, 2]),
        ));
    }
}

/** @internal */
final class EnchantmentSequenceRandom implements DropRandom
{
    /** @param list<int> $values */
    public function __construct(private array $values) {}

    public function integer(int $minimum, int $maximum): int
    {
        $value = array_shift($this->values);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException('Enchantment random sequence is missing or outside the requested range.');
        }

        return $value;
    }
}
