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

final readonly class ParchedLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        $drops = (new SkeletonLootTable())->roll($context, $random);
        if ($context->killer instanceof Player) {
            $weaknessArrows = $random->nextInt(0, min(64, 2 + $context->lootingLevel));
            if ($weaknessArrows > 0) {
                $drops[] = new ItemStack('minecraft:arrow', $weaknessArrows, auxValue: 35);
            }
        }

        return $drops;
    }
}
