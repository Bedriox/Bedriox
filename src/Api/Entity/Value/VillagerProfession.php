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

/** Stable villager professions and their current Bedrock actor variants. */
enum VillagerProfession: int
{
    case UNEMPLOYED = 0;
    case FARMER = 1;
    case FISHERMAN = 2;
    case SHEPHERD = 3;
    case FLETCHER = 4;
    case LIBRARIAN = 5;
    case CARTOGRAPHER = 6;
    case CLERIC = 7;
    case ARMORER = 8;
    case WEAPONSMITH = 9;
    case TOOLSMITH = 10;
    case BUTCHER = 11;
    case LEATHERWORKER = 12;
    case MASON = 13;
    case NITWIT = 14;

    public static function fromName(string $name): self
    {
        foreach (self::cases() as $profession) {
            if (strtolower($profession->name) === strtolower($name)) {
                return $profession;
            }
        }

        throw new \InvalidArgumentException('Unknown villager profession.');
    }

    public function canTrade(): bool
    {
        return $this !== self::UNEMPLOYED && $this !== self::NITWIT;
    }
}
