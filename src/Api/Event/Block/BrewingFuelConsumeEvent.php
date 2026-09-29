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

namespace Bedriox\Api\Event\Block;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

final class BrewingFuelConsumeEvent extends CancellableEvent
{
    private int $fuelUses;

    public function __construct(public readonly BlockPosition $position, public readonly ItemStack $fuel, int $fuelUses)
    {
        $this->setFuelUses($fuelUses);
    }

    public function fuelUses(): int
    {
        return $this->fuelUses;
    }

    public function setFuelUses(int $fuelUses): void
    {
        $this->assertMutable();
        if ($fuelUses < 1 || $fuelUses > 32_767) {
            throw new InvalidArgumentException('Brewing fuel uses must be between 1 and 32767.');
        }
        $this->fuelUses = $fuelUses;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->fuelUses];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid brewing fuel event state.');
        }
        parent::replaceState($state[0]);
        $this->fuelUses = $state[1];
    }
}
