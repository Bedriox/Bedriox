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

namespace Bedriox\Server\World\Environment\Fluid;

use Bedriox\Data\CanonicalBlockState;

final readonly class FluidCell
{
    public ?FluidState $fluid;

    public function __construct(
        public CanonicalBlockState $block,
        public bool $replaceable,
        public bool $solidTop,
    ) {
        $this->fluid = FluidState::fromCanonical($block);
    }

    public static function air(): self
    {
        return new self(CanonicalBlockState::from('minecraft:air'), true, false);
    }
}
