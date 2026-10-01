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

use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Api\Entity\Vanilla\Slime;
use Bedriox\Api\Inventory\ItemStack;

final readonly class SlimeLootTable implements LootTable
{
    public function roll(LootContext $context, LootRandomSource $random): array
    {
        if (!$context->subject instanceof Slime || $context->subject->getSize() !== SlimeSize::SMALL) {
            return [];
        }
        $count = $random->nextInt(0, 2 + $context->lootingLevel);

        return $count === 0 ? [] : [new ItemStack('minecraft:slime_ball', $count)];
    }
}
