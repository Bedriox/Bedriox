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

namespace Bedriox\Api\Entity;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;

/** Authoritative equipment belonging to one living entity. */
interface EntityEquipment
{
    public function getItem(EquipmentSlot $slot): ?ItemStack;

    public function setItem(EquipmentSlot $slot, ?ItemStack $item): void;

    public function getDropChance(EquipmentSlot $slot): float;

    public function setDropChance(EquipmentSlot $slot, float $chance): void;

    /** @return array<string, ItemStack|null> keyed by EquipmentSlot::value */
    public function getContents(): array;

    public function clear(EquipmentSlot $slot): void;

    public function clearAll(): void;
}
