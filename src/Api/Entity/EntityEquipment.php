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

    public function getMainHand(): ?ItemStack;

    public function setMainHand(?ItemStack $item): void;

    public function getOffHand(): ?ItemStack;

    public function setOffHand(?ItemStack $item): void;

    public function getHelmet(): ?ItemStack;

    public function setHelmet(?ItemStack $item): void;

    public function getChestplate(): ?ItemStack;

    public function setChestplate(?ItemStack $item): void;

    public function getLeggings(): ?ItemStack;

    public function setLeggings(?ItemStack $item): void;

    public function getBoots(): ?ItemStack;

    public function setBoots(?ItemStack $item): void;

    public function getDropChance(EquipmentSlot $slot): float;

    public function setDropChance(EquipmentSlot $slot, float $chance): void;

    /** @return array<string, ItemStack|null> keyed by EquipmentSlot::value */
    public function getContents(): array;

    /** @return array{head: ItemStack|null, chest: ItemStack|null, legs: ItemStack|null, feet: ItemStack|null} */
    public function getArmorContents(): array;

    public function clear(EquipmentSlot $slot): void;

    public function clearArmor(): void;

    public function clearAll(): void;
}
