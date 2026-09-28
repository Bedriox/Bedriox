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

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\Player\PlayerInventoryStackState;
use InvalidArgumentException;

/** Canonical persisted item state without session-local stack-network identity. */
final readonly class ContainerItemStack
{
    public function __construct(
        public string $identifier,
        public int $count,
        public int $damage = 0,
        public ?ItemNbt $nbt = null,
        public int $auxValue = 0,
    ) {
        if (strlen($identifier) > 256 || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Container item identifier must be canonical and namespaced.');
        }
        if ($count < 1 || $count > 64) {
            throw new InvalidArgumentException('Container item count must be between 1 and 64.');
        }
        if ($damage < 0 || $damage > PlayerInventoryStackState::MAX_DAMAGE) {
            throw new InvalidArgumentException('Container item damage is outside its supported range.');
        }
        if ($auxValue < 0 || $auxValue > PlayerInventoryStackState::MAX_AUX_VALUE) {
            throw new InvalidArgumentException('Container item auxiliary value is outside its supported range.');
        }
    }
}
