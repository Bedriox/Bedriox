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

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Closure;
use InvalidArgumentException;

/** @internal Transactional plugin view over authoritative living-entity equipment. */
final readonly class BufferedEntityEquipment implements EntityEquipment
{
    /** @var Closure(callable(): void): void */
    private Closure $stage;

    /** @param callable(callable(): void): void $stage */
    public function __construct(
        private EntityEquipment $equipment,
        callable $stage,
    ) {
        $this->stage = Closure::fromCallable($stage);
    }

    public function getItem(EquipmentSlot $slot): ?ItemStack
    {
        return $this->equipment->getItem($slot);
    }

    public function setItem(EquipmentSlot $slot, ?ItemStack $item): void
    {
        ($this->stage)(fn() => $this->equipment->setItem($slot, $item));
    }

    public function getMainHand(): ?ItemStack
    {
        return $this->equipment->getMainHand();
    }

    public function setMainHand(?ItemStack $item): void
    {
        $this->setItem(EquipmentSlot::MAIN_HAND, $item);
    }

    public function getOffHand(): ?ItemStack
    {
        return $this->equipment->getOffHand();
    }

    public function setOffHand(?ItemStack $item): void
    {
        $this->setItem(EquipmentSlot::OFF_HAND, $item);
    }

    public function getHelmet(): ?ItemStack
    {
        return $this->equipment->getHelmet();
    }

    public function setHelmet(?ItemStack $item): void
    {
        $this->setItem(EquipmentSlot::HEAD, $item);
    }

    public function getChestplate(): ?ItemStack
    {
        return $this->equipment->getChestplate();
    }

    public function setChestplate(?ItemStack $item): void
    {
        $this->setItem(EquipmentSlot::CHEST, $item);
    }

    public function getLeggings(): ?ItemStack
    {
        return $this->equipment->getLeggings();
    }

    public function setLeggings(?ItemStack $item): void
    {
        $this->setItem(EquipmentSlot::LEGS, $item);
    }

    public function getBoots(): ?ItemStack
    {
        return $this->equipment->getBoots();
    }

    public function setBoots(?ItemStack $item): void
    {
        $this->setItem(EquipmentSlot::FEET, $item);
    }

    public function getDropChance(EquipmentSlot $slot): float
    {
        return $this->equipment->getDropChance($slot);
    }

    public function setDropChance(EquipmentSlot $slot, float $chance): void
    {
        if (!is_finite($chance) || $chance < 0.0 || $chance > 1.0) {
            throw new InvalidArgumentException('Entity equipment drop chance must be finite and between zero and one.');
        }
        ($this->stage)(fn() => $this->equipment->setDropChance($slot, $chance));
    }

    public function getContents(): array
    {
        return $this->equipment->getContents();
    }

    public function getArmorContents(): array
    {
        return $this->equipment->getArmorContents();
    }

    public function clear(EquipmentSlot $slot): void
    {
        ($this->stage)(fn() => $this->equipment->clear($slot));
    }

    public function clearArmor(): void
    {
        ($this->stage)(fn() => $this->equipment->clearArmor());
    }

    public function clearAll(): void
    {
        ($this->stage)(fn() => $this->equipment->clearAll());
    }
}
