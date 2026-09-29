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
use Bedriox\Api\Processing\StationProcessCause;
use Bedriox\Api\World\BlockPosition;

final class GrindstoneProcessEvent extends CancellableEvent
{
    public readonly int $experience;
    public function __construct(public readonly Player $player, public readonly BlockPosition $position, public readonly ItemStack $top, public readonly ?ItemStack $bottom, public readonly ItemStack $result, int $experience, public readonly StationProcessCause $cause = StationProcessCause::PLAYER)
    {
        $this->experience = ProcessingEventValues::experience($experience);
    }
}
