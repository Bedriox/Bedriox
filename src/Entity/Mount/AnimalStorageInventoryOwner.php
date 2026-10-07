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

namespace Bedriox\Server\Entity\Mount;

use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Inventory\SimpleContainerInventory;

/** @internal Authoritative entity-backed storage boundary. */
interface AnimalStorageInventoryOwner
{
    public function hasChest(): bool;

    public function getStorageSlotCount(): int;

    public function setChested(bool $chested): void;

    public function storageInventory(): SimpleContainerInventory;

    public function markStorageInventoryChanged(): void;

    /** @return list<ItemStack> @internal */
    public function drainStorageItems(): array;
}
