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

use Bedriox\Server\World\BlockEntity\BlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

final readonly class FurnaceBlockEntity extends BlockEntity
{
    public const int SLOT_INPUT = 0;
    public const int SLOT_FUEL = 1;
    public const int SLOT_RESULT = 2;
    public const int SLOT_COUNT = 3;
    public const int MAXIMUM_PROGRESS_TICKS = 20_000;
    public const int MAXIMUM_STORED_EXPERIENCE_MILLI = 2_147_483_647;

    public function __construct(
        public FurnaceType $furnaceType,
        BlockPosition $position,
        public ContainerInventory $inventory,
        public int $burnTime = 0,
        public int $burnDuration = 0,
        public int $cookTime = 0,
        public int $cookDuration = 200,
        public int $storedExperienceMilli = 0,
        public ?string $customName = null,
        int $revision = 0,
    ) {
        parent::__construct($furnaceType->blockEntityType(), $position, $revision);
        if ($inventory->size !== self::SLOT_COUNT
            || $burnTime < 0 || $burnTime > self::MAXIMUM_PROGRESS_TICKS
            || $burnDuration < 0 || $burnDuration > self::MAXIMUM_PROGRESS_TICKS
            || $burnTime > $burnDuration || $cookTime < 0 || $cookTime > $cookDuration
            || $cookDuration < 1 || $cookDuration > self::MAXIMUM_PROGRESS_TICKS
            || $storedExperienceMilli < 0 || $storedExperienceMilli > self::MAXIMUM_STORED_EXPERIENCE_MILLI) {
            throw new InvalidArgumentException('Furnace state is outside its supported bounds.');
        }
    }

    public static function empty(FurnaceType $type, BlockPosition $position): self
    {
        return new self($type, $position, ContainerInventory::empty(self::SLOT_COUNT), cookDuration: $type->cookTimeTicks());
    }

    public function withState(ContainerInventory $inventory, int $burnTime, int $burnDuration, int $cookTime, int $storedExperienceMilli): self
    {
        if ($inventory === $this->inventory && $burnTime === $this->burnTime && $burnDuration === $this->burnDuration
            && $cookTime === $this->cookTime && $storedExperienceMilli === $this->storedExperienceMilli) {
            return $this;
        }
        return new self(
            $this->furnaceType,
            $this->position,
            $inventory,
            $burnTime,
            $burnDuration,
            $cookTime,
            $this->cookDuration,
            $storedExperienceMilli,
            $this->customName,
            $this->revision + 1,
        );
    }

    public function active(): bool
    {
        return $this->burnTime > 0 || $this->cookTime > 0 || $this->inventory->stackAt(self::SLOT_INPUT) !== null;
    }
}
