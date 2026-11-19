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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\DimensionType;

/** Bounded operational gauges for one loaded dimension runtime. */
final readonly class WorldRuntimeSnapshot
{
    public function __construct(
        public string $worldId,
        public DimensionType $dimension,
        public int $players,
        public int $loadedChunks,
        public int $dirtyChunks,
        public int $generatingChunks,
        public int $preparedCacheBytes,
        public int $preparedCachePendingBytes,
        public int $idleTicks,
        public bool $cacheHibernating,
        public int $hibernationBytesReleased,
        public int $storageProcessMemoryBytes = 0,
    ) {}
}
