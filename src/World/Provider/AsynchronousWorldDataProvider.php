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

use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;

/** Nonblocking persistence boundary for mutable world metadata such as time and spawn. */
interface AsynchronousWorldDataProvider extends WritableWorldProvider
{
    public function enqueueWorldDataSave(WorldData $worldData): PersistenceEnqueueResult;

    /** @return list<PersistenceWriteCompletion> */
    public function pollWorldDataSaves(int $maximumCompletions = 16): array;
}
