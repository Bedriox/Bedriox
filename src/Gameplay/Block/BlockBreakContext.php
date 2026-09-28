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

use InvalidArgumentException;

/** Environment modifiers applied to one authoritative break-speed calculation. */
final readonly class BlockBreakContext
{
    public function __construct(
        public bool $airborne = false,
        public bool $underwater = false,
        public bool $aquaAffinity = false,
        public int $hasteLevel = 0,
        public int $miningFatigueLevel = 0,
    ) {
        if ($hasteLevel < 0 || $hasteLevel > 255 || $miningFatigueLevel < 0 || $miningFatigueLevel > 255) {
            throw new InvalidArgumentException('Mining effect levels must be between zero and 255.');
        }
    }
}
