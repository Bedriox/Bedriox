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

namespace Bedriox\Server\Plugin\Scheduler\Worker;

final readonly class PluginAsyncQueueSnapshot
{
    public function __construct(
        public int $queuedTasks,
        public int $runningTasks,
        public int $waitingPlugins,
        public int $oldestWaitMilliseconds,
        public int $rejectedTasks,
        public int $expiredTasks,
        /** @var array<string, int> */
        public array $queuedTasksByPlugin = [],
    ) {}
}
