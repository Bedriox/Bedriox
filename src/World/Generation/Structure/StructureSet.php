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

namespace Bedriox\Server\World\Generation\Structure;

use InvalidArgumentException;

final readonly class StructureSet
{
    public function __construct(
        public string $id,
        public int $spacing,
        public int $separation,
        public int $salt,
        public StructurePlanner $planner,
    ) {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Structure-set ID must be a bounded lowercase identifier.');
        }
        if ($spacing < 1 || $spacing > 4_096 || $separation < 0 || $separation >= $spacing) {
            throw new InvalidArgumentException('Structure-set spacing must exceed its bounded non-negative separation.');
        }
    }
}
