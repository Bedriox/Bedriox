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
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable, adjustable repair proposed before Mending consumes collected experience. */
final class PlayerItemMendEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ItemStack $item,
        public readonly EquipmentSlot $slot,
        private int $repairAmount,
        private int $experienceCost,
    ) {
        self::validate($repairAmount, $experienceCost);
    }

    public function repairAmount(): int
    {
        return $this->repairAmount;
    }

    public function experienceCost(): int
    {
        return $this->experienceCost;
    }

    public function setRepair(int $repairAmount, int $experienceCost): void
    {
        $this->assertMutable();
        self::validate($repairAmount, $experienceCost);
        $this->repairAmount = $repairAmount;
        $this->experienceCost = $experienceCost;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->repairAmount, $this->experienceCost];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 3
            || !is_bool($state[0]) || !is_int($state[1]) || !is_int($state[2])) {
            throw new InvalidArgumentException('Invalid player item mend event state.');
        }
        parent::replaceState($state[0]);
        self::validate($state[1], $state[2]);
        $this->repairAmount = $state[1];
        $this->experienceCost = $state[2];
    }

    private static function validate(int $repairAmount, int $experienceCost): void
    {
        if ($repairAmount < 1 || $repairAmount > 65_535 || $experienceCost < 1 || $experienceCost > 32_767) {
            throw new InvalidArgumentException('Mending repair and experience cost must be positive and bounded.');
        }
    }
}
