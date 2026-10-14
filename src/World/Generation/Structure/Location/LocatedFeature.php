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

/** Canonical result shared by biome and structure locate queries. */
final readonly class LocatedFeature
{
    public function __construct(
        public string $identifier,
        public int $x,
        public int $y,
        public int $z,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
            || abs($x) > 30_000_000 || $y < -64 || $y > 319 || abs($z) > 30_000_000) {
            throw new \InvalidArgumentException('Located feature contains invalid bounded values.');
        }
    }
}
