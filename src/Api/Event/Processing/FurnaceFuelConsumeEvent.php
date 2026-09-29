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

namespace Bedriox\Api\Event\Processing;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Processing\FurnaceFuelCause;
use Bedriox\Api\Processing\FurnaceType;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

final class FurnaceFuelConsumeEvent extends CancellableEvent
{
    public function __construct(public readonly BlockPosition $position, public readonly FurnaceType $furnaceType, public readonly ItemStack $fuel, public readonly FurnaceFuelCause $cause, private int $burnTicks)
    {
        $this->burnTicks = ProcessingEventValues::ticks($burnTicks);
    }
    public function burnTicks(): int
    {
        return $this->burnTicks;
    }
    public function setBurnTicks(int $burnTicks): void
    {
        $this->assertMutable();
        $this->burnTicks = ProcessingEventValues::ticks($burnTicks);
    }
    protected function state(): mixed
    {
        return [parent::state(), $this->burnTicks];
    }
    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid furnace fuel event state.');
        } parent::replaceState($state[0]);
        $this->burnTicks = ProcessingEventValues::ticks($state[1]);
    }
}
