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

namespace Bedriox\Server\World\Generation\Vegetation;

final readonly class VegetationTreePlan
{
    public function __construct(
        public int $x,
        public int $groundY,
        public int $z,
        public string $biome,
        public string $profile,
        public TreeArchetype $archetype,
        public int $height,
        public int $rotation,
        public int $shapeSeed,
        public int $priority,
        public int $minimumSpacing,
    ) {}
}
