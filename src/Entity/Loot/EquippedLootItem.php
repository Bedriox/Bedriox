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

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

final readonly class EquippedLootItem
{
    public function __construct(
        public EquipmentSlot $slot,
        public ItemStack $item,
        public float $dropChance,
    ) {
        if (!is_finite($dropChance) || $dropChance < 0.0 || $dropChance > 1.0) {
            throw new InvalidArgumentException('Equipment drop chance must be between zero and one.');
        }
    }
}
