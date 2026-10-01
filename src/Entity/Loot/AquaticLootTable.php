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
use InvalidArgumentException;

final readonly class AquaticLootTable implements LootTable
{
    public function __construct(
        private ?string $rawItem,
        private int $minimum,
        private int $maximum,
        private ?string $cookedItem = null,
    ) {
        if ($minimum < 0 || $maximum < $minimum || $maximum > 64) {
            throw new InvalidArgumentException('Aquatic loot range is invalid.');
        }
    }

    public function roll(LootContext $context, LootRandomSource $random): array
    {
        if ($this->rawItem === null) {
            return [];
        }
        $count = $random->nextInt($this->minimum, min(64, $this->maximum + $context->lootingLevel));
        if ($count === 0) {
            return [];
        }

        return [new ItemStack(
            $context->burning && $this->cookedItem !== null ? $this->cookedItem : $this->rawItem,
            $count,
        )];
    }
}
