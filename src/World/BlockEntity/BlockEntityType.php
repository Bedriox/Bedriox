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

/** Canonical server-side identities for the storage block entities currently admitted by Bedriox. */
enum BlockEntityType: string
{
    case Chest = 'minecraft:chest';
    case Barrel = 'minecraft:barrel';
    case ShulkerBox = 'minecraft:shulker_box';
    case EnderChest = 'minecraft:ender_chest';
    case BrewingStand = 'minecraft:brewing_stand';

    public function ownsPersistentInventory(): bool
    {
        return $this !== self::EnderChest;
    }

    public function isStorageContainer(): bool
    {
        return $this === self::Chest || $this === self::Barrel || $this === self::ShulkerBox;
    }
}
