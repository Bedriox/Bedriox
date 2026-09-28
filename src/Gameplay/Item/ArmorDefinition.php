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

use InvalidArgumentException;

/** Server-owned wearable properties independent of Bedrock runtime IDs. */
final readonly class ArmorDefinition
{
    public function __construct(
        public ArmorSlot $slot,
        public int $defensePoints,
        public int $maximumDurability,
        public float $knockbackResistance = 0.0,
    ) {
        if ($defensePoints < 0 || $defensePoints > 20) {
            throw new InvalidArgumentException('Armor defense points must be between zero and twenty.');
        }
        if ($maximumDurability < 1 || $maximumDurability > 65_535) {
            throw new InvalidArgumentException('Armor durability must be between one and 65535.');
        }
        if (!is_finite($knockbackResistance) || $knockbackResistance < 0.0 || $knockbackResistance > 1.0) {
            throw new InvalidArgumentException('Armor knockback resistance must be finite and between zero and one.');
        }
    }
}
