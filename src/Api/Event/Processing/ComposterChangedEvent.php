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

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Processing\ComposterChangeCause;
use Bedriox\Api\World\BlockPosition;

final class ComposterChangedEvent extends Event implements PostEvent
{
    public readonly int $oldLevel;
    public readonly int $newLevel;
    public function __construct(public readonly ?Player $player, public readonly BlockPosition $position, int $oldLevel, int $newLevel, public readonly ComposterChangeCause $cause, public readonly ?ItemStack $item = null)
    {
        $this->oldLevel = ProcessingEventValues::level($oldLevel, 8);
        $this->newLevel = ProcessingEventValues::level($newLevel, 8);
    }
}
