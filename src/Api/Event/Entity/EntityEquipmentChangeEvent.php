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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** Cancellable, adjustable entity-equipment transition before authoritative commit. */
final class EntityEquipmentChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly LivingEntity $entity,
        public readonly EquipmentSlot $slot,
        public readonly ?ItemStack $previous,
        private ?ItemStack $item,
        public readonly float $previousDropChance = 0.0,
        private float $dropChance = 0.0,
    ) {
        self::validateDropChance($previousDropChance);
        self::validateDropChance($dropChance);
    }

    public function item(): ?ItemStack
    {
        return $this->item;
    }

    public function setItem(?ItemStack $item): void
    {
        $this->assertMutable();
        $this->item = $item;
    }

    public function dropChance(): float
    {
        return $this->dropChance;
    }

    public function setDropChance(float $dropChance): void
    {
        $this->assertMutable();
        self::validateDropChance($dropChance);
        $this->dropChance = $dropChance;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->item, $this->dropChance];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 3 || !is_bool($state[0])
            || ($state[1] !== null && !$state[1] instanceof ItemStack) || !is_float($state[2])) {
            throw new InvalidArgumentException('Invalid entity equipment event state.');
        }
        parent::replaceState($state[0]);
        $this->item = $state[1];
        $this->dropChance = $state[2];
    }

    private static function validateDropChance(float $dropChance): void
    {
        if (!is_finite($dropChance) || $dropChance < 0.0 || $dropChance > 1.0) {
            throw new InvalidArgumentException('Entity equipment drop chance must be between 0 and 1.');
        }
    }
}
