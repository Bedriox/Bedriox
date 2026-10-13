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

namespace Bedriox\Server\World\Generation\Structure\Overworld\Family;

use Bedriox\Server\World\Generation\Structure\StructureRotation;

/** One original modular piece positioned in world space. */
final readonly class PlannedStructurePiece
{
    public function __construct(
        public string $kind,
        public int $x,
        public int $y,
        public int $z,
        public int $width,
        public int $depth,
        public StructureRotation $rotation = StructureRotation::None,
    ) {}
}
