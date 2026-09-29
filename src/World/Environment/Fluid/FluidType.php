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

enum FluidType: string
{
    case WATER = 'minecraft:water';
    case LAVA = 'minecraft:lava';

    public function tickDelay(): int
    {
        return $this === self::WATER ? 5 : 30;
    }

    public function horizontalDecay(): int
    {
        return $this === self::WATER ? 1 : 2;
    }

    public function slopeDistance(): int
    {
        return $this === self::WATER ? 4 : 2;
    }

    public function formsSources(): bool
    {
        return $this === self::WATER;
    }
}
