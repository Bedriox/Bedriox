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

namespace Bedriox\Server\Player;

use InvalidArgumentException;

/** One occupied slot in a session-independent player-owned inventory state. */
final readonly class PlayerInventoryEntry
{
    public function __construct(
        public int $slot,
        public PlayerInventoryStackState $stack,
    ) {
        if ($this->slot < 0 || $this->slot >= PlayerInventory::SLOT_COUNT) {
            throw new InvalidArgumentException('Inventory entry slot is outside the main inventory.');
        }
    }
}
