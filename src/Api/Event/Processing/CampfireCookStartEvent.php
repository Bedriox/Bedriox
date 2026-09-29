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
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

final class CampfireCookStartEvent extends CancellableEvent
{
    public readonly int $slot;
    public function __construct(public readonly BlockPosition $position, int $slot, public readonly ItemStack $input, public readonly ItemStack $result, private int $cookTicks, public readonly bool $soulCampfire = false)
    {
        $this->slot = ProcessingEventValues::slot($slot, 3);
        $this->cookTicks = ProcessingEventValues::ticks($cookTicks);
    }
    public function cookTicks(): int
    {
        return $this->cookTicks;
    }
    public function setCookTicks(int $cookTicks): void
    {
        $this->assertMutable();
        $this->cookTicks = ProcessingEventValues::ticks($cookTicks);
    }
    protected function state(): mixed
    {
        return [parent::state(),$this->cookTicks];
    }
    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid campfire-start event state.');
        } parent::replaceState($state[0]);
        $this->cookTicks = ProcessingEventValues::ticks($state[1]);
    }
}
