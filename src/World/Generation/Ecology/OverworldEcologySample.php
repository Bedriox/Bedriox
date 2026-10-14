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

namespace Bedriox\Server\World\Generation\Ecology;

/** Continuous ecological suitability and its human-readable forest zone. */
final readonly class OverworldEcologySample
{
    public function __construct(
        public OverworldEcologyZone $zone,
        public int $densityPermille,
        public int $habitatMask,
    ) {}

    public function accepts(int $deterministicRoll): bool
    {
        return $deterministicRoll >= 0
            && $deterministicRoll < 1_000
            && $deterministicRoll < $this->densityPermille;
    }
}
