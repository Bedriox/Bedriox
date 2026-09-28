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

final class UnavailablePluginScheduler implements PluginScheduler
{
    public function nextTick(callable $callback): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }

    public function delayed(int $delayTicks, callable $callback): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }

    public function repeating(int $periodTicks, callable $callback): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }

    public function delayedRepeating(int $delayTicks, int $periodTicks, callable $callback): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }

    public function async(AsyncTask $task, AsyncTaskValue $input): TaskHandle
    {
        throw new \LogicException('The plugin scheduler is not available in this context.');
    }
}
