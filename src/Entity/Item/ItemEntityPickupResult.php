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

namespace Bedriox\Server\Entity\Item;

use Bedriox\Server\Player\InventoryStack;

/** Authoritative result after inventory capacity has selected an accepted item count. */
final readonly class ItemEntityPickupResult
{
    public function __construct(
        public int $uniqueEntityId,
        public int $runtimeEntityId,
        public InventoryStack $pickedUp,
        public ?InventoryStack $remaining,
    ) {}

    public function removed(): bool
    {
        return $this->remaining === null;
    }
}
