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

final readonly class RabbitLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        if ($context->subject instanceof Breedable && $context->subject->isBaby()) {
            return [];
        }
        $drops = [];
        $hide = $random->nextInt(0, 1 + $context->lootingLevel);
        if ($hide > 0) {
            $drops[] = new ItemStack('minecraft:rabbit_hide', $hide);
        }
        $meat = $random->nextInt(0, 1 + $context->lootingLevel);
        if ($meat > 0) {
            $drops[] = new ItemStack($context->burning ? 'minecraft:cooked_rabbit' : 'minecraft:rabbit', $meat);
        }
        if ($random->nextInt(1, max(1, 100 - (10 * $context->lootingLevel))) === 1) {
            $drops[] = new ItemStack('minecraft:rabbit_foot', 1);
        }
        return $drops;
    }
}
