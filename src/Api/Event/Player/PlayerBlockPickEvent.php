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

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;

final class PlayerBlockPickEvent extends CancellableEvent
{
    private ItemStack $item;

    public function __construct(
        public readonly Player $player,
        public readonly Block $block,
        ItemStack $item,
        public readonly bool $userDataRequested,
    ) {
        $this->item = $item;
    }

    public function item(): ItemStack
    {
        return $this->item;
    }

    public function setItem(ItemStack $item): void
    {
        $this->assertMutable();
        $this->item = $item;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->item];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof ItemStack) {
            throw new \InvalidArgumentException('Invalid player block-pick event state.');
        }
        parent::replaceState($state[0]);
        $this->item = $state[1];
    }
}
