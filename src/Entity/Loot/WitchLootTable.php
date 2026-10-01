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

use Bedriox\Api\Inventory\ItemStack;

final readonly class WitchLootTable implements LootTable
{
    private const array ITEMS = [
        'minecraft:glass_bottle',
        'minecraft:glowstone_dust',
        'minecraft:gunpowder',
        'minecraft:redstone',
        'minecraft:spider_eye',
        'minecraft:stick',
        'minecraft:sugar',
    ];

    public function roll(LootContext $context, LootRandomSource $random): array
    {
        $drops = [];
        $rolls = $random->nextInt(1, 3);
        for ($roll = 0; $roll < $rolls; ++$roll) {
            $identifier = self::ITEMS[$random->nextInt(0, count(self::ITEMS) - 1)];
            $count = $random->nextInt(0, 2 + $context->lootingLevel);
            if ($count > 0) {
                $drops[] = new ItemStack($identifier, $count);
            }
        }

        return $drops;
    }
}
