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

namespace Bedriox\Api\Entity\Value;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;

/** Bedrock suspicious-stew variant produced after a brown mooshroom consumes a flower. */
enum MooshroomStewEffect: int
{
    case POPPY = 0;
    case CORNFLOWER = 1;
    case TULIP = 2;
    case AZURE_BLUET = 3;
    case LILY_OF_THE_VALLEY = 4;
    case DANDELION = 5;
    case BLUE_ORCHID = 6;
    case ALLIUM = 7;
    case OXEYE_DAISY = 8;
    case WITHER_ROSE = 9;
    case TORCHFLOWER = 10;
    case OPEN_EYEBLOSSOM = 11;
    case CLOSED_EYEBLOSSOM = 12;

    public static function fromFlower(string $identifier): ?self
    {
        return match ($identifier) {
            'minecraft:poppy' => self::POPPY,
            'minecraft:cornflower' => self::CORNFLOWER,
            'minecraft:red_tulip', 'minecraft:orange_tulip',
            'minecraft:white_tulip', 'minecraft:pink_tulip' => self::TULIP,
            'minecraft:azure_bluet' => self::AZURE_BLUET,
            'minecraft:lily_of_the_valley' => self::LILY_OF_THE_VALLEY,
            'minecraft:dandelion' => self::DANDELION,
            'minecraft:blue_orchid' => self::BLUE_ORCHID,
            'minecraft:allium' => self::ALLIUM,
            'minecraft:oxeye_daisy' => self::OXEYE_DAISY,
            'minecraft:wither_rose' => self::WITHER_ROSE,
            'minecraft:torchflower' => self::TORCHFLOWER,
            'minecraft:open_eyeblossom' => self::OPEN_EYEBLOSSOM,
            'minecraft:closed_eyeblossom' => self::CLOSED_EYEBLOSSOM,
            default => null,
        };
    }

    /** @return list<EffectInstance> */
    public function consumptionEffects(): array
    {
        return match ($this) {
            self::POPPY, self::TORCHFLOWER => [new EffectInstance(EffectType::NIGHT_VISION, 80)],
            self::CORNFLOWER => [new EffectInstance(EffectType::JUMP_BOOST, 80)],
            self::TULIP => [new EffectInstance(EffectType::WEAKNESS, 140)],
            self::AZURE_BLUET, self::OPEN_EYEBLOSSOM => [new EffectInstance(EffectType::BLINDNESS, 120)],
            self::LILY_OF_THE_VALLEY => [new EffectInstance(EffectType::POISON, 200)],
            self::DANDELION, self::BLUE_ORCHID => [new EffectInstance(EffectType::SATURATION, 6)],
            self::ALLIUM => [new EffectInstance(EffectType::FIRE_RESISTANCE, 40)],
            self::OXEYE_DAISY => [new EffectInstance(EffectType::REGENERATION, 120)],
            self::WITHER_ROSE => [new EffectInstance(EffectType::WITHER, 120)],
            self::CLOSED_EYEBLOSSOM => [new EffectInstance(EffectType::NAUSEA, 140)],
        };
    }
}
