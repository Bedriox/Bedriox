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

namespace Bedriox\Api\Scheduler;

interface PluginScheduler
{
    /** @param callable(): void $callback */
    public function nextTick(callable $callback): TaskHandle;

    /** @param callable(): void $callback */
    public function delayed(int $delayTicks, callable $callback): TaskHandle;

    /** @param callable(): void $callback */
    public function repeating(int $periodTicks, callable $callback): TaskHandle;

    /** @param callable(): void $callback */
    public function delayedRepeating(int $delayTicks, int $periodTicks, callable $callback): TaskHandle;

    public function async(AsyncTask $task, AsyncTaskValue $input): TaskHandle;
}
