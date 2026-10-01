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

use Bedriox\Api\Entity\Capability\Breedable;
use Bedriox\Api\Inventory\ItemStack;

final readonly class CowLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        if ($context->subject instanceof Breedable && $context->subject->isBaby()) {
            return [];
        }
        $drops = [];
        $leather = $random->nextInt(0, 2 + $context->lootingLevel);
        if ($leather > 0) {
            $drops[] = new ItemStack('minecraft:leather', $leather);
        }
        $drops[] = new ItemStack(
            $context->burning ? 'minecraft:cooked_beef' : 'minecraft:beef',
            $random->nextInt(1, 3 + $context->lootingLevel),
        );

        return $drops;
    }
}
