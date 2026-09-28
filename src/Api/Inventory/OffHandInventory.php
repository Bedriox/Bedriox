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

namespace Bedriox\Api\Inventory;

/** Immutable off-hand snapshot with session-bound authoritative mutations. */
final readonly class OffHandInventory
{
    public function __construct(
        private ?ItemStack $item,
        private PlayerInventoryActions $actions,
    ) {}

    public function getItem(): ?ItemStack
    {
        return $this->item;
    }

    public function setItem(?ItemStack $stack): void
    {
        $this->actions->setOffHandItem($stack);
    }

    public function clear(): void
    {
        $this->setItem(null);
    }

    public function isEmpty(): bool
    {
        return $this->item === null;
    }
}
