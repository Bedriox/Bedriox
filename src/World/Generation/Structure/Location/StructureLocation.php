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

namespace Bedriox\Server\World\Generation\Structure\Location;

use Bedriox\Api\World\DimensionType;

/** A confirmed generated structure start and a safe inspection coordinate. */
final readonly class StructureLocation
{
    public function __construct(
        public string $identifier,
        public string $variant,
        public DimensionType $dimension,
        public int $x,
        public int $y,
        public int $z,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $identifier) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $variant) !== 1
            || abs($x) > 30_000_000 || $y < -64 || $y > 319 || abs($z) > 30_000_000) {
            throw new \InvalidArgumentException('Structure location contains invalid bounded values.');
        }
    }

    public function teleportCommand(): string
    {
        return sprintf('/tp @s %d %d %d', $this->x, $this->y, $this->z);
    }
}
