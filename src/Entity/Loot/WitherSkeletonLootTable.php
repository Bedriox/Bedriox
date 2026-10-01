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
use Bedriox\Api\Player\Player;

final readonly class WitherSkeletonLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        $drops = [];
        $coal = $random->nextInt(0, min(64, 1 + $context->lootingLevel));
        $bones = $random->nextInt(0, min(64, 2 + $context->lootingLevel));
        if ($coal > 0) {
            $drops[] = new ItemStack('minecraft:coal', $coal);
        }
        if ($bones > 0) {
            $drops[] = new ItemStack('minecraft:bone', $bones);
        }
        if ($context->killer instanceof Player && $random->nextInt(0, 999) < 25 + (20 * $context->lootingLevel)) {
            $drops[] = new ItemStack('minecraft:wither_skeleton_skull', 1);
        }

        return $drops;
    }
}
