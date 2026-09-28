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

use Bedriox\Server\Persistence\PersistenceEnqueueResult;

/** Non-blocking entity ownership persistence used by the simulation thread. */
interface AsynchronousEntityPersistenceStore extends EntityPersistenceStore
{
    /** Queues one immutable owner-chunk snapshot without waiting for storage I/O. */
    public function enqueueEntityChunkSave(EntityChunkSnapshot $snapshot): PersistenceEnqueueResult;

    /** @return list<EntityChunkSaveCompletion> */
    public function pollEntityChunkSaves(int $maximumCompletions = 256): array;

    /** @return list<EntityChunkSaveCompletion> */
    public function drainEntityChunkSaves(int $timeoutMilliseconds): array;

    /** Returns false when the bounded submission queue cannot accept the transfer. */
    public function enqueueEntityOwnershipTransfer(EntityOwnershipTransfer $transfer): bool;

    /**
     * @return list<EntityOwnershipTransferCompletion>
     */
    public function pollEntityOwnershipTransfers(int $maximumCompletions = 256): array;

    /**
     * @return list<EntityOwnershipTransferCompletion>
     */
    public function drainEntityOwnershipTransfers(int $timeoutMilliseconds): array;
}
