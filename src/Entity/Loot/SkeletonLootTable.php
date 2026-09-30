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

final readonly class SkeletonLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        $drops = [];
        $arrows = $random->nextInt(0, 2 + $context->lootingLevel);
        $bones = $random->nextInt(0, 2 + $context->lootingLevel);
        if ($arrows > 0) {
            $drops[] = new ItemStack('minecraft:arrow', $arrows);
        }
        if ($bones > 0) {
            $drops[] = new ItemStack('minecraft:bone', $bones);
        }

        return $drops;
    }
}
