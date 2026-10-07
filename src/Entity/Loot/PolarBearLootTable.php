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

use Bedriox\Api\Entity\Capability\Ageable;
use Bedriox\Api\Inventory\ItemStack;

final readonly class PolarBearLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        if ($context->subject instanceof Ageable && $context->subject->isBaby()) {
            return [];
        }
        $count = $random->nextInt(0, min(64, 2 + $context->lootingLevel));
        if ($count === 0) {
            return [];
        }
        $salmon = $random->nextInt(1, 4) === 1;

        return [new ItemStack(match (true) {
            $salmon && $context->burning => 'minecraft:cooked_salmon',
            $salmon => 'minecraft:salmon',
            $context->burning => 'minecraft:cooked_cod',
            default => 'minecraft:cod',
        }, $count)];
    }
}
