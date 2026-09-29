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

final class CampfireCookEvent extends CancellableEvent
{
    public readonly int $slot;
    private ItemStack $result;
    public function __construct(public readonly BlockPosition $position, int $slot, public readonly ItemStack $input, ItemStack $result, public readonly bool $soulCampfire = false)
    {
        $this->slot = ProcessingEventValues::slot($slot, 3);
        $this->result = $result;
    }
    public function result(): ItemStack
    {
        return $this->result;
    }
    public function setResult(ItemStack $result): void
    {
        $this->assertMutable();
        if ($result->count > $this->result->count) {
            throw new InvalidArgumentException('Campfire result cannot exceed its authoritative item-count budget.');
        }$this->result = $result;
    }
    protected function state(): mixed
    {
        return[parent::state(),$this->result];
    }
    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof ItemStack) {
            throw new InvalidArgumentException('Invalid campfire-cook event state.');
        }parent::replaceState($state[0]);
        $this->result = $state[1];
    }
}
