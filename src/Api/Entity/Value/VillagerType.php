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

/** Biome appearance used by villagers and zombie villagers. */
enum VillagerType: int
{
    case PLAINS = 0;
    case DESERT = 1;
    case JUNGLE = 2;
    case SAVANNA = 3;
    case SNOW = 4;
    case SWAMP = 5;
    case TAIGA = 6;

    public static function fromName(string $name): self
    {
        foreach (self::cases() as $type) {
            if (strtolower($type->name) === strtolower($name)) {
                return $type;
            }
        }

        throw new \InvalidArgumentException('Unknown villager type.');
    }

    public static function fromVillageVariant(string $variant): self
    {
        return match ($variant) {
            'desert' => self::DESERT,
            'savanna' => self::SAVANNA,
            'snowy' => self::SNOW,
            'taiga' => self::TAIGA,
            default => self::PLAINS,
        };
    }
}
