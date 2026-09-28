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

use InvalidArgumentException;

/** Immutable description of one validated action in a larger atomic transaction. */
final readonly class InventoryTransactionAction
{
    public function __construct(
        public InventoryActionType $type,
        public string $inventoryIdentifier,
        public int $slot,
        public ?ItemStack $before,
        public ?ItemStack $after,
    ) {
        if (strlen($inventoryIdentifier) > 256
            || preg_match('/^[A-Za-z0-9_.:\/-]+$/D', $inventoryIdentifier) !== 1) {
            throw new InvalidArgumentException('Transaction inventory identifier is invalid.');
        }
        if ($slot < -1 || $slot > 255) {
            throw new InvalidArgumentException('Transaction action slot is outside its supported range.');
        }
        if ($slot === -1 && !in_array($type, [InventoryActionType::DROP, InventoryActionType::DESTROY], true)) {
            throw new InvalidArgumentException('Only out-of-inventory actions may omit a slot.');
        }
    }
}
