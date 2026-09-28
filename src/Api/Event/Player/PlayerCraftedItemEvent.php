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

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Crafting\CraftingGrid;
use Bedriox\Api\Crafting\CraftingRecipe;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Immutable notification emitted after a complete authoritative craft commits. */
final class PlayerCraftedItemEvent extends Event implements PostEvent
{
    /** @var list<ItemStack> */
    public readonly array $consumedInputs;

    /** @var list<ItemStack> */
    public readonly array $outputs;

    /** @var list<ItemStack> */
    public readonly array $remainders;

    /** @var list<ItemStack> */
    public readonly array $overflow;

    /**
     * @param list<ItemStack> $consumedInputs
     * @param list<ItemStack> $outputs committed one-repetition outputs
     * @param list<ItemStack> $remainders committed one-repetition remainders
     * @param list<ItemStack> $overflow stacks emitted into the world by the complete transaction
     */
    public function __construct(
        public readonly Player $player,
        public readonly CraftingRecipe $recipe,
        public readonly CraftingGrid $grid,
        public readonly int $craftCount,
        array $consumedInputs,
        array $outputs,
        array $remainders = [],
        array $overflow = [],
    ) {
        if ($craftCount < 1 || $craftCount > 64) {
            throw new InvalidArgumentException('Craft count must be between one and 64.');
        }
        $this->consumedInputs = self::stacks($consumedInputs, 9, false, 'consumed input');
        $this->outputs = self::stacks($outputs, 4, false, 'craft output');
        $this->remainders = self::stacks($remainders, 4, true, 'craft remainder');
        $this->overflow = self::stacks($overflow, 16, true, 'craft overflow');
    }

    /**
     * @param array<array-key, mixed> $stacks
     * @return list<ItemStack>
     */
    private static function stacks(array $stacks, int $maximum, bool $emptyAllowed, string $label): array
    {
        if (!array_is_list($stacks) || (!$emptyAllowed && $stacks === []) || count($stacks) > $maximum) {
            throw new InvalidArgumentException(ucfirst($label) . ' stack count is outside its supported bounds.');
        }
        $validated = [];
        foreach ($stacks as $stack) {
            if (!$stack instanceof ItemStack) {
                throw new InvalidArgumentException(ucfirst($label) . ' contains an invalid stack.');
            }
            $validated[] = $stack;
        }

        return $validated;
    }
}
