<?php

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
