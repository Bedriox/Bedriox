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

namespace Bedriox\Api;

/** A bounded, read-only snapshot of server health intended for plugin displays and diagnostics. */
final readonly class ServerPerformance
{
    public function __construct(
        public float $currentTicksPerSecond,
        public float $averageTicksPerSecond,
        public int $memoryBytes,
        public int $peakMemoryBytes,
        public int $entityCount,
        public int $onlinePlayers,
        public int $maximumPlayers,
    ) {}
}
