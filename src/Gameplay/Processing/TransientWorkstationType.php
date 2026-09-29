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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Api\Inventory\ContainerType;

/** Workstations whose input and result slots exist only for the current open window. */
enum TransientWorkstationType: string
{
    case STONECUTTER = 'minecraft:stonecutter';
    case SMITHING_TABLE = 'minecraft:smithing_table';
    case ANVIL = 'minecraft:anvil';
    case CHIPPED_ANVIL = 'minecraft:chipped_anvil';
    case DAMAGED_ANVIL = 'minecraft:damaged_anvil';
    case GRINDSTONE = 'minecraft:grindstone';
    case ENCHANTING_TABLE = 'minecraft:enchanting_table';
    case LOOM = 'minecraft:loom';
    case CARTOGRAPHY_TABLE = 'minecraft:cartography_table';

    public static function fromBlockIdentifier(string $identifier): ?self
    {
        return $identifier === 'minecraft:stonecutter_block'
            ? self::STONECUTTER
            : self::tryFrom($identifier);
    }

    public function containerType(): ContainerType
    {
        return match ($this) {
            self::STONECUTTER => ContainerType::STONECUTTER,
            self::SMITHING_TABLE => ContainerType::SMITHING_TABLE,
            self::ANVIL, self::CHIPPED_ANVIL, self::DAMAGED_ANVIL => ContainerType::ANVIL,
            self::GRINDSTONE => ContainerType::GRINDSTONE,
            self::ENCHANTING_TABLE => ContainerType::ENCHANTING_TABLE,
            self::LOOM => ContainerType::LOOM,
            self::CARTOGRAPHY_TABLE => ContainerType::CARTOGRAPHY_TABLE,
        };
    }

    public function slotCount(): int
    {
        return $this->containerType()->slotCount()
            ?? throw new \LogicException('A transient workstation must have a fixed slot count.');
    }
}
