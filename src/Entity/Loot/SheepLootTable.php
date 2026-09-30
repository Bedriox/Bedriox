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

use Bedriox\Api\Entity\Vanilla\Sheep;
use Bedriox\Api\Inventory\ItemStack;

final readonly class SheepLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        $drops = [new ItemStack(
            $context->burning ? 'minecraft:cooked_mutton' : 'minecraft:mutton',
            $random->nextInt(1, 2 + $context->lootingLevel),
        )];
        if ($context->subject instanceof Sheep && !$context->subject->isSheared()) {
            $drops[] = new ItemStack($context->subject->getWoolColor()->woolIdentifier(), 1);
        }

        return $drops;
    }
}
