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

namespace Bedriox\Server\World\Generation;

/** Immutable climate sample shared by terrain, biome, surface, and feature stages. */
final readonly class OverworldClimate
{
    public function __construct(
        public int $continentalness,
        public int $erosion,
        public int $temperature,
        public int $humidity,
        public int $ridge,
        public int $uplift,
        public int $river,
        public int $detail,
    ) {}
}
