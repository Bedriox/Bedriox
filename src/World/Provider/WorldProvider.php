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

use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\Exception\CorruptChunkException;
use Bedriox\Server\World\Provider\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Provider\Exception\UnsupportedWorldFormatException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;

/** Storage-neutral read lifecycle for one world. */
interface WorldProvider
{
    /** @throws WorldProviderClosedException|WorldStorageException|UnsupportedWorldFormatException|CorruptWorldDataException */
    public function worldData(): WorldData;

    /**
     * Returns null only when the chunk does not exist.
     *
     * @throws WorldProviderClosedException|WorldStorageException|UnsupportedWorldFormatException|CorruptChunkException
     */
    public function loadChunk(
        ChunkPosition $position,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): ?LoadedChunkData;

    /**
     * Releases provider resources. Repeated calls must be harmless; every other operation after close must fail.
     */
    public function close(): void;
}
