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

namespace Bedriox\Server\World\BlockEntity;

use Bedriox\Server\Gameplay\Block\Interaction\ShelfInventory;
use Bedriox\Server\World\BlockPosition;

/** Durable three-compartment shelf contents. */
final readonly class ShelfBlockEntity extends BlockEntity
{
    public function __construct(BlockPosition $position, public ShelfInventory $inventory)
    {
        parent::__construct(BlockEntityType::Shelf, $position, $inventory->revision);
    }

    public static function empty(BlockPosition $position): self
    {
        return new self($position, new ShelfInventory());
    }

    public function withInventory(ShelfInventory $inventory): self
    {
        return $inventory === $this->inventory ? $this : new self($this->position, $inventory);
    }
}
