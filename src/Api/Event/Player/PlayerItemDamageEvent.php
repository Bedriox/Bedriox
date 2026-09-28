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

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemDamageCause;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable, adjustable durability loss emitted before authoritative commit. */
final class PlayerItemDamageEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ItemStack $item,
        public readonly ItemDamageCause $cause,
        public readonly EquipmentSlot $slot,
        private int $damage,
    ) {
        self::validateDamage($damage);
    }

    public function damage(): int
    {
        return $this->damage;
    }

    public function setDamage(int $damage): void
    {
        $this->assertMutable();
        self::validateDamage($damage);
        $this->damage = $damage;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->damage];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid player item damage event state.');
        }
        parent::replaceState($state[0]);
        self::validateDamage($state[1]);
        $this->damage = $state[1];
    }

    private static function validateDamage(int $damage): void
    {
        if ($damage < 0 || $damage > 65_535) {
            throw new InvalidArgumentException('Item durability damage must be between 0 and 65535.');
        }
    }
}
