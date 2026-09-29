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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Potion\PotionType;

/** Computes the vanilla presentation colour for potion particles and clouds. */
final class PotionColorMixer
{
    public const int WATER_ARGB = 0xff385dc6;

    public static function forPotion(PotionType $type): int
    {
        return self::forEffects($type->effects());
    }

    /** @param list<EffectInstance> $effects */
    public static function forEffects(array $effects): int
    {
        $red = $green = $blue = $weight = 0;
        foreach ($effects as $effect) {
            if (!$effect->visible) {
                continue;
            }
            [$effectRed, $effectGreen, $effectBlue] = self::rgb($effect->type);
            $effectWeight = $effect->amplifier + 1;
            $red += $effectRed * $effectWeight;
            $green += $effectGreen * $effectWeight;
            $blue += $effectBlue * $effectWeight;
            $weight += $effectWeight;
        }
        if ($weight === 0) {
            return self::WATER_ARGB;
        }

        return 0xff000000
            | ((int) floor($red / $weight) << 16)
            | ((int) floor($green / $weight) << 8)
            | (int) floor($blue / $weight);
    }

    /** @return array{int, int, int} */
    private static function rgb(EffectType $type): array
    {
        return match ($type) {
            EffectType::ABSORPTION => [37, 82, 165],
            EffectType::BAD_OMEN => [11, 97, 56],
            EffectType::BLINDNESS => [31, 31, 35],
            EffectType::CONDUIT_POWER => [29, 194, 209],
            EffectType::DARKNESS => [41, 39, 33],
            EffectType::FATAL_POISON, EffectType::POISON => [78, 147, 49],
            EffectType::FIRE_RESISTANCE => [228, 154, 58],
            EffectType::HASTE => [217, 192, 67],
            EffectType::HEALTH_BOOST => [248, 125, 35],
            EffectType::HUNGER => [88, 118, 83],
            EffectType::INFESTED => [99, 117, 105],
            EffectType::INSTANT_DAMAGE => [67, 10, 9],
            EffectType::INSTANT_HEALTH, EffectType::SATURATION => [248, 36, 35],
            EffectType::INVISIBILITY => [127, 131, 146],
            EffectType::JUMP_BOOST => [34, 255, 76],
            EffectType::LEVITATION => [206, 255, 255],
            EffectType::MINING_FATIGUE => [74, 66, 23],
            EffectType::NAUSEA => [85, 29, 74],
            EffectType::NIGHT_VISION => [31, 31, 161],
            EffectType::OOZING => [100, 145, 99],
            EffectType::REGENERATION => [205, 92, 171],
            EffectType::RESISTANCE => [153, 69, 58],
            EffectType::SLOWNESS => [90, 108, 129],
            EffectType::SLOW_FALLING => [247, 248, 224],
            EffectType::SPEED => [124, 175, 198],
            EffectType::STRENGTH => [147, 36, 35],
            EffectType::TRIAL_OMEN => [22, 166, 166],
            EffectType::VILLAGE_HERO => [0, 0, 0],
            EffectType::WATER_BREATHING => [46, 82, 153],
            EffectType::WEAKNESS => [72, 77, 72],
            EffectType::WEAVING => [183, 195, 221],
            EffectType::WIND_CHARGED => [179, 205, 210],
            EffectType::WITHER => [53, 42, 39],
        };
    }
}
