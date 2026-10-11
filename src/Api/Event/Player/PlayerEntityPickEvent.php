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

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable creative entity-pick intent emitted before authoritative inventory mutation. */
final class PlayerEntityPickEvent extends CancellableEvent
{
    private ItemStack $item;

    public function __construct(
        public readonly Player $player,
        public readonly Entity $entity,
        ItemStack $item,
        public readonly int $hotbarSlot,
        public readonly bool $userDataRequested,
    ) {
        self::validateHotbarSlot($hotbarSlot);
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
            throw new InvalidArgumentException('Invalid player entity-pick event state.');
        }
        parent::replaceState($state[0]);
        $this->item = $state[1];
    }

    private static function validateHotbarSlot(int $hotbarSlot): void
    {
        if ($hotbarSlot < 0 || $hotbarSlot > 8) {
            throw new InvalidArgumentException('Entity-pick hotbar slot must be between zero and eight.');
        }
    }
}
