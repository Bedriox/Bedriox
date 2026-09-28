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

namespace Bedriox\Server\Gameplay\Item;

/** Vanilla tier properties used by authoritative durability and mining rules. */
enum ToolTier
{
    case Wood;
    case Gold;
    case Stone;
    case Copper;
    case Iron;
    case Diamond;
    case Netherite;

    public function harvestLevel(): int
    {
        return match ($this) {
            self::Wood => 1,
            self::Gold => 2,
            self::Stone, self::Copper => 3,
            self::Iron => 4,
            self::Diamond => 5,
            self::Netherite => 6,
        };
    }

    public function durability(): int
    {
        return match ($this) {
            self::Wood => 60,
            self::Gold => 33,
            self::Stone => 132,
            self::Copper => 191,
            self::Iron => 251,
            self::Diamond => 1_562,
            self::Netherite => 2_032,
        };
    }

    public function miningEfficiency(): float
    {
        return match ($this) {
            self::Wood => 2.0,
            self::Gold => 12.0,
            self::Stone => 4.0,
            self::Copper => 5.0,
            self::Iron => 6.0,
            self::Diamond => 8.0,
            self::Netherite => 9.0,
        };
    }

    /** Vanilla melee damage dealt by this tier's sword before enchantments. */
    public function baseAttackDamage(): float
    {
        return match ($this) {
            self::Wood, self::Gold => 5.0,
            self::Stone, self::Copper => 6.0,
            self::Iron => 7.0,
            self::Diamond => 8.0,
            self::Netherite => 9.0,
        };
    }
}
