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

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\World\ChunkPosition;

/** Durable chunk ownership boundary for non-player entities. */
interface EntityPersistenceStore
{
    /** @throws CorruptEntityPersistenceException */
    public function loadEntityChunk(
        ChunkPosition $position,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): ?EntityChunkSnapshot;

    /** @throws EntityPersistenceConflictException */
    public function saveEntityChunk(
        EntityChunkSnapshot $snapshot,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): void;

    /** @throws CorruptEntityPersistenceException|EntityPersistenceConflictException */
    public function transferEntityOwnership(
        EntityOwnershipTransfer $transfer,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): EntityOwnershipTransferResult;
}
