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

final readonly class LootOutputNormalizer
{
    public const int MAX_DROP_STACKS = 64;
    public const int MAX_DROP_ITEMS = 4_096;

    public function __construct(private LootItemRegistry $items) {}

    /**
     * @param iterable<mixed> $items
     * @return list<ItemStack>
     */
    public function normalize(iterable $items): array
    {
        $result = [];
        $total = 0;
        foreach ($items as $item) {
            if (!$item instanceof ItemStack || $total >= self::MAX_DROP_ITEMS) {
                continue;
            }
            $maximumStackSize = $this->items->maximumStackSize($item->identifier);
            if ($maximumStackSize === null || $maximumStackSize < 1 || $maximumStackSize > 64) {
                continue;
            }

            $remaining = min($item->count, self::MAX_DROP_ITEMS - $total);
            foreach ($result as $index => $existing) {
                if ($remaining === 0) {
                    break;
                }
                if (!self::sameState($existing, $item) || $existing->count >= $maximumStackSize) {
                    continue;
                }
                $added = min($remaining, $maximumStackSize - $existing->count);
                $result[$index] = self::withCount($existing, $existing->count + $added);
                $remaining -= $added;
                $total += $added;
            }

            while ($remaining > 0 && count($result) < self::MAX_DROP_STACKS) {
                $count = min($remaining, $maximumStackSize);
                $result[] = self::withCount($item, $count);
                $remaining -= $count;
                $total += $count;
            }
            if (count($result) >= self::MAX_DROP_STACKS && $remaining > 0) {
                break;
            }
        }

        return $result;
    }

    private static function sameState(ItemStack $left, ItemStack $right): bool
    {
        return $left->identifier === $right->identifier
            && $left->damage === $right->damage
            && $left->auxValue === $right->auxValue
            && ($left->nbt === null
                ? $right->nbt === null
                : $right->nbt !== null && $left->nbt->equals($right->nbt));
    }

    private static function withCount(ItemStack $item, int $count): ItemStack
    {
        return new ItemStack($item->identifier, $count, $item->damage, $item->nbt, $item->auxValue);
    }
}
