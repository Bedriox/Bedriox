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
use Bedriox\Api\Player\Player;
use Bedriox\Api\Processing\ComposterChangeCause;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

final class ComposterChangeEvent extends CancellableEvent
{
    public readonly int $oldLevel;
    private int $newLevel;
    public function __construct(public readonly ?Player $player, public readonly BlockPosition $position, int $oldLevel, int $newLevel, public readonly ComposterChangeCause $cause, public readonly ?ItemStack $item = null)
    {
        $this->oldLevel = ProcessingEventValues::level($oldLevel, 8);
        $this->newLevel = ProcessingEventValues::level($newLevel, 8);
    }public function newLevel(): int
    {
        return $this->newLevel;
    }public function setNewLevel(int $level): void
    {
        $this->assertMutable();
        $this->newLevel = ProcessingEventValues::level($level, 8);
    }protected function state(): mixed
    {
        return[parent::state(),$this->newLevel];
    }protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid composter event state.');
        }parent::replaceState($state[0]);
        $this->newLevel = ProcessingEventValues::level($state[1], 8);
    }
}
