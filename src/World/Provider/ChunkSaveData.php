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

namespace Bedriox\Server\World\Provider;

use Bedriox\Server\World\Chunk;

/** Immutable save snapshot; providers must commit all represented components atomically. */
final readonly class ChunkSaveData
{
    public int $revision;

    public int $dirtyFlags;

    public function __construct(public Chunk $chunk)
    {
        $this->revision = $chunk->revision;
        $this->dirtyFlags = $chunk->dirtyFlags;
    }
}
