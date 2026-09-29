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
use Bedriox\Api\Processing\CauldronChangeCause;
use Bedriox\Api\Processing\CauldronContentType;
use Bedriox\Api\World\BlockPosition;

final class CauldronChangedEvent extends Event implements PostEvent
{
    public readonly int $oldLevel;
    public readonly CauldronContentType $newContent;
    public readonly int $newLevel;
    public function __construct(public readonly ?Player $player, public readonly BlockPosition $position, public readonly CauldronContentType $oldContent, int $oldLevel, CauldronContentType $newContent, int $newLevel, public readonly CauldronChangeCause $cause, public readonly ?ItemStack $item = null)
    {
        $pre = new CauldronChangeEvent($player, $position, $oldContent, $oldLevel, $newContent, $newLevel, $cause, $item);
        $this->oldLevel = $pre->oldLevel;
        $this->newContent = $pre->newContent();
        $this->newLevel = $pre->newLevel();
    }
}
