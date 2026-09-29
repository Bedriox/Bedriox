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

namespace Bedriox\Server\Gameplay\Processing;

/** Named vanilla gameplay rules that are not part of the admitted recipe artifact. */
final class FurnaceGameplayRules
{
    /** @var array<string, int> */
    private const array FUEL_TICKS = [
        'minecraft:lava_bucket' => 20_000,
        'minecraft:coal_block' => 16_000,
        'minecraft:dried_kelp_block' => 4_000,
        'minecraft:blaze_rod' => 2_400,
        'minecraft:coal' => 1_600,
        'minecraft:charcoal' => 1_600,
        'minecraft:stick' => 100,
        'minecraft:bamboo' => 50,
    ];

    /** @var array<string, float> */
    private const array EXPERIENCE = [
        'minecraft:ancient_debris' => 2.0,
        'minecraft:raw_gold' => 1.0,
        'minecraft:gold_ore' => 1.0,
        'minecraft:deepslate_gold_ore' => 1.0,
        'minecraft:raw_iron' => 0.7,
        'minecraft:iron_ore' => 0.7,
        'minecraft:deepslate_iron_ore' => 0.7,
        'minecraft:raw_copper' => 0.7,
        'minecraft:copper_ore' => 0.7,
        'minecraft:deepslate_copper_ore' => 0.7,
        'minecraft:potato' => 0.35,
        'minecraft:beef' => 0.35,
        'minecraft:porkchop' => 0.35,
        'minecraft:chicken' => 0.35,
        'minecraft:mutton' => 0.35,
        'minecraft:rabbit' => 0.35,
    ];

    public static function fuelTicks(string $identifier): ?int
    {
        $exact = self::FUEL_TICKS[$identifier] ?? null;
        if ($exact !== null) {
            return $exact;
        }
        if (str_ends_with($identifier, '_planks')) {
            return 300;
        }
        if (str_ends_with($identifier, '_log') || str_ends_with($identifier, '_wood')
            || str_ends_with($identifier, '_stem') || str_ends_with($identifier, '_hyphae')) {
            return 300;
        }
        return null;
    }

    public static function experienceFor(string $inputIdentifier): float
    {
        return self::EXPERIENCE[$inputIdentifier] ?? 0.1;
    }

    public static function fuelResidue(string $identifier): ?string
    {
        return $identifier === 'minecraft:lava_bucket' ? 'minecraft:bucket' : null;
    }
}
