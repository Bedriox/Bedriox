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

final readonly class ChickenLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        if ($context->subject instanceof Breedable && $context->subject->isBaby()) {
            return [];
        }
        $drops = [new ItemStack($context->burning ? 'minecraft:cooked_chicken' : 'minecraft:chicken', 1)];
        $feathers = $random->nextInt(0, 2 + $context->lootingLevel);
        if ($feathers > 0) {
            $drops[] = new ItemStack('minecraft:feather', $feathers);
        }
        return $drops;
    }
}
