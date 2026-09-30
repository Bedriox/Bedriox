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

namespace Bedriox\Api\Event\Block;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;

final class BlockBreakEvent extends CancellableEvent
{
    /** @var list<ItemStack> */
    private array $drops;

    /** @param list<ItemStack> $drops */
    public function __construct(
        public readonly Player $player,
        public readonly Block $block,
        array $drops = [],
    ) {
        $this->setDropsInternal($drops);
    }

    /** @return list<ItemStack> */
    public function getDrops(): array
    {
        return $this->drops;
    }

    /** @param list<ItemStack> $drops */
    public function setDrops(array $drops): void
    {
        $this->assertMutable();
        $this->setDropsInternal($drops);
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->drops];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_array($state[1])) {
            throw new \InvalidArgumentException('Invalid block break event state.');
        }
        parent::replaceState($state[0]);
        $this->setDropsInternal($state[1]);
    }

    /** @param array<mixed> $drops */
    private function setDropsInternal(array $drops): void
    {
        if (!array_is_list($drops) || count($drops) > 256) {
            throw new \InvalidArgumentException('Block drops must be a bounded list.');
        }
        foreach ($drops as $drop) {
            if (!$drop instanceof ItemStack) {
                throw new \InvalidArgumentException('Block drops must contain item stacks.');
            }
        }
        $this->drops = $drops;
    }
}
