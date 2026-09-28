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

namespace Bedriox\Server\Player\Persistence;

use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Player\PlayerBootstrap;

/** Nonblocking routine-save boundary for a player store owned outside the simulation process. */
interface AsynchronousPlayerDataStore extends PlayerDataStore
{
    public function enqueueSave(PlayerBootstrap $player, int $revision): PersistenceEnqueueResult;

    /** @return list<PersistenceWriteCompletion> */
    public function pollSaves(int $maximumCompletions = 256): array;

    /** @return list<PersistenceWriteCompletion> */
    public function drainSaves(int $timeoutMilliseconds): array;

    public function close(): void;
}
