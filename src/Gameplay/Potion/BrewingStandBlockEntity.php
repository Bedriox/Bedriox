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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Server\World\BlockEntity\BlockEntity;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Durable authoritative inventory and progress state for one brewing stand. */
final readonly class BrewingStandBlockEntity extends BlockEntity
{
    public const int SLOT_INGREDIENT = 0;
    public const int SLOT_BOTTLE_LEFT = 1;
    public const int SLOT_BOTTLE_MIDDLE = 2;
    public const int SLOT_BOTTLE_RIGHT = 3;
    public const int SLOT_FUEL = 4;
    public const int SLOT_COUNT = 5;
    public const int BREW_TIME_TICKS = 400;
    public const int BLAZE_POWDER_FUEL_USES = 20;
    public const int MAXIMUM_FUEL_USES = 32_767;

    public function __construct(
        BlockPosition $position,
        public ContainerInventory $inventory,
        public int $brewTime = 0,
        public int $fuelAmount = 0,
        public int $fuelTotal = 0,
        public ?string $customName = null,
        int $revision = 0,
    ) {
        parent::__construct(BlockEntityType::BrewingStand, $position, $revision);
        if ($inventory->size !== self::SLOT_COUNT) {
            throw new InvalidArgumentException('Brewing stands must own exactly five slots.');
        }
        if ($brewTime < 0 || $brewTime > self::BREW_TIME_TICKS
            || $fuelAmount < 0 || $fuelAmount > self::MAXIMUM_FUEL_USES
            || $fuelTotal < 0 || $fuelTotal > self::MAXIMUM_FUEL_USES
            || $fuelAmount > $fuelTotal) {
            throw new InvalidArgumentException('Brewing stand progress is outside its supported range.');
        }
        if ($customName !== null && ($customName === '' || strlen($customName) > 256
            || preg_match('//u', $customName) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $customName) === 1)) {
            throw new InvalidArgumentException('Brewing stand custom name is invalid or exceeds its limit.');
        }
    }

    public static function empty(BlockPosition $position): self
    {
        return new self($position, ContainerInventory::empty(self::SLOT_COUNT));
    }

    public function withState(
        ContainerInventory $inventory,
        int $brewTime,
        int $fuelAmount,
        int $fuelTotal,
    ): self {
        if ($inventory === $this->inventory && $brewTime === $this->brewTime
            && $fuelAmount === $this->fuelAmount && $fuelTotal === $this->fuelTotal) {
            return $this;
        }
        if ($this->revision === PHP_INT_MAX) {
            throw new \OverflowException('Brewing stand revision space is exhausted.');
        }

        return new self(
            $this->position,
            $inventory,
            $brewTime,
            $fuelAmount,
            $fuelTotal,
            $this->customName,
            $this->revision + 1,
        );
    }
}
