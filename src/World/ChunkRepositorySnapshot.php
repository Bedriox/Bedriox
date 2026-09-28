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

namespace Bedriox\Server\World;

final readonly class ChunkRepositorySnapshot
{
    public function __construct(
        public int $capacity,
        public int $loaded,
        public int $retainedChunks,
        public int $retentionReferences,
        public int $dirty,
        public int $hits,
        public int $misses,
        public int $evictions,
    ) {}

    public function hitRatio(): ?float
    {
        $lookups = $this->hits + $this->misses;

        return $lookups === 0 ? null : $this->hits / $lookups;
    }
}
