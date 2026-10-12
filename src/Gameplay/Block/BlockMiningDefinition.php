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

namespace Bedriox\Server\Gameplay\Block;

use Bedriox\Server\Gameplay\Item\ToolTier;
use Bedriox\Server\Gameplay\Item\ToolType;
use InvalidArgumentException;

/** Tool and hardness requirements for a registered vanilla block family member. */
final readonly class BlockMiningDefinition
{
    public function __construct(
        public float $hardness,
        public ?ToolType $preferredTool,
        public ?ToolTier $requiredTier,
    ) {
        if (!is_finite($hardness) || $hardness < 0.0 || ($requiredTier !== null && $preferredTool === null)) {
            throw new InvalidArgumentException('Block mining definition is invalid.');
        }
    }
}
