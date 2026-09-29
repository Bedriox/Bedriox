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

namespace Bedriox\Api\Potion;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;

/** Current Bedrock potion variants. Backing values are stable item metadata, not effect IDs. */
enum PotionType: int
{
    case WATER = 0;
    case MUNDANE = 1;
    case LONG_MUNDANE = 2;
    case THICK = 3;
    case AWKWARD = 4;
    case NIGHT_VISION = 5;
    case LONG_NIGHT_VISION = 6;
    case INVISIBILITY = 7;
    case LONG_INVISIBILITY = 8;
    case LEAPING = 9;
    case LONG_LEAPING = 10;
    case STRONG_LEAPING = 11;
    case FIRE_RESISTANCE = 12;
    case LONG_FIRE_RESISTANCE = 13;
    case SWIFTNESS = 14;
    case LONG_SWIFTNESS = 15;
    case STRONG_SWIFTNESS = 16;
    case SLOWNESS = 17;
    case LONG_SLOWNESS = 18;
    case WATER_BREATHING = 19;
    case LONG_WATER_BREATHING = 20;
    case HEALING = 21;
    case STRONG_HEALING = 22;
    case HARMING = 23;
    case STRONG_HARMING = 24;
    case POISON = 25;
    case LONG_POISON = 26;
    case STRONG_POISON = 27;
    case REGENERATION = 28;
    case LONG_REGENERATION = 29;
    case STRONG_REGENERATION = 30;
    case STRENGTH = 31;
    case LONG_STRENGTH = 32;
    case STRONG_STRENGTH = 33;
    case WEAKNESS = 34;
    case LONG_WEAKNESS = 35;
    case WITHER = 36;
    case TURTLE_MASTER = 37;
    case LONG_TURTLE_MASTER = 38;
    case STRONG_TURTLE_MASTER = 39;
    case SLOW_FALLING = 40;
    case LONG_SLOW_FALLING = 41;
    case STRONG_SLOWNESS = 42;
    case WIND_CHARGED = 43;
    case WEAVING = 44;
    case OOZING = 45;
    case INFESTED = 46;

    /** @return list<EffectInstance> */
    public function effects(): array
    {
        return match ($this) {
            self::WATER, self::MUNDANE, self::LONG_MUNDANE, self::THICK, self::AWKWARD => [],
            self::NIGHT_VISION => [self::effect(EffectType::NIGHT_VISION, 3_600)],
            self::LONG_NIGHT_VISION => [self::effect(EffectType::NIGHT_VISION, 9_600)],
            self::INVISIBILITY => [self::effect(EffectType::INVISIBILITY, 3_600)],
            self::LONG_INVISIBILITY => [self::effect(EffectType::INVISIBILITY, 9_600)],
            self::LEAPING => [self::effect(EffectType::JUMP_BOOST, 3_600)],
            self::LONG_LEAPING => [self::effect(EffectType::JUMP_BOOST, 9_600)],
            self::STRONG_LEAPING => [self::effect(EffectType::JUMP_BOOST, 1_800, 1)],
            self::FIRE_RESISTANCE => [self::effect(EffectType::FIRE_RESISTANCE, 3_600)],
            self::LONG_FIRE_RESISTANCE => [self::effect(EffectType::FIRE_RESISTANCE, 9_600)],
            self::SWIFTNESS => [self::effect(EffectType::SPEED, 3_600)],
            self::LONG_SWIFTNESS => [self::effect(EffectType::SPEED, 9_600)],
            self::STRONG_SWIFTNESS => [self::effect(EffectType::SPEED, 1_800, 1)],
            self::SLOWNESS => [self::effect(EffectType::SLOWNESS, 1_800)],
            self::LONG_SLOWNESS => [self::effect(EffectType::SLOWNESS, 4_800)],
            self::STRONG_SLOWNESS => [self::effect(EffectType::SLOWNESS, 400, 3)],
            self::WATER_BREATHING => [self::effect(EffectType::WATER_BREATHING, 3_600)],
            self::LONG_WATER_BREATHING => [self::effect(EffectType::WATER_BREATHING, 9_600)],
            self::HEALING => [self::effect(EffectType::INSTANT_HEALTH, 1)],
            self::STRONG_HEALING => [self::effect(EffectType::INSTANT_HEALTH, 1, 1)],
            self::HARMING => [self::effect(EffectType::INSTANT_DAMAGE, 1)],
            self::STRONG_HARMING => [self::effect(EffectType::INSTANT_DAMAGE, 1, 1)],
            self::POISON => [self::effect(EffectType::POISON, 900)],
            self::LONG_POISON => [self::effect(EffectType::POISON, 2_400)],
            self::STRONG_POISON => [self::effect(EffectType::POISON, 440, 1)],
            self::REGENERATION => [self::effect(EffectType::REGENERATION, 900)],
            self::LONG_REGENERATION => [self::effect(EffectType::REGENERATION, 2_400)],
            self::STRONG_REGENERATION => [self::effect(EffectType::REGENERATION, 440, 1)],
            self::STRENGTH => [self::effect(EffectType::STRENGTH, 3_600)],
            self::LONG_STRENGTH => [self::effect(EffectType::STRENGTH, 9_600)],
            self::STRONG_STRENGTH => [self::effect(EffectType::STRENGTH, 1_800, 1)],
            self::WEAKNESS => [self::effect(EffectType::WEAKNESS, 1_800)],
            self::LONG_WEAKNESS => [self::effect(EffectType::WEAKNESS, 4_800)],
            self::WITHER => [self::effect(EffectType::WITHER, 800, 1)],
            self::TURTLE_MASTER => [
                self::effect(EffectType::SLOWNESS, 400, 3),
                self::effect(EffectType::RESISTANCE, 400, 2),
            ],
            self::LONG_TURTLE_MASTER => [
                self::effect(EffectType::SLOWNESS, 800, 3),
                self::effect(EffectType::RESISTANCE, 800, 2),
            ],
            self::STRONG_TURTLE_MASTER => [
                self::effect(EffectType::SLOWNESS, 400, 5),
                self::effect(EffectType::RESISTANCE, 400, 3),
            ],
            self::SLOW_FALLING => [self::effect(EffectType::SLOW_FALLING, 1_800)],
            self::LONG_SLOW_FALLING => [self::effect(EffectType::SLOW_FALLING, 4_800)],
            self::WIND_CHARGED => [self::effect(EffectType::WIND_CHARGED, 3_600)],
            self::WEAVING => [self::effect(EffectType::WEAVING, 3_600)],
            self::OOZING => [self::effect(EffectType::OOZING, 3_600)],
            self::INFESTED => [self::effect(EffectType::INFESTED, 3_600)],
        };
    }

    public function displayName(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->name)));
    }

    private static function effect(EffectType $type, int $durationTicks, int $amplifier = 0): EffectInstance
    {
        return new EffectInstance($type, $durationTicks, $amplifier);
    }
}
