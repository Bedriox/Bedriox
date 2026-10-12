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

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockFace;
use InvalidArgumentException;

final class PlayerInteractedBlockEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Block $block,
        public readonly BlockInteractionType $interaction,
        public readonly BlockFace $face,
        public readonly float $hitX,
        public readonly float $hitY,
        public readonly float $hitZ,
        public readonly ?ItemStack $heldItem = null,
    ) {
        foreach ([$hitX, $hitY, $hitZ] as $coordinate) {
            if (!is_finite($coordinate) || $coordinate < 0.0 || $coordinate > 1.0) {
                throw new InvalidArgumentException('Block interaction hit coordinates must be finite and normalized.');
            }
        }
    }
}
