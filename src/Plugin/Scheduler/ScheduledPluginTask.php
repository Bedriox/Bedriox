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

namespace Bedriox\Server\Plugin\Scheduler;

use Closure;

/** @internal */
final class ScheduledPluginTask
{
    /** @param Closure(): void $callback */
    public function __construct(
        public readonly int $id,
        public readonly int $sequence,
        public readonly string $owner,
        public readonly Closure $callback,
        public readonly int $periodTicks,
        public readonly OwnedTaskHandle $handle,
        public int $targetTick,
    ) {}
}
