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
use Bedriox\Server\World\Provider\Exception\UnsupportedWorldFormatException;
use Bedriox\Server\World\Provider\Exception\WorldProviderClosedException;
use Bedriox\Server\World\Provider\Exception\WorldStorageException;

interface WritableWorldProvider extends WorldProvider
{
    /** @throws WorldProviderClosedException|WorldStorageException|UnsupportedWorldFormatException */
    public function saveWorldData(WorldData $worldData): void;

    /** @throws WorldProviderClosedException|WorldStorageException|UnsupportedWorldFormatException */
    public function saveChunk(
        ChunkSaveData $chunkData,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): void;
}
