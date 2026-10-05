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
use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\World\ChunkLoadCompletion;
use Bedriox\Server\World\ChunkPosition;

/** Nonblocking streaming and autosave boundary for a provider owned outside the simulation process. */
interface AsynchronousWorldProvider extends WritableWorldProvider
{
    public function requestChunkLoad(
        ChunkPosition $position,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): bool;

    /** @return list<ChunkLoadCompletion> */
    public function pollChunkLoads(
        int $maximumCompletions = 256,
        ?WorldDimension $dimension = null,
    ): array;

    public function enqueueChunkSave(
        ChunkSaveData $chunkData,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): PersistenceEnqueueResult;

    /** @return list<PersistenceWriteCompletion> */
    public function pollChunkSaves(
        int $maximumCompletions = 256,
        ?WorldDimension $dimension = null,
    ): array;

    /** @return list<PersistenceWriteCompletion> */
    public function drainChunkSaves(
        int $timeoutMilliseconds,
        ?WorldDimension $dimension = null,
    ): array;
}
