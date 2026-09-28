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

/** Data-only item-use behavior registered for an admitted canonical item. */
final readonly class ItemBehaviorDefinition
{
    public function __construct(
        public ?ItemUseKind $kind = null,
        public int $useDurationTicks = 0,
        public ?ConsumableDefinition $consumable = null,
        public ?ArmorDefinition $armor = null,
        public bool $allowedInOffhand = false,
        public int $cooldownTicks = 0,
    ) {
        if ($useDurationTicks < 0 || $useDurationTicks > 1_200) {
            throw new InvalidArgumentException('Item use duration must be between 0 and 1200 ticks.');
        }
        if ($kind !== ItemUseKind::CONSUME && $useDurationTicks !== 0) {
            throw new InvalidArgumentException('Only consumable behavior may declare a use duration.');
        }
        if ($cooldownTicks < 0 || $cooldownTicks > 72_000) {
            throw new InvalidArgumentException('Item cooldown must be between 0 and 72000 ticks.');
        }
        if ($kind === null && $cooldownTicks !== 0) {
            throw new InvalidArgumentException('Items without a use action cannot declare a cooldown.');
        }
        if ($kind === ItemUseKind::CONSUME && ($consumable === null || $useDurationTicks < 1)) {
            throw new InvalidArgumentException('Consumable behavior requires nutrition data and a positive use duration.');
        }
        if ($kind !== ItemUseKind::CONSUME && $consumable !== null) {
            throw new InvalidArgumentException('Only consumable behavior may include nutrition data.');
        }
        if ($kind === ItemUseKind::EQUIP && $armor === null) {
            throw new InvalidArgumentException('Equip behavior requires an armor definition.');
        }
        if ($kind === null && $armor === null && !$allowedInOffhand) {
            throw new InvalidArgumentException('Item behavior must declare at least one supported capability.');
        }
    }
}
