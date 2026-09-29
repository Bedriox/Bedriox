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

/** Stable storage-container identities exposed to plugins. */
enum ContainerType: string
{
    /** Plugin-owned inventory with no backing block or implicit persistence. */
    case VIRTUAL = 'virtual';
    case CHEST = 'chest';
    case DOUBLE_CHEST = 'double_chest';
    case TRAPPED_CHEST = 'trapped_chest';
    case DOUBLE_TRAPPED_CHEST = 'double_trapped_chest';
    case BARREL = 'barrel';
    case SHULKER_BOX = 'shulker_box';
    case ENDER_CHEST = 'ender_chest';
    case BREWING_STAND = 'brewing_stand';
    case FURNACE = 'furnace';
    case BLAST_FURNACE = 'blast_furnace';
    case SMOKER = 'smoker';
    case STONECUTTER = 'stonecutter';
    case SMITHING_TABLE = 'smithing_table';
    case ANVIL = 'anvil';
    case GRINDSTONE = 'grindstone';
    case ENCHANTING_TABLE = 'enchanting_table';
    case LOOM = 'loom';
    case CARTOGRAPHY_TABLE = 'cartography_table';

    public function slotCount(): ?int
    {
        return match ($this) {
            self::VIRTUAL => null,
            self::DOUBLE_CHEST, self::DOUBLE_TRAPPED_CHEST => 54,
            self::CHEST, self::TRAPPED_CHEST, self::BARREL, self::SHULKER_BOX, self::ENDER_CHEST => 27,
            self::BREWING_STAND => 5,
            self::FURNACE, self::BLAST_FURNACE, self::SMOKER,
            self::ANVIL, self::GRINDSTONE, self::CARTOGRAPHY_TABLE => 3,
            self::STONECUTTER, self::ENCHANTING_TABLE => 2,
            self::SMITHING_TABLE, self::LOOM => 4,
        };
    }

    public function isPaired(): bool
    {
        return $this === self::DOUBLE_CHEST || $this === self::DOUBLE_TRAPPED_CHEST;
    }

    public function isProcessingStation(): bool
    {
        return match ($this) {
            self::BREWING_STAND, self::FURNACE, self::BLAST_FURNACE, self::SMOKER,
            self::STONECUTTER, self::SMITHING_TABLE, self::ANVIL, self::GRINDSTONE,
            self::ENCHANTING_TABLE, self::LOOM, self::CARTOGRAPHY_TABLE => true,
            default => false,
        };
    }

    public function resultSlot(): ?int
    {
        return match ($this) {
            self::FURNACE, self::BLAST_FURNACE, self::SMOKER,
            self::ANVIL, self::GRINDSTONE, self::CARTOGRAPHY_TABLE => 2,
            self::STONECUTTER => 1,
            self::SMITHING_TABLE, self::LOOM => 3,
            default => null,
        };
    }

    public function isTransient(): bool
    {
        return in_array($this, [
            self::STONECUTTER,
            self::SMITHING_TABLE,
            self::ANVIL,
            self::GRINDSTONE,
            self::ENCHANTING_TABLE,
            self::LOOM,
            self::CARTOGRAPHY_TABLE,
        ], true);
    }
}
